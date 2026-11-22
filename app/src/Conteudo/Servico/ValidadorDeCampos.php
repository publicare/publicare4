<?php

declare(strict_types=1);

namespace Pbl\Conteudo\Servico;

use Pbl\Acesso\Acao;
use Pbl\Acesso\Permissoes;
use Pbl\Acesso\Usuario;
use Pbl\Busca\Texto;
use Pbl\Caminhos;
use Pbl\Conteudo\Classe;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\HtmlSeguro;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Conteudo\TagRepositorio;

/**
 * Leitura e validação dos campos de um conteúdo (título, descrição, endereço, peso, menu, tags e as propriedades da classe),
 * sem HTTP e sem sessão. É a regra que o painel e o CLI compartilham: mesmos limites, mesmas mensagens, mesma limpeza de HTML.
 * Propriedades `arquivo`/`imagem` não passam por aqui (têm operação própria, com upload e validação por conteúdo).
 */
final class ValidadorDeCampos
{
    public const MAX_TITULO = 300;
    public const MAX_DESCRICAO = 2000;
    public const MAX_ROTULO_MENU = 60;
    /** Teto de uma propriedade `texto` e de uma `html` (em caracteres). */
    public const MAX_TEXTO = 10000;
    public const MAX_HTML = 200000;
    public const MAX_ALT = 500;

    public function __construct(
        private readonly ObjetoRepositorio $objetos,
        private readonly ClasseRepositorio $classes,
        private readonly Permissoes $permissoes,
    ) {
    }

    /**
     * Lê e valida o que veio. Na edição (`$atual` existe), campo ausente mantém o valor atual; valor vazio numa propriedade remove.
     * Na criação, endereço vazio é aceito (o serviço gera a partir do título).
     *
     * @param array<string, mixed> $campos titulo, descricao, url, peso, no_menu, rotulo_menu, tags (texto separado por vírgula), dados (nome => texto)
     * @return array{0: array{titulo: string, descricao: string, url_amigavel?: string, peso: int, no_menu: bool, rotulo_menu: string, dados: array<string, string|int|float|null>, tags?: list<string>}, 1: array<string, string>}
     */
    public function ler(Usuario $quem, int $siteId, Classe $classe, array $campos, ?Objeto $atual): array
    {
        $erros = [];
        $texto = function (string $chave, string $padrao) use ($campos, &$erros): string {
            $v = $campos[$chave] ?? $padrao;
            if (!is_string($v)) {
                $erros[$chave === 'url' ? 'url' : $chave] = 'Este campo precisa ser um texto.';

                return $padrao;
            }

            return $v;
        };
        $titulo = trim($texto('titulo', $atual->titulo ?? ''));
        $descricao = trim($this->linhas($texto('descricao', $atual->descricao ?? '')));
        $url = trim($texto('url', $atual === null ? '' : $atual->urlAmigavel));
        $peso = trim($texto('peso', $atual === null ? '0' : (string) $atual->peso));
        $noMenuTexto = $texto('no_menu', $atual?->noMenu ? '1' : '0');
        $rotulo = trim($texto('rotulo_menu', $atual === null ? '' : $atual->rotuloMenu));
        $valores = ['titulo' => $titulo, 'descricao' => $descricao, 'peso' => 0, 'no_menu' => $noMenuTexto === '1', 'rotulo_menu' => $rotulo, 'dados' => []];
        if (!in_array($noMenuTexto, ['0', '1'], true)) {
            $erros['no_menu'] = 'Use 1 (aparece no menu) ou 0.';
        }

        if (array_key_exists('tags', $campos)) {
            $brutas = $campos['tags'];
            if (!is_string($brutas)) {
                $erros['tags'] = 'As tags precisam ser um texto separado por vírgula.';
            } elseif (!mb_check_encoding($brutas, 'UTF-8') || $this->temControle($brutas, true)) {
                $erros['tags'] = 'As tags têm caracteres que não são permitidos.';
            } else {
                $nomes = TagRepositorio::interpretar($brutas);
                $erroTags = TagRepositorio::validar($nomes);
                if ($erroTags !== null) {
                    $erros['tags'] = $erroTags;
                } else {
                    $valores['tags'] = $nomes;
                }
            }
        }

        if (!mb_check_encoding($rotulo, 'UTF-8') || $this->temControle($rotulo, false)) {
            $erros['rotulo_menu'] = 'O texto do menu tem caracteres que não são permitidos.';
        } elseif (mb_strlen($rotulo) > self::MAX_ROTULO_MENU) {
            $erros['rotulo_menu'] = 'O texto do menu pode ter até ' . self::MAX_ROTULO_MENU . ' caracteres.';
        }
        if (!mb_check_encoding($titulo, 'UTF-8') || $this->temControle($titulo, false)) {
            $erros['titulo'] = 'O título tem caracteres que não são permitidos.';
        } elseif ($titulo === '') {
            $erros['titulo'] = 'O título é obrigatório.';
        } elseif (mb_strlen($titulo) > self::MAX_TITULO) {
            $erros['titulo'] = 'O título pode ter até ' . self::MAX_TITULO . ' caracteres.';
        }
        if (!mb_check_encoding($descricao, 'UTF-8') || $this->temControle($descricao, true)) {
            $erros['descricao'] = 'A descrição tem caracteres que não são permitidos.';
        } elseif (mb_strlen($descricao) > self::MAX_DESCRICAO) {
            $erros['descricao'] = 'A descrição pode ter até ' . self::MAX_DESCRICAO . ' caracteres.';
        }
        // A página inicial do site (sem pai) mantém o endereço vazio; nos demais, endereço vazio só ao criar (gera do título).
        if (($atual === null || $atual->paiId !== null) && !isset($erros['url'])) {
            if ($url === '' && $atual !== null) {
                $erros['url'] = 'O endereço é obrigatório.';
            } elseif ($url !== '' && !Caminhos::urlValida($url)) {
                $erros['url'] = 'Use só letras minúsculas sem acento, números e hífen, com "/" separando partes (exemplo: noticias/2026/aviso).';
            } elseif ($url !== '' && !$this->objetos->urlDisponivel($siteId, $url, $atual?->id)) {
                $erros['url'] = 'Este endereço já é de outro conteúdo (ou já foi, e hoje leva até ele). Escolha outro.';
            } else {
                $valores['url_amigavel'] = $url;
            }
        }
        if (preg_match('/^-?[0-9]{1,6}$/D', $peso) === 1) {
            $valores['peso'] = (int) $peso;
        } else {
            $erros['peso'] = 'O peso precisa ser um número inteiro (exemplo: 0, 10 ou -5).';
        }

        $dadosBrutos = $campos['dados'] ?? [];
        if (!is_array($dadosBrutos)) {
            $erros['dados'] = 'Os campos do tipo precisam vir como nome => valor.';
            $dadosBrutos = [];
        }
        $ativas = [];
        foreach ($classe->propriedades as $p) {
            if (!($p['arquivada'] ?? false)) {
                $ativas[$p['nome']] = $p;
            }
        }
        foreach (array_keys($dadosBrutos) as $nome) {
            if (!isset($ativas[(string) $nome])) {
                $erros['dados.' . $nome] = 'Este tipo de conteúdo não tem o campo "' . $nome . '".';
            } elseif (in_array($ativas[(string) $nome]['tipo'], ['arquivo', 'imagem'], true)) {
                $erros['dados.' . $nome] = 'Arquivos e imagens se anexam com o subcomando "' . $ativas[(string) $nome]['tipo'] . '", não como texto.';
            }
        }
        foreach ($ativas as $nome => $p) {
            if (in_array($p['tipo'], ['arquivo', 'imagem'], true)) {
                continue;
            }
            $guardado = $atual === null || !isset($atual->dados[$nome]) ? null : (string) $atual->dados[$nome];
            if (!array_key_exists($nome, $dadosBrutos)) {
                if ($atual === null && ($p['obrigatorio'] ?? false)) {
                    $erros['dados.' . $nome] = 'Este campo é obrigatório.';
                }
                continue; // não tocado: mantém o que está guardado
            }
            $bruto = $dadosBrutos[$nome];
            if (!is_string($bruto)) {
                $erros['dados.' . $nome] = 'Este campo precisa ser um texto.';
                continue;
            }
            $t = in_array($p['tipo'], ['texto', 'html'], true) ? trim($this->linhas($bruto)) : trim($bruto);
            [$valor, $erro] = $this->propriedade($quem, $siteId, $p, $t, $guardado);
            if ($erro === null && $valor === null && ($p['obrigatorio'] ?? false)) {
                $erro = 'Este campo é obrigatório.';
            }
            if ($erro !== null) {
                $erros['dados.' . $nome] = $erro;
            } else {
                $valores['dados'][$nome] = $valor;
            }
        }

        return [$valores, $erros];
    }

    /**
     * @param array{nome: string, tipo: string, indexavel: bool, classe?: string} $p
     * @return array{0: string|int|float|null, 1: string|null} [valor, erro]
     */
    public function propriedade(Usuario $quem, int $siteId, array $p, string $texto, ?string $guardado = null): array
    {
        if ($texto === '') {
            return [null, null];
        }
        if (!mb_check_encoding($texto, 'UTF-8')) {
            return [null, 'Este campo tem caracteres que não são permitidos.'];
        }
        switch ($p['tipo']) {
            case 'numero':
                $n = str_replace(',', '.', $texto);
                if (preg_match('/^-?[0-9]{1,15}$/D', $n) === 1) {
                    return [(int) $n, null];
                }
                if (preg_match('/^-?[0-9]{1,15}\.[0-9]{1,10}$/D', $n) === 1) {
                    return [(float) $n, null];
                }

                return [null, 'Este campo precisa ser um número (exemplo: 42 ou 3,5).'];
            case 'booleano':
                return in_array($texto, ['0', '1'], true) ? [(int) $texto, null] : [null, 'Escolha "Sim" ou "Não".'];
            case 'data':
                if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}$/D', $texto) === 1) {
                    $texto .= ':00';
                }
                foreach (['!Y-m-d', '!Y-m-d H:i:s', '!Y-m-d\TH:i:sP'] as $formato) {
                    $d = \DateTimeImmutable::createFromFormat($formato, $texto);
                    $problemas = \DateTimeImmutable::getLastErrors();
                    if ($d !== false && ($problemas === false || ($problemas['warning_count'] === 0 && $problemas['error_count'] === 0))) {
                        return [$texto, null];
                    }
                }

                return [null, 'Use uma data válida no formato aaaa-mm-dd, com hora opcional (exemplo: 2026-12-31 ou 2026-12-31 18:30).'];
            case 'ref':
                $alvo = preg_match('/^[1-9][0-9]{0,9}$/D', $texto) === 1 ? $this->objetos->porId((int) $texto) : null;
                // Mesma mensagem para "não existe", "é de outro site" e "você não pode ver": não revela ids alheios. A referência
                // que já estava gravada continua valendo mesmo que a pessoa não possa ver o alvo (ela só não pode apontar para outro).
                $mantida = $guardado !== null && $alvo !== null && (string) $alvo->id === $guardado;
                if ($alvo === null || $alvo->siteId !== $siteId || (!$mantida && !$this->permissoes->pode($quem, Acao::Ver, $alvo))) {
                    return [null, 'Indique o número de um conteúdo deste site que você possa ver.'];
                }
                if (isset($p['classe']) && ($this->classes->porId($alvo->classeId)->nome ?? '?') !== $p['classe']) {
                    return [null, 'Este campo precisa apontar para um conteúdo do tipo ' . $p['classe'] . '.'];
                }

                return [$alvo->id, null];
            default: // texto e html
                $max = $p['tipo'] === 'html' ? self::MAX_HTML : self::MAX_TEXTO;
                if ($this->temControle($texto, true)) {
                    return [null, 'Este campo tem caracteres de controle, que não são permitidos.'];
                }
                if (mb_strlen($texto) > $max) {
                    return [null, 'Este campo pode ter até ' . $max . ' caracteres.'];
                }
                if ($p['tipo'] === 'html') {
                    // O repositório limpa de novo ao gravar; limpar aqui mostra o que de fato ficará salvo.
                    $limpo = HtmlSeguro::limpar($texto);

                    return $limpo === '' ? [null, 'Nada deste HTML é permitido (scripts, estilos, formulários e eventos são removidos). Escreva o texto com as marcações comuns.'] : [$limpo, null];
                }

                return [$texto, null];
        }
    }

    /** Texto alternativo de imagem: texto simples, sem marcação, sem controle, até {@see MAX_ALT} caracteres. null = ok, senão a mensagem. */
    public function problemaNoAlt(string $alt): ?string
    {
        $alt = trim($alt);
        if ($alt === '') {
            return 'O texto alternativo (--alt) é obrigatório em imagem.';
        }
        if (!mb_check_encoding($alt, 'UTF-8') || $this->temControle($alt, false) || preg_match('/[<>]/', $alt) === 1) {
            return 'O texto alternativo tem caracteres que não são permitidos (sem marcação HTML).';
        }

        return mb_strlen($alt) > self::MAX_ALT ? 'O texto alternativo pode ter até ' . self::MAX_ALT . ' caracteres.' : null;
    }

    /** Endereço livre a partir do título (`Notícia de Hoje!` → `noticia-de-hoje`, `-2`, `-3`… se já usado), ou null. */
    public function urlLivre(int $siteId, string $titulo): ?string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', Texto::normalizar($titulo)), '-');
        $base = rtrim(substr($base !== '' ? $base : 'conteudo', 0, 80), '-');
        for ($n = 1; $n <= 50; $n++) {
            $candidata = $n === 1 ? $base : $base . '-' . $n;
            if (Caminhos::urlValida($candidata) && $this->objetos->urlDisponivel($siteId, $candidata)) {
                return $candidata;
            }
        }

        return null;
    }

    public function linhas(string $texto): string
    {
        return str_replace(["\r\n", "\r"], "\n", $texto);
    }

    /** Bytes de controle (abaixo de 0x20 e 0x7F) nunca aparecem dentro de um caractere UTF-8 de vários bytes. */
    public function temControle(string $texto, bool $multilinha): bool
    {
        return preg_match($multilinha ? '/[\x00-\x08\x0B-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $texto) === 1;
    }
}
