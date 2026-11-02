<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Versao;
use Symfony\Component\HttpFoundation\Response;

/**
 * Páginas HTML da administração e do instalador: JavaScript só de arquivos próprios (sem inline), tudo escapado, com os cabeçalhos de segurança. O visual vem do
 * Bootstrap 5 servido do próprio site (`public/_pbl/assets/`, sem CDN) mais `pbl.css`; nenhum estilo ou script inline, então a
 * CSP só libera `'self'` (ADR 0012).
 */
final class Pagina
{
    /** Pasta pública dos arquivos estáticos da administração. Arquivo existente é servido direto pelo servidor web. */
    public const ASSETS = '/_pbl/assets';
    public const BOOTSTRAP = self::ASSETS . '/bootstrap-5.3.8/bootstrap.min.css';
    public const CSS = self::ASSETS . '/pbl.css';
    /** Scripts da administração: só arquivos do próprio site (Bootstrap local + `pbl-admin.js`), nunca inline (CSP `script-src 'self'`). */
    public const BOOTSTRAP_JS = self::ASSETS . '/bootstrap-5.3.8/bootstrap.bundle.min.js';
    public const JS = self::ASSETS . '/pbl-admin.js';

    public static function h(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * URL de um arquivo estático da administração com `?v=` (hash curto do conteúdo): o servidor/CDN guarda esses arquivos por
     * anos, então sem a versão na URL um ajuste de CSS nunca chegaria a quem já abriu o painel. Arquivo ausente: versão do produto.
     */
    public static function urlVersionada(string $url): string
    {
        static $cache = [];
        if (!isset($cache[$url])) {
            $arquivo = dirname(__DIR__, 3) . '/public' . $url;
            $hash = is_file($arquivo) ? hash_file('crc32b', $arquivo) : false;
            $cache[$url] = $url . '?v=' . ($hash === false ? rawurlencode(Versao::ATUAL) : $hash);
        }

        return $cache[$url];
    }

    /** Caminho da requisição em curso, para a barra marcar o item ativo (definido por `Painel::tratar`). */
    private static string $caminhoAtual = '';

    public static function definirCaminho(string $caminho): void
    {
        self::$caminhoAtual = $caminho;
    }

    /**
     * `$navegacao` (HTML já escapado, em geral uma `<nav>`) vai num `<header>` antes do `<main>`; o título vira o `<h1>`.
     * `$migalhas` (lista de `[rótulo, href|null]`, o último sem href = tela atual) vira o caminho de volta acima do título;
     * `$descricao` (HTML já escapado) é uma frase curta abaixo dele.
     *
     * @param list<array{0: string, 1: string|null}> $migalhas
     */
    public static function resposta(string $titulo, string $corpo, int $status = Response::HTTP_OK, bool $seguro = false, string $navegacao = '', array $migalhas = [], string $descricao = ''): Response
    {
        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . self::h($titulo) . ' — PBL4</title>'
            . '<link rel="stylesheet" href="' . self::urlVersionada(self::BOOTSTRAP) . '"><link rel="stylesheet" href="' . self::urlVersionada(self::CSS) . '">'
            . '<script src="' . self::urlVersionada(self::BOOTSTRAP_JS) . '" defer></script><script src="' . self::urlVersionada(self::JS) . '" defer></script>'
            . '</head><body><a class="visually-hidden-focusable" href="#conteudo">Pular para o conteúdo</a>'
            . ($navegacao === '' ? '' : '<header>' . $navegacao . '</header>')
            . '<main id="conteudo" class="container py-3 py-md-4">' . self::migalhas($migalhas)
            . '<h1 class="h3 mb-2">' . self::h($titulo) . '</h1>'
            . ($descricao === '' ? '<div class="mb-4"></div>' : '<p class="text-body-secondary mb-4">' . $descricao . '</p>')
            . self::tabelasEmCartoes($corpo) . '</main></body></html>';

        return self::protegida(new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']), $seguro);
    }

    /**
     * Toda tabela com `<thead>` ganha a classe `pbl-cartoes` e, em cada célula do corpo, `data-label` com o texto do cabeçalho da
     * coluna: em telas estreitas o CSS vira cada linha num cartão empilhado (rótulo + valor), sem cabeçalho quebrado letra a letra.
     * Linhas com `colspan` ficam como estão. O HTML aqui é sempre o que as próprias telas geraram (já escapado).
     */
    public static function tabelasEmCartoes(string $html): string
    {
        if (!str_contains($html, '</thead>')) {
            return $html;
        }

        return preg_replace_callback('#<table class="([^"]*)"([^>]*)>(.*?)</table>#s', static function (array $m): string {
            $partes = explode('</thead>', $m[3], 2);
            if (count($partes) !== 2) {
                return $m[0];
            }
            preg_match_all('#<th\b[^>]*>(.*?)</th>#s', $partes[0], $ths);
            $rotulos = array_map(static fn (string $t): string => trim(html_entity_decode(strip_tags(preg_replace('#<span class="visually-hidden">.*?</span>#s', '', $t) ?? $t), ENT_QUOTES, 'UTF-8')), $ths[1]);
            $corpo = preg_replace_callback('#<tr>(.*?)</tr>#s', static function (array $l) use ($rotulos): string {
                if (str_contains($l[1], 'colspan=')) {
                    return $l[0];
                }
                $i = 0;
                $celulas = preg_replace_callback('#<(td|th)\b([^>]*)>#', static function (array $c) use ($rotulos, &$i): string {
                    $rotulo = $rotulos[$i++] ?? '';

                    return $rotulo === '' ? $c[0] : '<' . $c[1] . $c[2] . ' data-label="' . self::h($rotulo) . '">';
                }, $l[1]);

                return '<tr>' . ($celulas ?? $l[1]) . '</tr>';
            }, $partes[1]);

            return '<table class="' . $m[1] . ' pbl-cartoes"' . $m[2] . '>' . $partes[0] . '</thead>' . ($corpo ?? $partes[1]) . '</table>';
        }, $html) ?? $html;
    }

    /** @param list<array{0: string, 1: string|null}> $migalhas */
    public static function migalhas(array $migalhas): string
    {
        if ($migalhas === []) {
            return '';
        }
        $itens = '';
        $ultimo = array_key_last($migalhas);
        foreach ($migalhas as $i => [$rotulo, $href]) {
            $itens .= $i === $ultimo || $href === null
                ? '<li class="breadcrumb-item active" aria-current="page">' . self::h($rotulo) . '</li>'
                : '<li class="breadcrumb-item"><a href="' . self::h($href) . '">' . self::h($rotulo) . '</a></li>';
        }

        return '<nav aria-label="Você está em" class="pbl-migalhas"><ol class="breadcrumb mb-2">' . $itens . '</ol></nav>';
    }

    /**
     * Barra de quem entrou: na primeira linha a marca, o nome, o botão Sair e (no celular) o botão do menu; os links das seções ficam
     * num `collapse` do Bootstrap, abaixo no celular e em linha a partir de 768 px. A seção atual leva `aria-current="page"`. `$nome` e `$tokenCsrf` chegam sem escape.
     */
    public static function barra(string $nome, string $tokenCsrf, bool $administrador = false): string
    {
        $caminho = rtrim(self::$caminhoAtual, '/');
        $secao = match (true) {
            str_starts_with($caminho, '/_pbl/classes') => 'classes',
            str_starts_with($caminho, '/_pbl/contas') => 'contas',
            str_starts_with($caminho, '/_pbl/atualizacao') => 'atualizacao',
            default => 'inicio',
        };
        $itens = ['inicio' => ['/_pbl/', 'Sites e conteúdo'], 'classes' => ['/_pbl/classes', 'Tipos de conteúdo']];
        if ($administrador) {
            $itens['contas'] = ['/_pbl/contas', 'Contas'];
            $itens['atualizacao'] = ['/_pbl/atualizacao', 'Atualização'];
        }
        $links = '';
        foreach ($itens as $chave => [$href, $rotulo]) {
            $links .= '<li class="nav-item"><a class="nav-link' . ($chave === $secao ? ' active' : '') . '" href="' . $href . '"' . ($chave === $secao ? ' aria-current="page"' : '') . '>' . $rotulo . '</a></li>';
        }

        return '<nav class="navbar navbar-expand-md bg-dark pbl-barra" data-bs-theme="dark" aria-label="Administração"><div class="container">'
            . '<a class="navbar-brand" href="/_pbl/">PBL4</a>'
            . '<div class="pbl-usuario d-flex align-items-center gap-2 ms-auto"><span class="navbar-text pbl-nome">' . self::h($nome) . '</span>'
            . '<form method="post" action="/_pbl/sair"><input type="hidden" name="_csrf" value="' . self::h($tokenCsrf) . '">'
            . '<button type="submit" class="btn btn-sm btn-outline-light">Sair</button></form></div>'
            . '<button class="navbar-toggler ms-2" type="button" data-bs-toggle="collapse" data-bs-target="#menu-principal" aria-controls="menu-principal" aria-expanded="false" aria-label="Abrir o menu">'
            . '<span class="navbar-toggler-icon"></span></button>'
            . '<div class="collapse navbar-collapse" id="menu-principal"><ul class="navbar-nav pbl-links">' . $links . '</ul></div></div></nav>';
    }

    /**
     * Painel recolhível do Bootstrap (`accordion` + `collapse`, sem `data-bs-parent`: abrir um não fecha os outros). `$resumo` e `$corpo`
     * chegam como HTML já escapado; `$id` precisa ser único na página. Agrupe vários dentro de `<div class="accordion">`.
     */
    public static function recolhivel(string $id, string $resumo, string $corpo, bool $aberto = false): string
    {
        $alvo = self::h('painel-' . $id);

        return '<div class="accordion-item"><h3 class="accordion-header"><button class="accordion-button pbl-resumo' . ($aberto ? '' : ' collapsed') . '" type="button" data-bs-toggle="collapse" data-bs-target="#' . $alvo . '" aria-expanded="' . ($aberto ? 'true' : 'false') . '" aria-controls="' . $alvo . '">'
            . $resumo . '</button></h3><div id="' . $alvo . '" class="accordion-collapse collapse' . ($aberto ? ' show' : '') . '"><div class="accordion-body">' . $corpo . '</div></div></div>';
    }

    // ---------------------------------------------------------------- componentes de formulário (HTML sempre escapado)

    /**
     * Campo com `<label>` visível e associado (`for`/`id`), ajuda abaixo e `mb-3`. `$tipo`: `text`, `password`, `email`, `number`,
     * `textarea` ou `select` (neste caso `$opcoes['opcoes']` = valor => rótulo). Opções: `ajuda` (HTML já escapado), `obrigatorio`,
     * `maxlength`, `autocomplete`, `inputmode`, `min`, `max`, `linhas`, `classe` (do controle), `grupo` (classe do `div`), `pattern`, `autofocus`.
     *
     * @param array<string, mixed> $o
     */
    public static function campo(string $id, string $nome, string $rotulo, string $valor = '', array $o = []): string
    {
        $tipo = is_string($o['tipo'] ?? null) ? $o['tipo'] : 'text';
        $ajuda = is_string($o['ajuda'] ?? null) ? $o['ajuda'] : '';
        $attrs = ' id="' . self::h($id) . '" name="' . self::h($nome) . '"'
            . ($ajuda === '' ? '' : ' aria-describedby="' . self::h($id) . '-ajuda"')
            . (($o['obrigatorio'] ?? false) ? ' required' : '')
            . (($o['autofocus'] ?? false) ? ' autofocus' : '');
        foreach (['maxlength', 'autocomplete', 'inputmode', 'min', 'max', 'pattern', 'step'] as $a) {
            if (isset($o[$a]) && (is_string($o[$a]) || is_int($o[$a]))) {
                $attrs .= ' ' . $a . '="' . self::h((string) $o[$a]) . '"';
            }
        }
        $classe = is_string($o['classe'] ?? null) ? ' ' . $o['classe'] : '';
        if ($tipo === 'textarea') {
            $controle = '<textarea class="form-control' . $classe . '"' . $attrs . ' rows="' . (is_int($o['linhas'] ?? null) ? $o['linhas'] : 4) . '">' . self::h($valor) . '</textarea>';
        } elseif ($tipo === 'select') {
            $controle = '<select class="form-select' . $classe . '"' . $attrs . '>';
            $opcoes = is_array($o['opcoes'] ?? null) ? $o['opcoes'] : [];
            foreach ($opcoes as $v => $texto) {
                $controle .= '<option value="' . self::h((string) $v) . '"' . ((string) $v === $valor ? ' selected' : '') . '>' . self::h((string) $texto) . '</option>';
            }
            $controle .= '</select>';
        } else {
            $controle = '<input type="' . self::h($tipo) . '" class="form-control' . $classe . '"' . $attrs . ' value="' . self::h($valor) . '">';
        }

        return '<div class="' . (is_string($o['grupo'] ?? null) ? $o['grupo'] : 'mb-3') . '"><label for="' . self::h($id) . '" class="form-label">' . self::h($rotulo) . '</label>'
            . $controle . ($ajuda === '' ? '' : '<div id="' . self::h($id) . '-ajuda" class="form-text">' . $ajuda . '</div>') . '</div>';
    }

    /**
     * Caixa de marcar (`form-check`: input antes do label, `for`/`id` únicos). `$rotulo` é texto puro, salvo `$rotuloHtml` (HTML já
     * escapado); `$ajuda` é HTML já escapado.
     */
    public static function checkbox(string $id, string $nome, string $rotulo, bool $marcado, string $ajuda = '', string $valor = '1', bool $rotuloHtml = false, string $tipo = 'checkbox'): string
    {
        return '<div class="form-check"><input class="form-check-input" type="' . $tipo . '" id="' . self::h($id) . '" name="' . self::h($nome) . '" value="' . self::h($valor) . '"'
            . ($marcado ? ' checked' : '') . ($ajuda === '' ? '' : ' aria-describedby="' . self::h($id) . '-ajuda"') . '>'
            . '<label class="form-check-label" for="' . self::h($id) . '">' . ($rotuloHtml ? $rotulo : self::h($rotulo)) . '</label>'
            . ($ajuda === '' ? '' : '<div id="' . self::h($id) . '-ajuda" class="form-text w-100">' . $ajuda . '</div>') . '</div>';
    }

    /** Quadro com título (`<fieldset><legend>` por padrão; `$tag = 'section'` para quadro sem campos). Título em texto puro, corpo em HTML. */
    public static function card(string $titulo, string $corpo, string $ajuda = '', string $tag = 'fieldset', string $classe = ''): string
    {
        $cab = $tag === 'fieldset' ? '<legend>' . self::h($titulo) . '</legend>' : '<h2 class="pbl-card-titulo">' . self::h($titulo) . '</h2>';

        return '<' . $tag . ' class="pbl-card' . ($classe === '' ? '' : ' ' . $classe) . '">' . $cab
            . ($ajuda === '' ? '' : '<p class="form-text mt-0">' . $ajuda . '</p>') . $corpo . '</' . $tag . '>';
    }

    /** Aviso Bootstrap: `$tipo` = danger, success, warning ou info; erro/aviso usam `role="alert"`, o resto `role="status"`. `$texto` é texto puro. */
    public static function alerta(string $tipo, string $texto, string $id = '', bool $html = false): string
    {
        return '<div' . ($id === '' ? '' : ' id="' . self::h($id) . '"') . ' class="alert alert-' . self::h($tipo) . '" role="' . (in_array($tipo, ['danger', 'warning'], true) ? 'alert' : 'status') . '">'
            . ($html ? $texto : self::h($texto)) . '</div>';
    }

    /**
     * Linha de botões de um formulário: o primário (`<button type="submit">`), os secundários (links em outline, `[rótulo, href]`) e,
     * afastado, um destrutivo opcional (`[rótulo, href]`, link em vermelho). `$primario` é o rótulo do botão de envio.
     *
     * @param list<array{0: string, 1: string}> $secundarios
     * @param array{0: string, 1: string}|null $destrutivo
     */
    public static function acoes(string $primario, array $secundarios = [], ?array $destrutivo = null, string $classePrimario = 'btn-primary'): string
    {
        $html = '<div class="pbl-acoes">' . ($primario === '' ? '' : '<button type="submit" class="btn ' . self::h($classePrimario) . '">' . self::h($primario) . '</button>');
        foreach ($secundarios as [$rotulo, $href]) {
            $html .= '<a class="btn btn-outline-secondary" href="' . self::h($href) . '">' . self::h($rotulo) . '</a>';
        }
        if ($destrutivo !== null) {
            $html .= '<a class="btn btn-outline-danger pbl-acao-destrutiva" href="' . self::h($destrutivo[1]) . '">' . self::h($destrutivo[0]) . '</a>';
        }

        return $html . '</div>';
    }

    /** Cabeçalhos de segurança das páginas de administração (ADR 0011 e 0012). `$seguro` = a conexão é HTTPS (liga o HSTS). */
    public static function protegida(Response $r, bool $seguro): Response
    {
        $r->headers->set('Cache-Control', 'no-store');
        $r->headers->set('X-Frame-Options', 'DENY');
        $r->headers->set('X-Content-Type-Options', 'nosniff');
        $r->headers->set('Referrer-Policy', 'same-origin');
        // img-src data: só porque o CSS do Bootstrap desenha alguns ícones de formulário como SVG embutido.
        $r->headers->set('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        if ($seguro) {
            $r->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $r;
    }
}
