<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Versao;
use Symfony\Component\HttpFoundation\Request;
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
    /** Editor de texto rico (TinyMCE 6.8, MIT, hospedado aqui; ADR 0020). Só é carregado em telas com campo `data-pbl-editor`. */
    public const TINYMCE = self::ASSETS . '/tinymce-6.8.6';
    public const TINYMCE_JS = self::TINYMCE . '/tinymce.min.js';
    public const EDITOR_JS = self::ASSETS . '/pbl-editor.js';
    /** Marca que as telas põem no `<textarea>` de um campo `html`; a página usa para decidir carregar o editor. */
    public const MARCA_EDITOR = 'data-pbl-editor="';
    public const MARCA_PREVIA = 'data-pbl-previa="';

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
        self::$revisao = [0, false, false];
    }

    /** Fluxo de revisão da conta em curso: [pendências na fila, é revisor, envia conteúdo]. Definido por `Painel` depois do login; a barra mostra os links "Pendências" e "Meus envios" conforme o papel. */
    /** @var array{0: int, 1: bool, 2: bool} */
    private static array $revisao = [0, false, false];

    public static function definirRevisao(int $pendencias, bool $revisor, bool $envia): void
    {
        self::$revisao = [$pendencias, $revisor, $envia];
    }

    /**
     * `$navegacao` (HTML já escapado, em geral uma `<nav>`) vai num `<header>` antes do `<main>`; o título vira o `<h1>`.
     * `$migalhas` (lista de `[rótulo, href|null]`, o último sem href = tela atual) vira o caminho de volta acima do título;
     * `$descricao` (HTML já escapado) é uma frase curta abaixo dele; `$ajudaDaTela` (HTML já escapado) é a explicação longa da tela, atrás de um "?" ao lado do título.
     *
     * @param list<array{0: string, 1: string|null}> $migalhas
     */
    public static function resposta(string $titulo, string $corpo, int $status = Response::HTTP_OK, bool $seguro = false, string $navegacao = '', array $migalhas = [], string $descricao = '', string $ajudaDaTela = ''): Response
    {
        $editor = str_contains($corpo, self::MARCA_EDITOR);
        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
            . '<meta name="robots" content="noindex"><title>' . self::h($titulo) . ' — PBL4</title>'
            . '<link rel="stylesheet" href="' . self::urlVersionada(self::BOOTSTRAP) . '"><link rel="stylesheet" href="' . self::urlVersionada(self::CSS) . '">'
            . '<script src="' . self::urlVersionada(self::BOOTSTRAP_JS) . '" defer></script><script src="' . self::urlVersionada(self::JS) . '" defer></script>'
            . ($editor ? '<script src="' . self::urlVersionada(self::TINYMCE_JS) . '" defer></script><script src="' . self::urlVersionada(self::EDITOR_JS) . '" defer></script>' : '')
            . '</head><body><a class="visually-hidden-focusable" href="#conteudo">Pular para o conteúdo</a>'
            . ($navegacao === '' ? '' : '<header>' . $navegacao . '</header>')
            . '<main id="conteudo" class="container py-3 py-md-4">' . self::migalhas($migalhas)
            . ($ajudaDaTela === '' ? '<h1 class="h3 mb-2">' . self::h($titulo) . '</h1>' : '<div class="pbl-rotulo"><h1 class="h3 mb-2">' . self::h($titulo) . '</h1>' . self::ajuda('ajuda-tela', $titulo, $ajudaDaTela) . '</div>')
            . ($descricao === '' ? '<div class="mb-4"></div>' : '<p class="text-body-secondary mb-4">' . $descricao . '</p>')
            . self::tabelasEmCartoes($corpo) . '</main></body></html>';

        return self::protegida(new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']), $seguro, $editor);
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
            str_starts_with($caminho, '/_pbl/classes'), str_starts_with($caminho, '/_pbl/modelos') => 'classes',
            str_starts_with($caminho, '/_pbl/conteudo') => 'conteudo',
            str_starts_with($caminho, '/_pbl/contas') => 'contas',
            str_starts_with($caminho, '/_pbl/atualizacao') => 'atualizacao',
            str_starts_with($caminho, '/_pbl/tarefas') => 'tarefas',
            str_starts_with($caminho, '/_pbl/pendencias') => 'pendencias',
            str_starts_with($caminho, '/_pbl/meus-envios') => 'envios',
            default => 'inicio',
        };
        [$pendencias, $revisor, $envia] = self::$revisao;
        $itens = ['inicio' => ['/_pbl/', 'Sites e conteúdo']];
        $itens['conteudo'] = ['/_pbl/conteudo', 'Conteúdo'];
        if ($revisor) {
            $itens['pendencias'] = ['/_pbl/pendencias', 'Pendências' . ($pendencias > 0 ? ' <span class="badge text-bg-warning" title="Aguardando revisão">' . $pendencias . '</span>' : '')];
        }
        if ($envia) {
            $itens['envios'] = ['/_pbl/meus-envios', 'Meus envios'];
        }
        $itens['classes'] = ['/_pbl/classes', 'Tipos de conteúdo'];
        if ($administrador) {
            $itens['contas'] = ['/_pbl/contas', 'Contas'];
            $itens['atualizacao'] = ['/_pbl/atualizacao', 'Atualização'];
            $itens['tarefas'] = ['/_pbl/tarefas', 'Tarefas agendadas'];
        }
        $links = '';
        foreach ($itens as $chave => [$href, $rotulo]) {
            $links .= '<li class="nav-item"><a class="nav-link' . ($chave === $secao ? ' active' : '') . '" href="' . $href . '"' . ($chave === $secao ? ' aria-current="page"' : '') . '>' . $rotulo . '</a></li>';
        }

        return '<nav class="navbar navbar-expand-xl bg-dark pbl-barra" data-bs-theme="dark" aria-label="Administração"><div class="container">'
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

    /**
     * Abas do Bootstrap (`nav-tabs`, WAI-ARIA tablist). O servidor já entrega a aba escolhida com `active show` e todos os painéis no
     * HTML; sem JavaScript os painéis ficam empilhados, cada um com o seu título (a barra de abas nasce `hidden`). O `pbl-admin.js`
     * (`data-pbl-abas`) mostra a barra, esconde os títulos e põe `tab-content`/`tab-pane`, que é o que faz o Bootstrap esconder
     * os painéis inativos. Avisos e erros entram dentro do painel ativo. `$rotuloDaBarra` = nome acessível da lista de abas.
     *
     * @param array<string, array{0: string, 1: string}> $abas chave => [rótulo, HTML do painel]
     */
    public static function abas(array $abas, string $ativa, string $avisos, string $rotuloDaBarra): string
    {
        $nav = '<ul class="nav nav-tabs flex-nowrap overflow-x-auto mb-3" role="tablist" aria-label="' . self::h($rotuloDaBarra) . '" hidden data-pbl-abas-nav>';
        $paineis = '';
        foreach ($abas as $chave => [$rotulo, $html]) {
            $on = $chave === $ativa;
            $nav .= '<li class="nav-item" role="presentation"><button type="button" id="aba-' . $chave . '" class="nav-link text-nowrap' . ($on ? ' active' : '') . '" role="tab" data-bs-toggle="tab" data-bs-target="#painel-' . $chave . '" data-pbl-aba="' . $chave . '" aria-controls="painel-' . $chave . '" aria-selected="' . ($on ? 'true' : 'false') . '"' . ($on ? '' : ' tabindex="-1"') . '>' . self::h($rotulo) . '</button></li>';
            $paineis .= '<div id="painel-' . $chave . '" class="pbl-aba-painel mb-4' . ($on ? ' active show' : '') . '" role="tabpanel" aria-labelledby="aba-' . $chave . '" tabindex="0">'
                . '<h2 class="h5 pbl-aba-titulo">' . self::h($rotulo) . '</h2>' . ($on ? $avisos : '') . $html . '</div>';
        }

        return '<div data-pbl-abas>' . $nav . '</ul><div data-pbl-abas-paineis>' . $paineis . '</div></div>';
    }

    /**
     * A aba pedida em `?aba=` (ou `$padrao`). Com `$validas`, qualquer outra chave volta ao padrão.
     *
     * @param list<string>|null $validas
     */
    public static function abaDe(Request $requisicao, string $padrao, ?array $validas = null): string
    {
        $aba = $requisicao->query->all()['aba'] ?? null;
        if (!is_string($aba)) {
            return $padrao;
        }

        return $validas === null || in_array($aba, $validas, true) ? $aba : $padrao;
    }

    // ---------------------------------------------------------------- componentes de formulário (HTML sempre escapado)

    /**
     * Ajuda de um campo, bloco ou tela atrás de um botão "?" (collapse do Bootstrap local, sem JavaScript inline): o botão leva
     * `aria-expanded`/`aria-controls`, funciona por teclado e tem nome acessível ("Ajuda: assunto"); o painel (`role="note"`) tem o
     * `id` pedido, então o campo pode apontá-lo em `aria-describedby` e o leitor de tela lê o texto mesmo recolhido. Devolve botão +
     * painel: ponha-os logo depois do rótulo/título, dentro de um `.pbl-rotulo` (o painel ocupa a linha de baixo).
     * `$id` único na página; `$assunto` é texto puro (nome do campo); `$html` é HTML já escapado.
     */
    public static function ajuda(string $id, string $assunto, string $html): string
    {
        return self::ajudaBotao($id, $assunto, $html) . self::ajudaPainel($id, $html);
    }

    public static function ajudaBotao(string $id, string $assunto, string $html): string
    {
        if ($html === '') {
            return '';
        }
        $i = self::h($id);

        return '<button type="button" class="btn btn-sm btn-outline-secondary pbl-ajuda-botao" data-bs-toggle="collapse" data-bs-target="#' . $i . '" aria-expanded="false" aria-controls="' . $i . '" aria-label="Ajuda: ' . self::h($assunto) . '"><span aria-hidden="true">?</span></button>';
    }

    public static function ajudaPainel(string $id, string $html): string
    {
        return $html === '' ? '' : '<div id="' . self::h($id) . '" class="collapse pbl-ajuda-painel form-text" role="note">' . $html . '</div>';
    }

    /** Linha do rótulo (`<label>`, título) com o "?" de ajuda ao lado; `$rotuloHtml` já escapado. */
    public static function rotulo(string $paraId, string $rotuloHtml, string $ajuda = '', string $classe = 'form-label'): string
    {
        return '<div class="pbl-rotulo"><label for="' . self::h($paraId) . '" class="' . self::h($classe) . '">' . $rotuloHtml . '</label>' . $ajuda . '</div>';
    }

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

        return '<div class="' . (is_string($o['grupo'] ?? null) ? $o['grupo'] : 'mb-3') . '">' . self::rotulo($id, self::h($rotulo), self::ajuda($id . '-ajuda', $rotulo, $ajuda))
            . $controle . '</div>';
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
            . self::ajuda($id . '-ajuda', $rotuloHtml ? trim(html_entity_decode(strip_tags($rotulo), ENT_QUOTES, 'UTF-8')) : $rotulo, $ajuda) . '</div>';
    }

    /** Quadro com título (`<fieldset><legend>` por padrão; `$tag = 'section'` para quadro sem campos). Título em texto puro, corpo em HTML. */
    public static function card(string $titulo, string $corpo, string $ajuda = '', string $tag = 'fieldset', string $classe = ''): string
    {
        static $n = 0;
        $id = 'ajuda-bloco-' . (++$n);
        $botao = self::ajudaBotao($id, $titulo, $ajuda);
        $rot = self::h($titulo) . ($botao === '' ? '' : ' ' . $botao);
        $cab = $tag === 'fieldset' ? '<legend>' . $rot . '</legend>' : '<h2 class="pbl-card-titulo">' . $rot . '</h2>';

        return '<' . $tag . ' class="pbl-card' . ($classe === '' ? '' : ' ' . $classe) . '">' . $cab . self::ajudaPainel($id, $ajuda) . $corpo . '</' . $tag . '>';
    }

    /** Aviso Bootstrap: `$tipo` = danger, success, warning ou info; erro/aviso usam `role="alert"`, o resto `role="status"`. `$texto` é texto puro. */
    public static function alerta(string $tipo, string $texto, string $id = '', bool $html = false): string
    {
        return '<div' . ($id === '' ? '' : ' id="' . self::h($id) . '"') . ' class="alert alert-' . self::h($tipo) . '" role="' . (in_array($tipo, ['danger', 'warning'], true) ? 'alert' : 'status') . '">'
            . ($html ? $texto : self::h($texto)) . '</div>';
    }

    /**
     * Linha de botões de um formulário: o primário (`<button type="submit">`), os secundários (links em outline, `[rótulo, href]`) e,
     * afastado, um destrutivo opcional (`[rótulo, href]`, link em vermelho). `$primario` é o rótulo do botão de envio. `$classeBarra` = classe extra (ex.: `pbl-acoes-fixas`, barra fixa no rodapé do celular).
     *
     * @param list<array{0: string, 1: string}> $secundarios
     * @param array{0: string, 1: string}|null $destrutivo
     * @param string $gravarEPublicar rótulo do segundo botão de envio (verde); vazio: sem ele
     */
    public static function acoes(string $primario, array $secundarios = [], ?array $destrutivo = null, string $classePrimario = 'btn-primary', string $classeBarra = '', string $gravarEPublicar = ''): string
    {
        $html = '<div class="pbl-acoes' . ($classeBarra === '' ? '' : ' ' . self::h($classeBarra)) . '">' . ($primario === '' ? '' : '<button type="submit" class="btn ' . self::h($classePrimario) . '">' . self::h($primario) . '</button>');
        // Segundo envio do mesmo formulário (ADR 0022): `acao=publicar` grava e muda o estado para publicado. Só para quem pode publicar.
        if ($gravarEPublicar !== '') {
            $html .= '<button type="submit" name="acao" value="publicar" class="btn btn-success bg-gradient">' . self::h($gravarEPublicar) . '</button>';
        }
        foreach ($secundarios as [$rotulo, $href]) {
            $html .= '<a class="btn btn-outline-secondary" href="' . self::h($href) . '">' . self::h($rotulo) . '</a>';
        }
        if ($destrutivo !== null) {
            $html .= '<a class="btn btn-outline-danger pbl-acao-destrutiva" href="' . self::h($destrutivo[1]) . '">' . self::h($destrutivo[0]) . '</a>';
        }

        return $html . '</div>';
    }

    /**
     * Cabeçalhos de segurança das páginas de administração (ADR 0011 e 0012). `$seguro` = a conexão é HTTPS (liga o HSTS).
     * `$editor` = a página tem o editor de texto rico (ADR 0020): o TinyMCE aplica estilos em linha (alinhamento, tamanho) no
     * conteúdo editado e mostra imagens por endereço https; por isso, só nessas páginas, `style-src` aceita `'unsafe-inline'` e
     * `img-src` aceita `https:`. `script-src` continua `'self'`: nenhum script inline ou de fora em lugar nenhum.
     */
    public static function protegida(Response $r, bool $seguro, bool $editor = false): Response
    {
        // O painel passa por aqui de novo depois da tela: a página que traz o editor é reconhecida pela marca no corpo.
        $html = str_starts_with((string) $r->headers->get('Content-Type'), 'text/html') ? (string) $r->getContent() : '';
        $editor = $editor || ($html !== '' && (str_contains($html, self::MARCA_EDITOR) || str_contains($html, self::MARCA_PREVIA)));
        $r->headers->set('Cache-Control', 'no-store');
        $r->headers->set('X-Frame-Options', 'DENY');
        $r->headers->set('X-Content-Type-Options', 'nosniff');
        $r->headers->set('Referrer-Policy', 'same-origin');
        // img-src data: só porque o CSS do Bootstrap desenha alguns ícones de formulário como SVG embutido.
        $r->headers->set('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self'" . ($editor ? " 'unsafe-inline'" : '') . "; img-src 'self' data:" . ($editor ? ' https:' : '') . "; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        if ($seguro) {
            $r->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $r;
    }
}
