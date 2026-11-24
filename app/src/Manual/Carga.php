<?php

declare(strict_types=1);

namespace Pbl\Manual;

use Pbl\Acesso\Usuario;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\HtmlSeguro;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\Servico\ConteudoNaoEncontrado;
use Pbl\Conteudo\Servico\EntradaInvalida;
use Pbl\Conteudo\Site;
use Pbl\Servicos;

/**
 * Carga idempotente do manual compilado num site PBL4 (ADR 0041), sempre pelo `ServicoDeConteudo` (permissões, versões, auditoria, cache).
 *
 * Identidade de cada objeto = a propriedade `origem` (`manual:<edição>:<capítulo>/<página>`). O hash do que a carga gerencia
 * (título, resumo, textos, peso) fica em `hash_gerado`: igual ao pacote = nada a fazer; igual ao `hash_gerado` anterior = ninguém
 * mexeu, atualiza; diferente dos dois = alguém editou no painel, então PRESERVA (lista como conflito; só `forcar` sobrescreve,
 * sempre gravando versão nova, nunca apagando histórico). Página que saiu da fonte é despublicada, nunca apagada nem enviada à lixeira.
 */
final class Carga
{
    public const CLASSE_EDICAO = 'manual_edicao';
    public const CLASSE_CAPITULO = 'manual_capitulo';
    public const CLASSE_PAGINA = 'manual_pagina';
    private const LIMITE = 5000;

    public function __construct(private readonly Servicos $s, private readonly Usuario $quem, private readonly Site $site)
    {
    }

    /**
     * As classes do manual (só o que falta é criado; nunca toca nas classes fixas).
     *
     * @return list<array{nome: string, propriedades: list<array{nome: string, tipo: string, indexavel: bool, rotulo: string}>, meta: array{descricao: string, aceita_filhos: bool, filhas: list<string>, fixa: bool}}>
     */
    public static function definicoes(): array
    {
        $texto = static fn (string $n, string $r, bool $i = false): array => ['nome' => $n, 'tipo' => 'texto', 'indexavel' => $i, 'rotulo' => $r];
        $html = static fn (string $n, string $r, bool $i = false): array => ['nome' => $n, 'tipo' => 'html', 'indexavel' => $i, 'rotulo' => $r];
        $controle = [$texto('origem', 'Origem (gerado pelo manual-gerar)'), $texto('hash_gerado', 'Impressão do que foi gerado')];

        return [
            ['nome' => self::CLASSE_EDICAO, 'propriedades' => [$texto('resumo', 'Resumo'), ...$controle],
                'meta' => ['descricao' => 'Uma edição do manual (beta ou uma versão guardada).', 'aceita_filhos' => true, 'filhas' => [], 'fixa' => false]],
            ['nome' => self::CLASSE_CAPITULO, 'propriedades' => [$texto('resumo', 'Resumo', true), $html('menu', 'Menu lateral da edição'), $texto('edicao', 'Edição', true), ...$controle],
                'meta' => ['descricao' => 'Capítulo do manual (gerado de docs/usuario).', 'aceita_filhos' => true, 'filhas' => [], 'fixa' => false]],
            ['nome' => self::CLASSE_PAGINA, 'propriedades' => [
                $texto('resumo', 'Resumo', true), $html('corpo', 'Texto', true), $html('toc', 'Nesta página'), $html('navegacao', 'Anterior e próximo'),
                $html('menu', 'Menu lateral da edição'), $texto('edicao', 'Edição', true), ...$controle,
            ], 'meta' => ['descricao' => 'Página do manual (gerada de docs/usuario).', 'aceita_filhos' => false, 'filhas' => [], 'fixa' => false]],
        ];
    }

    /** Cria o site do manual (pele `manual`, página inicial publicada) se o domínio ainda não existe. */
    public static function garantirSite(Servicos $s, string $host): Site
    {
        $existente = $s->sites->porHost(strtolower($host));
        if ($existente !== null) {
            return $existente;
        }
        $pagina = $s->classes->porNome('pagina') ?? throw new \RuntimeException('Tipo pagina não existe');
        $site = $s->sites->criar('Manual do Publicare 4', $host, 'manual');
        $inicio = $s->objetos->criar($site->id, $pagina, null, 'Manual do Publicare 4', '', [
            'resumo' => 'Como usar o Publicare 4, tarefa por tarefa.',
            'corpo' => '<p>Escolha um capítulo.</p>',
        ]);
        $s->sites->definirRaiz($site->id, $inicio->id);
        $s->configs->definir($site->id, ['nome' => 'Manual do Publicare 4', 'idioma' => 'pt-BR']);

        return $s->sites->porId($site->id) ?? $site;
    }

    /**
     * @param array<string, mixed> $pacote saída do Compilador
     * @return array{edicao: string, simulado: bool, alterado: bool, plano: list<array{acao: string, origem: string, titulo: string}>, conflitos: list<string>, resumo: array<string, int>}
     */
    public function executar(array $pacote, Edicao $edicao, bool $simular, bool $forcar): array
    {
        if (!$simular) {
            $this->garantirClasses();
        }
        $raiz = $this->raiz();
        $pai = $edicao->ehEstavel() ? $raiz : $this->objetoDaEdicao($raiz, $edicao, $simular);
        $existentes = $this->existentes($edicao);
        $antes = $existentes;
        $desejados = $this->desejados($pacote, $edicao);
        $plano = [];
        $conflitos = [];
        $gravacoes = [];
        $idsPorOrigem = [];
        foreach ($desejados as $d) {
            $atual = $existentes[$d['origem']] ?? null;
            unset($existentes[$d['origem']]);
            $paiId = $d['pai'] === null ? ($pai?->id) : ($idsPorOrigem[$d['pai']] ?? null);
            if ($atual === null) {
                $plano[] = ['acao' => 'criar', 'origem' => $d['origem'], 'titulo' => $d['titulo']];
                $gravacoes[] = [$d, null, $paiId];
                $idsPorOrigem[$d['origem']] = null;
                continue;
            }
            $idsPorOrigem[$d['origem']] = $atual->id;
            $hashAtual = $this->hashDoAtual($atual, $d['tipo']);
            $acao = match (true) {
                $hashAtual === $d['hash'] && $atual->status === 'publicado' => 'igual',
                $hashAtual === $d['hash'] => 'publicar',
                $hashAtual === (string) ($atual->dados['hash_gerado'] ?? '') || $forcar => 'atualizar',
                default => 'preservar',
            };
            $plano[] = ['acao' => $acao, 'origem' => $d['origem'], 'titulo' => $d['titulo']];
            if ($acao === 'preservar') {
                $conflitos[] = $d['origem'];
            } elseif ($acao !== 'igual') {
                $gravacoes[] = [$d, $atual, $paiId];
            }
        }
        $despublicar = [];
        foreach ($existentes as $origem => $atual) {
            if ($atual->status !== 'publicado') {
                continue;
            }
            $tipo = $this->s->classes->porId($atual->classeId)->nome ?? '';
            $mesmo = $this->hashDoAtual($atual, $tipo) === (string) ($atual->dados['hash_gerado'] ?? '');
            if ($mesmo || $forcar) {
                $plano[] = ['acao' => 'despublicar', 'origem' => $origem, 'titulo' => $atual->titulo];
                $despublicar[] = $atual;
            } else {
                $plano[] = ['acao' => 'preservar', 'origem' => $origem, 'titulo' => $atual->titulo];
                $conflitos[] = $origem;
            }
        }
        $alterado = $gravacoes !== [] || $despublicar !== [];
        if (!$simular && $alterado) {
            $this->guardarCopia($edicao, $antes);
            $this->aplicar($gravacoes, $despublicar, $pai ?? throw new \RuntimeException('Sem a raiz da edição.'), $idsPorOrigem);
        }
        $contagem = array_count_values(array_column($plano, 'acao'));
        ksort($contagem);

        return ['edicao' => $edicao->chave, 'simulado' => $simular, 'alterado' => $alterado, 'plano' => $plano, 'conflitos' => $conflitos, 'resumo' => $contagem];
    }

    /**
     * @param list<array{0: array<string, mixed>, 1: ?Objeto, 2: ?int}> $gravacoes
     * @param list<Objeto> $despublicar
     * @param array<string, ?int> $ids
     */
    private function aplicar(array $gravacoes, array $despublicar, Objeto $pai, array $ids): void
    {
        foreach ($gravacoes as [$d, $atual, $paiId]) {
            $campos = ['titulo' => $d['titulo'], 'peso' => (string) $d['peso'], 'dados' => $d['dados']];
            if ($atual === null) {
                $paiReal = $d['pai'] === null ? $pai->id : ($ids[$d['pai']] ?? $paiId ?? $pai->id);
                $campos['url'] = $d['url'];
                $r = $this->s->conteudo->criar($this->quem, $paiReal, $d['tipo'], $campos);
                $ids[$d['origem']] = $r->objeto->id;
                $this->s->conteudo->publicar($this->quem, $r->objeto->id, $r->objeto->versao);
                continue;
            }
            $this->s->conteudo->editar($this->quem, $atual->id, $atual->versao, $campos, true);
        }
        foreach ($despublicar as $o) {
            $atual = $this->s->conteudo->obter($this->quem, $o->id);
            $this->s->conteudo->despublicar($this->quem, $atual->id, $atual->versao);
        }
    }

    private function garantirClasses(): void
    {
        $classes = $this->s->classes;
        foreach (self::definicoes() as $d) {
            if ($classes->porNome($d['nome']) === null) {
                $classes->criar($d['nome'], $d['propriedades'], $d['meta']);
            }
        }
        // O pai precisa aceitar as filhas: a página inicial é `pagina`, que já aceita qualquer filha.
    }

    private function raiz(): Objeto
    {
        $id = $this->site->objetoRaizId ?? throw new ConteudoNaoEncontrado('O site não tem página inicial.');

        return $this->s->conteudo->obter($this->quem, $id);
    }

    private function objetoDaEdicao(Objeto $raiz, Edicao $edicao, bool $simular): ?Objeto
    {
        $origem = $edicao->origem('edicao');
        $achados = $this->s->conteudo->listar($this->quem, $this->site, $raiz, false, null, self::CLASSE_EDICAO, null, 500)['itens'];
        foreach ($achados as $o) {
            if (($o->dados['origem'] ?? null) === $origem) {
                return $o;
            }
        }
        if ($simular) {
            return null;
        }
        $r = $this->s->conteudo->criar($this->quem, $raiz->id, self::CLASSE_EDICAO, [
            'titulo' => $edicao->rotulo, 'url' => rtrim($edicao->prefixoDeUrl(), '/'), 'peso' => $edicao->chave === Edicao::BETA ? '5' : '20',
            'dados' => ['resumo' => 'Edição ' . $edicao->rotulo . ' do manual.', 'origem' => $origem, 'hash_gerado' => ''],
        ]);
        $this->s->conteudo->publicar($this->quem, $r->objeto->id, $r->objeto->versao);

        return $this->s->conteudo->obter($this->quem, $r->objeto->id);
    }

    /** @return array<string, Objeto> origem => objeto, só do que pertence a esta edição (e é capítulo ou página) */
    private function existentes(Edicao $edicao): array
    {
        $saida = [];
        $prefixo = $edicao->origem('');
        foreach ([self::CLASSE_CAPITULO, self::CLASSE_PAGINA] as $tipo) {
            if ($this->s->classes->porNome($tipo) === null) {
                continue;
            }
            foreach ($this->s->conteudo->listar($this->quem, $this->site, null, true, null, $tipo, null, self::LIMITE)['itens'] as $o) {
                $origem = (string) ($o->dados['origem'] ?? '');
                if (str_starts_with($origem, $prefixo)) {
                    $saida[$origem] = $o;
                }
            }
        }

        return $saida;
    }

    /**
     * Os objetos desejados, na ordem de criação (capítulo antes das suas páginas), com os valores finais (marcadores trocados e HTML limpo).
     *
     * @param array<string, mixed> $pacote
     * @return list<array{origem: string, pai: ?string, tipo: string, titulo: string, url: string, peso: int, dados: array<string, string>, hash: string}>
     */
    private function desejados(array $pacote, Edicao $edicao): array
    {
        $troca = static fn (string $h): string => str_replace([Compilador::ED, Compilador::IMG], [$edicao->prefixoDeLink(), $edicao->pastaDeCapturas()], $h);
        $menu = $troca($this->menu($pacote));
        $saida = [];
        foreach ($pacote['capitulos'] as $c) {
            $origemC = $edicao->origem($c['slug']);
            $saida[] = $this->item($origemC, null, self::CLASSE_CAPITULO, $c['titulo'], $edicao->prefixoDeUrl() . $c['slug'], (int) $c['peso'], [
                'resumo' => $c['resumo'], 'menu' => $menu, 'edicao' => $edicao->chave, 'origem' => $origemC,
            ]);
            foreach ($c['paginas'] as $p) {
                $origemP = $edicao->origem($c['slug'] . '/' . $p['slug']);
                $saida[] = $this->item($origemP, $origemC, self::CLASSE_PAGINA, $p['titulo'], $edicao->prefixoDeUrl() . $c['slug'] . '/' . $p['slug'], (int) $p['peso'], [
                    'resumo' => $p['resumo'], 'corpo' => $troca($p['html']), 'toc' => $troca($p['toc']), 'navegacao' => $troca($p['navegacao']),
                    'menu' => $menu, 'edicao' => $edicao->chave, 'origem' => $origemP,
                ]);
            }
        }

        return $saida;
    }

    /**
     * @param array<string, string> $dados
     * @return array{origem: string, pai: ?string, tipo: string, titulo: string, url: string, peso: int, dados: array<string, string>, hash: string}
     */
    private function item(string $origem, ?string $pai, string $tipo, string $titulo, string $url, int $peso, array $dados): array
    {
        foreach (self::CAMPOS_HTML as $campo) {
            if (isset($dados[$campo])) {
                $dados[$campo] = HtmlSeguro::limpar($dados[$campo]);
            }
        }
        $hash = self::hash($tipo, $titulo, $peso, $dados);
        $dados['hash_gerado'] = $hash;

        return ['origem' => $origem, 'pai' => $pai, 'tipo' => $tipo, 'titulo' => $titulo, 'url' => $url, 'peso' => $peso, 'dados' => $dados, 'hash' => $hash];
    }

    private const CAMPOS_HTML = ['corpo', 'toc', 'navegacao', 'menu'];
    private const GERENCIADOS = ['resumo', 'corpo', 'toc', 'navegacao', 'menu', 'edicao', 'origem'];

    /** @param array<string, string> $dados */
    private static function hash(string $tipo, string $titulo, int $peso, array $dados): string
    {
        $g = ['tipo' => $tipo, 'titulo' => $titulo, 'peso' => $peso];
        foreach (self::GERENCIADOS as $campo) {
            $g[$campo] = (string) ($dados[$campo] ?? '');
        }

        return hash('sha256', json_encode($g, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function hashDoAtual(Objeto $o, string $tipo): string
    {
        $dados = [];
        foreach (self::GERENCIADOS as $campo) {
            $dados[$campo] = (string) ($o->dados[$campo] ?? '');
        }

        return self::hash($tipo, $o->titulo, $o->peso, $dados);
    }

    /** @param array<string, mixed> $pacote */
    private function menu(array $pacote): string
    {
        $html = '<ul>';
        foreach ($pacote['capitulos'] as $c) {
            $html .= '<li><a href="' . Compilador::ED . $c['slug'] . '/">' . htmlspecialchars((string) $c['titulo'], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a><ul>';
            foreach ($c['paginas'] as $p) {
                $html .= '<li><a href="' . Compilador::ED . $c['slug'] . '/' . $p['slug'] . '/">' . htmlspecialchars((string) $p['titulo'], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></li>';
            }
            $html .= '</ul></li>';
        }

        return $html . '</ul>';
    }

    /**
     * Copia (JSON, sem data no nome) do que a carga vai tocar, em `dados/manual/`: o nome leva a edição e a impressão do conteúdo,
     * então repetir a mesma carga não acumula arquivos. Restaurar = reaplicar cada versão pelo histórico do próprio conteúdo.
     *
     * @param array<string, Objeto> $objetos
     */
    private function guardarCopia(Edicao $edicao, array $objetos): void
    {
        if ($objetos === []) {
            return;
        }
        $itens = [];
        foreach ($objetos as $origem => $o) {
            $itens[$origem] = ['id' => $o->id, 'versao' => $o->versao, 'status' => $o->status, 'titulo' => $o->titulo, 'peso' => $o->peso, 'dados' => $o->dados];
        }
        ksort($itens);
        $json = json_encode($itens, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $pasta = $this->s->caminhos->dados() . '/manual';
        if (!is_dir($pasta) && !@mkdir($pasta, 0750, true) && !is_dir($pasta)) {
            throw new \RuntimeException('Não foi possível criar dados/manual para a cópia de segurança.');
        }
        file_put_contents($pasta . '/copia-' . $edicao->chave . '-' . substr(hash('sha256', $json), 0, 12) . '.json', $json, LOCK_EX);
    }

    /** Copia as capturas compiladas para os assets da pele, numa pasta por edição. */
    public function copiarCapturas(string $origem, Edicao $edicao): int
    {
        $destino = $this->s->caminhos->peles() . '/manual/assets/capturas/' . $edicao->chave;
        $n = 0;
        foreach (glob($origem . '/*.png') ?: [] as $png) {
            if (!is_dir($destino) && !@mkdir($destino, 0755, true) && !is_dir($destino)) {
                throw new \RuntimeException('Não foi possível criar a pasta das capturas da pele.');
            }
            if (!is_file($destino . '/' . basename($png)) || hash_file('sha256', $png) !== hash_file('sha256', $destino . '/' . basename($png))) {
                copy($png, $destino . '/' . basename($png));
                $n++;
            }
        }

        return $n;
    }
}
