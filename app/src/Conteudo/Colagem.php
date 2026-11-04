<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

use Pbl\Acesso\Acao;
use Pbl\Acesso\Usuario;
use Pbl\Busca\Texto;
use Pbl\Caminhos;
use Pbl\Servicos;

/**
 * Cola o que está na pilha (fase 5r, ADR 0025) dentro de uma pasta de destino. Três jeitos:
 *
 * - `mover` (itens recortados): troca o pai, com a versão otimista guardada na pilha;
 * - `copia` (itens copiados): objeto novo em rascunho, título "Cópia de …", endereço livre; com `$profunda`, a subárvore inteira
 *   também. As propriedades vão iguais e cada arquivo/imagem ganha uma linha nova em `arquivo` apontando para o mesmo arquivo em
 *   disco (os bytes não são duplicados);
 * - `link` (itens copiados): um objeto `link` (rascunho) apontando para o original.
 *
 * `prever` não grava nada e devolve o que `colar` faria, com o motivo de cada item que será recusado. `colar` trata item por item
 * (cada um numa transação: ou entra por inteiro, ou nada dele) e devolve o resultado de cada um. As regras valem nas duas:
 * destino do mesmo site e fora da lixeira, tipo aceito, Criar no destino, Ver (copiar/link) ou Editar (mover) na origem, nunca
 * para dentro de si mesmo ou de um descendente, até {@see self::LIMITE_TOTAL} objetos criados ou movidos por colagem.
 *
 * @phpstan-type Linha array{id: int, titulo: string, ok: bool, mensagem: string, quantidade: int, novo: int|null}
 */
final class Colagem
{
    public const MOVER = 'mover';
    public const COPIA = 'copia';
    public const LINK = 'link';
    public const TIPOS = [self::MOVER, self::COPIA, self::LINK];
    /** Teto de objetos criados ou movidos por colagem (somando as subárvores copiadas). */
    public const LIMITE_TOTAL = 200;
    /** Acima disto de objetos afetados, a republicação vai para a fila do cron (ADR 0023) em vez de ser feita na hora. */
    public const LIMITE_NA_HORA = 25;
    private const MOTIVO = 'pilha: recortar e colar';
    private const MAX_TITULO = 300;

    public function __construct(private readonly Servicos $servicos)
    {
    }

    /** O jeito de colar que combina com o modo da pilha, ou null se não combina. */
    public static function tipoValido(string $modoDaPilha, string $tipo): bool
    {
        return $modoDaPilha === Pilha::RECORTAR ? $tipo === self::MOVER : in_array($tipo, [self::COPIA, self::LINK], true);
    }

    /**
     * Motivo pelo qual **nada** pode ser colado neste destino (independe dos itens), ou null.
     */
    public function recusaDoDestino(Usuario $usuario, Objeto $destino): ?string
    {
        if ($destino->status === 'apagado') {
            return 'O destino está na lixeira.';
        }
        if (!$this->servicos->permissoes->podeCriarSob($usuario, $destino)) {
            return 'Você não pode criar conteúdo neste destino.';
        }
        if ($this->classe($destino->classeId)?->aceitaFilhos !== true) {
            return 'O tipo deste destino não aceita conteúdo dentro dele.';
        }

        return null;
    }

    /**
     * @return list<Linha>
     */
    public function prever(Usuario $usuario, Objeto $destino, string $tipo, bool $profunda): array
    {
        return $this->percorrer($usuario, $destino, $tipo, $profunda, false)['linhas'];
    }

    /**
     * @return array{linhas: list<Linha>, ok: int, falhas: int, criados: int, republicacao: string}
     *     `republicacao`: `ok`, `falhou` (a mudança está salva, mas alguma página não atualizou) ou `fila` (segue pelo cron)
     */
    public function colar(Usuario $usuario, Objeto $destino, string $tipo, bool $profunda): array
    {
        $r = $this->percorrer($usuario, $destino, $tipo, $profunda, true);
        $ok = count(array_filter($r['linhas'], static fn (array $l): bool => $l['ok']));
        $falhas = count($r['linhas']) - $ok;
        $republicacao = $r['tocados'] === [] ? 'ok' : $this->republicar($destino, $r['tocados'], $r['antigosPais'], $r['afetados']);
        $this->servicos->auditoria->registrar('pilha.colar', 'usuario:' . $usuario->id, $usuario->id, $destino->id, [
            'tipo' => $tipo, 'profunda' => $profunda ? 1 : 0, 'itens' => count($r['linhas']), 'ok' => $ok, 'falhas' => $falhas, 'objetos' => $r['afetados'],
        ]);

        return ['linhas' => $r['linhas'], 'ok' => $ok, 'falhas' => $falhas, 'criados' => $r['afetados'], 'republicacao' => $republicacao];
    }

    /**
     * @return array{linhas: list<Linha>, tocados: list<int>, antigosPais: list<int>, afetados: int}
     */
    private function percorrer(Usuario $usuario, Objeto $destino, string $tipo, bool $profunda, bool $executar): array
    {
        $linhas = [];
        $tocados = [];
        $antigosPais = [];
        $afetados = 0;
        $pilha = $this->servicos->pilha;
        $itens = $pilha->itens($usuario->id);
        $recusaGeral = $this->recusaDoDestino($usuario, $destino);
        $modo = $itens === [] ? null : $itens[0]['modo'];
        if ($modo !== null && !self::tipoValido($modo, $tipo)) {
            $recusaGeral ??= 'Este jeito de colar não vale para o que está na pilha.';
        }
        foreach ($itens as $item) {
            $o = $this->servicos->objetos->porId($item['objeto_id']);
            $titulo = $o === null ? 'Item #' . $item['objeto_id'] : $o->titulo;
            $quantidade = 1;
            $erro = $recusaGeral ?? $this->avaliar($usuario, $destino, $tipo, $profunda, $item, $o, $quantidade);
            if ($erro === null && $afetados + $quantidade > self::LIMITE_TOTAL) {
                $erro = 'Passaria do limite de ' . self::LIMITE_TOTAL . ' objetos por colagem. Cole menos itens de cada vez.';
            }
            $novoId = null;
            if ($erro === null && $o !== null) {
                $afetados += $quantidade;
                if ($executar) {
                    [$erro, $novoId] = $this->executar($usuario, $destino, $tipo, $profunda, $o, $item['versao']);
                    if ($erro !== null) {
                        $afetados -= $quantidade;
                    } elseif ($tipo === self::MOVER) {
                        $tocados[] = $o->id;
                        if ($o->paiId !== null) {
                            $antigosPais[] = $o->paiId;
                        }
                        $pilha->remover($usuario->id, $o->id);
                    }
                }
            }
            $linhas[] = ['id' => $item['objeto_id'], 'titulo' => $titulo, 'ok' => $erro === null, 'mensagem' => $erro ?? $this->descricao($tipo, $profunda, $quantidade, $executar), 'quantidade' => $quantidade, 'novo' => $novoId];
        }
        if ($executar && $afetados > 0) {
            $tocados[] = $destino->id;
        }

        return ['linhas' => $linhas, 'tocados' => array_values(array_unique($tocados)), 'antigosPais' => array_values(array_unique($antigosPais)), 'afetados' => $afetados];
    }

    /**
     * Confere um item sem gravar. Devolve o motivo da recusa (ou null) e, em `$quantidade`, quantos objetos a operação cria/move.
     *
     * @param array{objeto_id: int, site_id: int, modo: string, versao: int} $item
     */
    private function avaliar(Usuario $usuario, Objeto $destino, string $tipo, bool $profunda, array $item, ?Objeto $o, int &$quantidade): ?string
    {
        if ($o === null) {
            return 'Não existe mais.';
        }
        if ($o->siteId !== $destino->siteId) {
            return 'É de outro site.';
        }
        if ($o->status === 'apagado') {
            return 'Está na lixeira.';
        }
        if ($o->paiId === null) {
            return 'A raiz do site não pode ser copiada nem movida.';
        }
        if (str_starts_with($destino->caminho, $o->caminho)) {
            return 'Não dá para colar um conteúdo dentro dele mesmo.';
        }
        $acao = $tipo === self::MOVER ? Acao::Editar : Acao::Ver;
        if (!$this->servicos->permissoes->pode($usuario, $acao, $o)) {
            return $tipo === self::MOVER ? 'Você não pode alterar este conteúdo.' : 'Você não pode ver este conteúdo.';
        }
        $classeDoDestino = $this->classe($destino->classeId);
        $classe = $this->classe($o->classeId);
        $classeLink = $this->servicos->classes->porNome('link');
        if ($classeDoDestino === null || $classe === null) {
            return 'Tipo de conteúdo desconhecido.';
        }
        if ($tipo === self::LINK) {
            if ($classeLink === null || !$classeDoDestino->aceita($classeLink)) {
                return 'O destino não aceita links.';
            }

            return null;
        }
        if (!$classeDoDestino->aceita($classe)) {
            return 'O tipo do destino não aceita conteúdo do tipo ' . $classe->nome . ' dentro dele.';
        }
        if ($tipo === self::MOVER) {
            if ($item['versao'] !== $o->versao) {
                return 'Foi alterado depois de recortado. Recorte de novo.';
            }
            if ($o->paiId === $destino->id) {
                return 'Já está dentro deste destino.';
            }
            $quantidade = $this->servicos->objetos->contarDaSubarvore($o->siteId, $o->caminho);

            return null;
        }
        if ($profunda) {
            // Sem carregar a árvore inteira: acima do limite basta saber que passa (o item será recusado adiante).
            $quantidade = $this->servicos->objetos->contarAtivosDaSubarvore($o->siteId, $o->caminho);
            if ($quantidade <= self::LIMITE_TOTAL) {
                $quantidade = count($this->subarvoreAtiva($o));
            }
        }

        return null;
    }

    /**
     * @return array{0: string|null, 1: int|null} motivo do erro (ou null) e id do objeto novo (ou do movido)
     */
    private function executar(Usuario $usuario, Objeto $destino, string $tipo, bool $profunda, Objeto $o, int $versaoDaPilha): array
    {
        try {
            $novo = $this->servicos->conexao()->transactional(function () use ($usuario, $destino, $tipo, $profunda, $o, $versaoDaPilha): Objeto {
                return match ($tipo) {
                    self::MOVER => $this->servicos->objetos->mover($o->id, $destino->id, self::MOTIVO, $usuario->id, $versaoDaPilha, true),
                    self::LINK => $this->criarLink($usuario, $destino, $o),
                    default => $this->copiar($usuario, $destino, $o, $profunda),
                };
            });
        } catch (ConflitoDeVersao) {
            return ['Foi alterado por outra pessoa. Recorte de novo.', null];
        } catch (ConflitoDeArvore $e) {
            return [rtrim($e->getMessage(), '.') . '.', null];
        } catch (\InvalidArgumentException $e) {
            return [rtrim($e->getMessage(), '.') . '.', null];
        } catch (\Throwable) {
            return ['Não foi possível colar este item. Nada dele foi gravado.', null];
        }

        return [null, $novo->id];
    }

    private function criarLink(Usuario $usuario, Objeto $destino, Objeto $origem): Objeto
    {
        $classe = $this->servicos->classes->porNome('link') ?? throw new \InvalidArgumentException('O tipo "link" não existe');
        $titulo = mb_substr($origem->titulo, 0, self::MAX_TITULO);

        return $this->servicos->objetos->criarDentro(
            $destino->siteId,
            $classe,
            $destino->id,
            $titulo,
            $this->urlLivre($destino->siteId, $titulo) ?? throw new \InvalidArgumentException('Não foi possível achar um endereço livre'),
            ['destino_interno' => $origem->id],
            'privado',
            ['criado_por' => $usuario->id, 'descricao' => $origem->descricao],
        );
    }

    private function copiar(Usuario $usuario, Objeto $destino, Objeto $raiz, bool $profunda): Objeto
    {
        $todos = $profunda ? $this->subarvoreAtiva($raiz) : [$raiz];
        $filhosDe = [];
        foreach ($todos as $x) {
            if ($x->id !== $raiz->id && $x->paiId !== null) {
                $filhosDe[$x->paiId][] = $x;
            }
        }
        $copiar = function (Objeto $x, Objeto $pai, bool $ehRaiz) use ($usuario, &$copiar, $filhosDe): Objeto {
            $novo = $this->copiarUm($usuario, $pai, $x, $ehRaiz);
            foreach ($filhosDe[$x->id] ?? [] as $filho) {
                $copiar($filho, $novo, false);
            }

            return $novo;
        };

        return $copiar($raiz, $destino, true);
    }

    private function copiarUm(Usuario $usuario, Objeto $pai, Objeto $x, bool $ehRaiz): Objeto
    {
        $classe = $this->classe($x->classeId) ?? throw new \InvalidArgumentException('Tipo de conteúdo desconhecido');
        $titulo = $ehRaiz ? mb_substr('Cópia de ' . $x->titulo, 0, self::MAX_TITULO) : $x->titulo;
        $dados = $x->dados;
        $arquivos = [];
        foreach ($classe->propriedades as $p) {
            if (in_array($p['tipo'], ['arquivo', 'imagem'], true) && isset($dados[$p['nome']])) {
                $arquivos[$p['nome']] = (int) $dados[$p['nome']];
                unset($dados[$p['nome']]);
            }
        }
        $aposInserir = $arquivos === [] ? null : function (int $novoId) use ($arquivos, $x, $usuario): array {
            $extra = [];
            foreach ($arquivos as $propriedade => $arquivoId) {
                $a = $this->servicos->arquivos->porId($arquivoId);
                if ($a === null || $a->siteId !== $x->siteId) {
                    continue;
                }
                // Mesma `chave` em disco: só a linha de metadados é nova (quem apaga confere `contarPorChave` antes de remover o arquivo).
                $nova = $this->servicos->arquivos->criar($a->siteId, $novoId, $propriedade, $a->chave, $a->nome, $a->extensao, $a->mime, $a->tamanho, $a->sha256, $a->largura, $a->altura, $a->sanitizada, $usuario->id);
                $extra[$propriedade] = $nova->id;
            }

            return $extra;
        };

        return $this->servicos->objetos->criarDentro(
            $x->siteId,
            $classe,
            $pai->id,
            $titulo,
            $this->urlLivre($x->siteId, $titulo) ?? throw new \InvalidArgumentException('Não foi possível achar um endereço livre'),
            $dados,
            'privado',
            [
                'peso' => $x->peso, 'descricao' => $x->descricao, 'no_menu' => $x->noMenu, 'rotulo_menu' => $x->rotuloMenu, 'pele' => $x->pele, 'molde' => $x->molde,
                'data_publicacao' => $x->dataPublicacao, 'data_validade' => $x->dataValidade, 'criado_por' => $usuario->id,
            ],
            $aposInserir,
        );
    }

    /**
     * O objeto e tudo o que está abaixo dele fora da lixeira (o que está debaixo de algo na lixeira também fica de fora).
     *
     * @return list<Objeto>
     */
    private function subarvoreAtiva(Objeto $raiz): array
    {
        $saida = [];
        $fora = [];
        foreach ($this->servicos->objetos->todosDaSubarvore($raiz->siteId, $raiz->caminho) as $x) {
            if ($x->status === 'apagado' || ($x->id !== $raiz->id && isset($fora[$x->paiId]))) {
                $fora[$x->id] = true;
                continue;
            }
            $saida[] = $x;
        }

        return $saida;
    }

    /** Endereço livre a partir do título (`Cópia de Notícia!` → `copia-de-noticia`, `-2`, `-3`… se já usado). */
    private function urlLivre(int $siteId, string $titulo): ?string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', Texto::normalizar($titulo)), '-');
        $base = rtrim(substr($base !== '' ? $base : 'conteudo', 0, 80), '-');
        for ($n = 1; $n <= 500; $n++) {
            $candidata = $n === 1 ? $base : $base . '-' . $n;
            if (Caminhos::urlValida($candidata) && $this->servicos->objetos->urlDisponivel($siteId, $candidata)) {
                return $candidata;
            }
        }

        return null;
    }

    /**
     * Atualiza as páginas públicas afetadas: poucas, na hora (cada uma publicada ou retirada conforme o estado vigente); muitas,
     * pela fila de republicação do site, que o cron central segue (ADR 0023). Uma falha nunca desfaz a colagem.
     *
     * @param list<int> $tocados movidos e destino
     * @param list<int> $antigosPais
     */
    private function republicar(Objeto $destino, array $tocados, array $antigosPais, int $afetados): string
    {
        $site = $this->servicos->sites->porId($destino->siteId);
        if ($site === null) {
            return 'falhou';
        }
        if ($afetados > self::LIMITE_NA_HORA) {
            try {
                $this->servicos->republicacao->iniciar($site, 'pilha');

                return 'fila';
            } catch (\Throwable) {
                return 'falhou';
            }
        }
        $ok = true;
        $agora = gmdate('c');
        foreach (array_unique([...$tocados, ...$antigosPais]) as $id) {
            $alvo = $this->servicos->objetos->porId($id);
            if ($alvo === null) {
                continue;
            }
            try {
                $alvo->visivel($agora) ? $this->servicos->publicador->publicar($site, $alvo) : $this->servicos->publicador->despublicar($site, $alvo);
            } catch (\Throwable) {
                $ok = false;
            }
        }

        return $ok ? 'ok' : 'falhou';
    }

    private function descricao(string $tipo, bool $profunda, int $quantidade, bool $feito): string
    {
        $dentro = $quantidade > 1 ? ' com ' . ($quantidade - 1) . ' dentro dele' : '';

        return match ($tipo) {
            self::MOVER => ($feito ? 'Movido' : 'Será movido') . $dentro . '.',
            self::LINK => ($feito ? 'Link criado' : 'Será criado um link') . ' (em rascunho).',
            default => ($feito ? 'Copiado' : 'Será copiado') . ($profunda ? $dentro : '') . ' (em rascunho).',
        };
    }

    private function classe(int $id): ?Classe
    {
        return $this->servicos->classes->porId($id);
    }
}
