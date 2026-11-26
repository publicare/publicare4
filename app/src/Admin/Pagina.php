<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Conteudo\ConfigDoSite;
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
    /** Sistema de formulário e casca do painel (ADR 0031): tokens, tema claro e escuro, menu, abas, interruptores e barra de ações. */
    public const FORMULARIO_CSS = self::ASSETS . '/pbl-formulario.css';
    /** Marca o `<html>` com `js` e aplica o tema antes de pintar; arquivo minúsculo, sem `defer`, nunca inline (CSP). */
    public const BASE_JS = self::ASSETS . '/pbl-js.js';
    /** Melhoria progressiva dos formulários (abas, interruptores, validação ao vivo, contador, prévias, seletores); só nas telas com `data-pbl-form`. */
    public const FORMULARIO_JS = self::ASSETS . '/pbl-formulario.js';
    public const MARCA_FORMULARIO = 'data-pbl-form';
    /** Telas com campo de imagem: só nelas a CSP aceita `blob:` em `img-src` (miniatura do arquivo escolhido). */
    public const MARCA_IMAGEM = 'data-pbl-imagem';
    /** Scripts da administração: só arquivos do próprio site (Bootstrap local + `pbl-admin.js`), nunca inline (CSP `script-src 'self'`). */
    public const BOOTSTRAP_JS = self::ASSETS . '/bootstrap-5.3.8/bootstrap.bundle.min.js';
    public const JS = self::ASSETS . '/pbl-admin.js';
    /** Editor de texto rico (TinyMCE 6.8, MIT, hospedado aqui; ADR 0020). Só é carregado em telas com campo `data-pbl-editor`. */
    public const TINYMCE = self::ASSETS . '/tinymce-6.8.6';
    public const TINYMCE_JS = self::TINYMCE . '/tinymce.min.js';
    public const EDITOR_JS = self::ASSETS . '/pbl-editor.js';
    /** Marca que as telas põem no `<textarea>` de um campo `html`; a página usa para decidir carregar o editor. */
    public const MARCA_EDITOR = 'data-pbl-editor="';
    /** Passos do assistente de instalação (só carregado na tela que traz `data-pbl-instalar`). */
    public const INSTALAR_JS = self::ASSETS . '/pbl-instalar.js';
    public const MARCA_INSTALAR = 'data-pbl-instalar';
    /** Andamento da atualização (só carregado na tela que traz `data-pbl-progresso`). */
    public const PROGRESSO_JS = self::ASSETS . '/pbl-atualizacao-progresso.js';
    public const MARCA_PROGRESSO = 'data-pbl-progresso ';
    /** Barra de "trabalhando" das cópias de segurança (só carregado na tela que traz `data-pbl-backup-form`). */
    public const BACKUP_JS = self::ASSETS . '/pbl-backup.js';
    public const MARCA_BACKUP = 'data-pbl-backup-form';
    public const MARCA_PREVIA = 'data-pbl-previa="';

    public static function h(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Ícones dos botões de ação (traço simples, 24x24, estilo Feather/Lucide). Os seis primeiros são os mesmos da barra de edição pública
     * (`pbl-barra.js`); o mapa é o único lugar do painel que desenha ícone de botão.
     */
    public const ICONES = [
        'conteudo' => 'M8 6h13 M8 12h13 M8 18h13 M3 6h.01 M3 12h.01 M3 18h.01',
        'editar' => 'M12 20h9 M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
        'publicar' => 'M20 6 9 17l-5-5',
        'despublicar' => 'M8 5v14 M16 5v14',
        'mover' => 'M7 4v16 M3 8l4-4 4 4 M17 20V4 M13 16l4 4 4-4',
        'excluir' => 'M3 6h18 M8 6V4h8v2 M6 6l1 14h10l1-14 M10 11v6 M14 11v6',
        'abrir' => 'M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z',
        'detalhes' => 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z',
        'externo' => 'M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6 M15 3h6v6 M10 14 21 3',
        'mais' => 'M12 5v14 M5 12h14',
        'copiar' => 'M11 9h9a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-9a2 2 0 0 1-2-2v-9a2 2 0 0 1 2-2z M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1',
        'recortar' => 'M6 3a3 3 0 1 0 0 6 3 3 0 0 0 0-6z M6 15a3 3 0 1 0 0 6 3 3 0 0 0 0-6z M20 4 8.12 15.88 M14.47 14.48 20 20 M8.12 8.12 12 12',
        'colar' => 'M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2 M9 2h6a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z',
        'tirar' => 'M18 6 6 18 M6 6l12 12',
        'restaurar' => 'M1 4v6h6 M3.51 15a9 9 0 1 0 2.13-9.36L1 10',
        'historico' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z M12 7v5l3 2',
        'log' => 'M4 6h16 M4 12h16 M4 18h10',
        'qr' => 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h3v3h-3z M20 14v7 M14 20h3',
        'arquivos' => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z M14 2v6h6 M16 13H8 M16 17H8',
        'aprovar' => 'M20 6 9 17l-5-5',
        'devolver' => 'M9 14 4 9l5-5 M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5 5.5 5.5 0 0 1-5.5 5.5H11',
        'enviar' => 'M22 2 11 13 M22 2l-7 20-4-9-9-4z',
        'retirar' => 'M19 12H5 M12 19l-7-7 7-7',
        'ativar' => 'M22 11.08V12a10 10 0 1 1-5.93-9.14 M22 4 12 14.01l-3-3',
        'executar' => 'M5 3l14 9-14 9z',
        'principal' => 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z',
        'estado-ok' => 'M22 11.08V12a10 10 0 1 1-5.93-9.14 M22 4 12 14.01l-3-3',
        'estado-atencao' => 'M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z M12 9v4 M12 17h.01',
        'estado-erro' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z M15 9l-6 6 M9 9l6 6',
        'estado-andamento' => 'M21 12a9 9 0 1 1-6.22-8.56',
        'alternar' => 'M17 1l4 4-4 4 M3 11V9a4 4 0 0 1 4-4h14 M7 23l-4-4 4-4 M21 13v2a4 4 0 0 1-4 4H3',
    ];

    /** `<svg>` inline de um ícone de `ICONES` (16px, `currentColor`, decorativo). Nome desconhecido: vazio. */
    public static function icone(string $nome): string
    {
        if (!isset(self::ICONES[$nome])) {
            return '';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . self::ICONES[$nome] . '"/></svg>';
    }

    /**
     * Botão de ação só com ícone (modelo da barra de edição pública). Com `href` vira `<a>`; sem ele, `<button type="submit">`.
     * O rótulo continua no HTML em `visually-hidden` (leitor de tela e busca), no `title` e, com `item`, no `aria-label`
     * ("Editar: Título"). `$classe` = cor por função (`btn-primary bg-gradient`, `btn-outline-danger`...).
     *
     * @param array{href?: string, item?: string, extra?: string, externo?: bool, tamanho?: string} $o `extra` = atributos já escapados (ex.: `name="modo" value="copiar"`)
     */
    public static function botaoIcone(string $icone, string $rotulo, string $classe, array $o = []): string
    {
        $item = $o['item'] ?? '';
        $tamanho = $o['tamanho'] ?? 'btn-sm';
        $completo = $rotulo . ($item === '' ? '' : ': ' . $item);
        $attrs = ' class="btn ' . ($tamanho === '' ? '' : $tamanho . ' ') . self::h($classe) . ' pbl-btn-icone" title="' . self::h($rotulo) . '"'
            . ($item === '' ? '' : ' aria-label="' . self::h($completo) . '"') . (($o['extra'] ?? '') === '' ? '' : ' ' . $o['extra']);
        $miolo = self::icone($icone) . '<span class="visually-hidden">' . self::h($item === '' ? $rotulo : $rotulo . ' ' . $item) . '</span>';
        if (isset($o['href'])) {
            return '<a' . $attrs . ' href="' . self::h($o['href']) . '"' . (($o['externo'] ?? false) ? ' target="_blank" rel="noopener"' : '') . '>' . $miolo . '</a>';
        }

        return '<button type="submit"' . $attrs . '>' . $miolo . '</button>';
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

    /** Linha de aviso da demo (HTML já escapado) no topo de toda tela do painel; vazia fora de demo. Definida por `Painel`. */
    private static string $avisoDeDemo = '';

    public static function definirAvisoDeDemo(string $html): void
    {
        self::$avisoDeDemo = $html;
    }

    public static function definirRevisao(int $pendencias, bool $revisor, bool $envia): void
    {
        self::$revisao = [$pendencias, $revisor, $envia];
    }

    /** Site que a conta administra (primeiro, para o item "Site" do menu); null = não administra nenhum. Definido por `Painel`. */
    private static ?int $siteDoMenu = null;
    private static bool $administradorDoMenu = false;

    public static function definirMenu(bool $administrador, ?int $siteId): void
    {
        self::$administradorDoMenu = $administrador;
        self::$siteDoMenu = $siteId;
        if ($siteId === null) {
            self::$manualUrl = ConfigDoSite::MANUAL_URL_PADRAO;
        }
    }

    /** Endereço do manual (config `manual_url` do site em contexto); sem site, o padrão. Definido por `Painel`. */
    private static string $manualUrl = ConfigDoSite::MANUAL_URL_PADRAO;

    public static function definirManual(string $url): void
    {
        self::$manualUrl = ConfigDoSite::normalizar('manual_url', $url) ?? ConfigDoSite::MANUAL_URL_PADRAO;
    }

    /** O link "Ajuda (manual)": nova aba, sem referência; o nome acessível avisa. Nenhuma chamada de rede. */
    public static function linkDoManual(): string
    {
        return '<a class="pbl-ajuda" href="' . self::h(self::$manualUrl) . '" target="_blank" rel="noopener noreferrer">Ajuda (manual)<span class="visually-hidden"> (abre em nova aba)</span></a>';
    }

    /** Ajuda do campo de nova senha (perfil, recuperação e troca da senha provisória). */
    public const AJUDA_DE_SENHA = 'No mínimo 10 caracteres, nada óbvio (como "senha123456"). Tamanho vale mais que símbolos.';

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
        $instalar = str_contains($corpo, '<form method="post" action="/_pbl/instalar"') && str_contains($corpo, self::MARCA_INSTALAR);
        $progresso = str_contains($corpo, self::MARCA_PROGRESSO);
        $backup = str_contains($corpo, self::MARCA_BACKUP);
        $formulario = str_contains($corpo, self::MARCA_FORMULARIO);
        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
            . '<meta name="robots" content="noindex"><meta name="color-scheme" content="light dark"><title>' . self::h($titulo) . ' — PBL4</title>'
            . '<link rel="stylesheet" href="' . self::urlVersionada(self::BOOTSTRAP) . '"><link rel="stylesheet" href="' . self::urlVersionada(self::CSS) . '">'
            . '<link rel="stylesheet" href="' . self::urlVersionada(self::FORMULARIO_CSS) . '">'
            . '<script src="' . self::urlVersionada(self::BASE_JS) . '"></script>'
            . '<script src="' . self::urlVersionada(self::BOOTSTRAP_JS) . '" defer></script><script src="' . self::urlVersionada(self::JS) . '" defer></script>'
            . ($formulario ? '<script src="' . self::urlVersionada(self::FORMULARIO_JS) . '" defer></script>' : '')
            . ($instalar ? '<script src="' . self::urlVersionada(self::INSTALAR_JS) . '" defer></script>' : '')
            . ($progresso ? '<script src="' . self::urlVersionada(self::PROGRESSO_JS) . '" defer></script>' : '')
            . ($backup ? '<script src="' . self::urlVersionada(self::BACKUP_JS) . '" defer></script>' : '')
            . ($editor ? '<script src="' . self::urlVersionada(self::TINYMCE_JS) . '" defer></script><script src="' . self::urlVersionada(self::EDITOR_JS) . '" defer></script>' : '')
            . '</head><body>' . self::$avisoDeDemo . '<a class="visually-hidden-focusable" href="#conteudo">Pular para o conteúdo</a>'
            . '<div class="pbl-app' . ($navegacao === '' ? ' pbl-sem-menu' : '') . '">'
            . ($navegacao === '' ? '' : '<header class="pbl-lateral">' . $navegacao . '</header>')
            . '<main id="conteudo" class="pbl-principal" tabindex="-1">' . self::migalhas($migalhas)
            . ($ajudaDaTela === '' ? '<h1 class="h3 mb-2">' . self::h($titulo) . '</h1>' : '<div class="pbl-rotulo"><h1 class="h3 mb-2">' . self::h($titulo) . '</h1>' . self::ajuda('ajuda-tela', $titulo, $ajudaDaTela) . '</div>')
            . ($descricao === '' ? '<div class="mb-4"></div>' : '<p class="text-body-secondary mb-4">' . $descricao . '</p>')
            . self::tabelasEmCartoes($corpo) . '</main></div></body></html>';

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
     * Item do menu que a rota ocupa: o mapa de prefixos é o único lugar que decide, e `/objeto/…` e `/classe…` deixam de cair em
     * "Início". Telas novas registram o prefixo aqui. Valores: inicio, conteudo, revisar, vencidos, lixeira, pilha, tipos, peles,
     * site-config, site-dominios, site-redirecionamentos, site-menu, contas, perfil, email, atualizacao, copias, tarefas, mais.
     */
    public static function itemAtivo(string $caminho): string
    {
        $caminho = rtrim($caminho, '/');
        if (preg_match('#^/_pbl/site/\d+/([a-z-]+)#', $caminho, $m) === 1) {
            return match ($m[1]) {
                'configuracoes' => 'site-config', 'dominios' => 'site-dominios', 'redirecionamentos' => 'site-redirecionamentos',
                'peles' => 'peles', 'publicacao' => 'site-menu', default => 'site-config',
            };
        }
        $mapa = [
            'conteudo' => ['/_pbl/conteudo', '/_pbl/objeto'],
            'vencidos' => ['/_pbl/vencidos'], 'lixeira' => ['/_pbl/lixeira'], 'pilha' => ['/_pbl/pilha'],
            'revisar' => ['/_pbl/revisar', '/_pbl/pendencias', '/_pbl/meus-envios', '/_pbl/revisao'],
            'tipos' => ['/_pbl/classes', '/_pbl/classe'], 'peles' => ['/_pbl/modelos'],
            'contas' => ['/_pbl/contas', '/_pbl/conta'], 'perfil' => ['/_pbl/perfil'], 'email' => ['/_pbl/email'],
            'atualizacao' => ['/_pbl/atualizacao'], 'copias' => ['/_pbl/copias'], 'tarefas' => ['/_pbl/tarefas'],
            'mais' => ['/_pbl/mais'],
        ];
        foreach ($mapa as $item => $prefixos) {
            foreach ($prefixos as $prefixo) {
                if ($caminho === $prefixo || str_starts_with($caminho, $prefixo . '/')) {
                    return $item;
                }
            }
        }

        return 'inicio';
    }

    /**
     * Menu agrupado por seção (ADR 0031, revisado): Início e seis grupos (Conteúdo, Estrutura, Site, Pessoas, Sistema, Ajuda). Cada grupo
     * só traz o que a pessoa pode abrir; grupo vazio some. Funciona sem JavaScript: é um `<details open>` e o botão "Menu" só aparece
     * abaixo de 1024 px (o `pbl-admin.js` recolhe o menu no celular). Acima disso o menu é uma coluna fixa com rolagem própria. A tela
     * atual leva `aria-current="page"`. `$nome` e `$tokenCsrf` chegam sem escape.
     */
    public static function barra(string $nome, string $tokenCsrf, bool $administrador = false): string
    {
        $administrador = $administrador || self::$administradorDoMenu;
        $ativo = self::itemAtivo(self::$caminhoAtual);
        [$pendencias, $revisor, $envia] = self::$revisao;
        $edita = $administrador || $revisor || $envia;
        $site = self::$siteDoMenu;
        $base = $site === null ? null : '/_pbl/site/' . $site;
        $revisarRotulo = 'Revisar' . ($pendencias > 0 ? ' <span class="pbl-contagem" title="Aguardando revisão">' . $pendencias . '<span class="visually-hidden"> aguardando revisão</span></span>' : '');

        /** @var list<array{0: string, 1: list<array{0: string, 1: string, 2: string}>}> $grupos título => [chave, href, rótulo HTML] */
        $grupos = [];
        $conteudo = [['conteudo', '/_pbl/conteudo', 'Lista de conteúdo']];
        if ($revisor || $envia) {
            $conteudo[] = ['revisar', '/_pbl/revisar' . ($revisor ? '' : '?aba=envios'), $revisarRotulo];
        }
        if ($edita) {
            $conteudo[] = ['vencidos', '/_pbl/vencidos', 'Vencidos'];
            $conteudo[] = ['pilha', '/_pbl/pilha', 'Pilha'];
            $conteudo[] = ['lixeira', '/_pbl/lixeira', 'Lixeira'];
        }
        $grupos[] = ['Conteúdo', $conteudo];
        $estrutura = [['tipos', '/_pbl/classes', 'Tipos de conteúdo']];
        if ($administrador && $base !== null) {
            $estrutura[] = ['peles', $base . '/peles', 'Peles e modelos de página'];
        }
        $grupos[] = ['Estrutura', $estrutura];
        if ($base !== null) {
            $siteItens = [['site-config', $base . '/configuracoes', 'Configurações'], ['site-dominios', $base . '/dominios', 'Domínios']];
            if ($administrador) {
                $siteItens[] = ['site-redirecionamentos', $base . '/redirecionamentos', 'Redirecionamentos'];
                $siteItens[] = ['site-menu', $base . '/publicacao', 'Menu e publicação'];
            }
            $grupos[] = ['Site', $siteItens];
        }
        $pessoas = [['perfil', '/_pbl/perfil', 'Meu perfil']];
        if ($administrador) {
            array_unshift($pessoas, ['contas', '/_pbl/contas', 'Contas e papéis']);
        }
        $grupos[] = ['Pessoas', $pessoas];
        if ($administrador) {
            $grupos[] = ['Sistema', [
                ['email', '/_pbl/email', 'E-mail do site'], ['atualizacao', '/_pbl/atualizacao', 'Atualização'],
                ['copias', '/_pbl/copias', 'Cópias de segurança'], ['tarefas', '/_pbl/tarefas', 'Tarefas agendadas'],
            ]];
        }
        $link = static fn (string $chave, string $href, string $rotulo): string => '<li><a href="' . self::h($href) . '"' . ($chave === $ativo ? ' aria-current="page"' : '') . '>' . $rotulo . '</a></li>';
        $nav = '<ul class="pbl-itens pbl-itens-topo">' . $link('inicio', '/_pbl/', 'Início') . '</ul>';
        foreach ($grupos as $n => [$titulo, $itens]) {
            $nav .= '<section class="pbl-grupo" aria-labelledby="pbl-grupo-' . $n . '"><h2 class="pbl-grupo-titulo" id="pbl-grupo-' . $n . '">' . self::h($titulo) . '</h2><ul class="pbl-itens">';
            foreach ($itens as [$chave, $href, $rotulo]) {
                $nav .= $link($chave, $href, $rotulo);
            }
            $nav .= '</ul></section>';
        }
        $nav .= '<section class="pbl-grupo" aria-labelledby="pbl-grupo-ajuda"><h2 class="pbl-grupo-titulo" id="pbl-grupo-ajuda">Ajuda</h2><ul class="pbl-itens"><li>' . self::linkDoManual() . '</li></ul></section>';

        return '<div class="pbl-topo"><a class="pbl-marca" href="/_pbl/">PBL4</a></div>'
            . '<details class="pbl-menu" open data-pbl-menu><summary>Menu</summary>'
            . '<nav class="pbl-menu-nav" aria-label="Principal">' . $nav
            . '<div class="pbl-pessoa"><span class="pbl-nome">' . self::h($nome) . '</span>'
            . '<button type="button" class="btn btn-sm btn-outline-secondary pbl-tema" data-pbl-tema>Tema</button>'
            . '<form method="post" action="/_pbl/sair"><input type="hidden" name="_csrf" value="' . self::h($tokenCsrf) . '">'
            . '<button type="submit" class="btn btn-sm btn-outline-secondary">Sair</button></form></div></nav></details>';
    }

    /**
     * Cabeçalho de tela (ADR 0032): a linha de estado (uma frase) e UMA ação principal, mais atalhos secundários em texto. O `<h1>`
     * vem de `resposta()`, então aqui não há título. `$linhaHtml`, `$acaoPrincipal` (um `<a class="btn btn-primary">` ou `<form>`) e
     * os `$secundarios` (links ou formulários) chegam como HTML já escapado; só a ação principal usa o estilo cheio.
     *
     * @param list<string> $secundarios
     */
    public static function cabecalho(string $linhaHtml, string $acaoPrincipal = '', array $secundarios = []): string
    {
        $sec = $secundarios === [] ? '' : '<ul class="pbl-secundarias pbl-cabecalho-sec">' . implode('', array_map(static fn (string $i): string => '<li>' . $i . '</li>', $secundarios)) . '</ul>';

        return '<div class="pbl-cabecalho">' . ($linhaHtml === '' ? '' : '<p class="pbl-cabecalho-linha">' . $linhaHtml . '</p>')
            . ($acaoPrincipal === '' ? '' : '<div class="pbl-cabecalho-acao">' . $acaoPrincipal . '</div>') . $sec . '</div>';
    }

    /**
     * Cartão de estado (ADR 0032): tom `ok`, `atencao`, `erro` ou `andamento`, com ícone e título em texto (nunca só cor). `erro` e
     * `atencao` anunciam como `role="alert"`; o resto como `role="status"`. `$textoHtml` e `$acaoHtml` já escapados; `$titulo` é texto puro.
     * `$atributos` (já escapados) vai na raiz, para quem precisa de gancho (ex.: `data-pbl-progresso`).
     */
    public static function cartaoDeEstado(string $tom, string $titulo, string $textoHtml = '', string $acaoHtml = '', string $atributos = ''): string
    {
        $tom = in_array($tom, ['ok', 'atencao', 'erro', 'andamento'], true) ? $tom : 'andamento';
        $papel = in_array($tom, ['erro', 'atencao'], true) ? 'alert' : 'status';

        return '<section class="pbl-estado pbl-estado-' . $tom . '" role="' . $papel . '"' . ($atributos === '' ? '' : ' ' . $atributos) . '>'
            . '<div class="pbl-estado-titulo">' . self::icone('estado-' . $tom) . '<strong>' . self::h($titulo) . '</strong></div>'
            . ($textoHtml === '' ? '' : '<div class="pbl-estado-texto">' . $textoHtml . '</div>')
            . ($acaoHtml === '' ? '' : '<div class="pbl-estado-acao">' . $acaoHtml . '</div>') . '</section>';
    }

    /**
     * Abas de leitura/ação em forma de link (`?aba=chave`, ADR 0032): o servidor entrega só a aba pedida, funciona sem JavaScript e a
     * URL é marcável. A aba atual leva `aria-current="page"`. `$href` = caminho da tela sem query; `$extra` = query adicional já escapada.
     *
     * @param array<string, string> $abas chave => rótulo (texto puro)
     */
    public static function abasEmLink(array $abas, string $atual, string $href, string $rotuloDaBarra, string $extra = ''): string
    {
        $itens = '';
        foreach ($abas as $chave => $rotulo) {
            $itens .= '<li><a href="' . self::h($href) . '?aba=' . self::h((string) $chave) . $extra . '"' . ((string) $chave === $atual ? ' aria-current="page"' : '') . '>' . self::h($rotulo) . '</a></li>';
        }

        return '<nav aria-label="' . self::h($rotuloDaBarra) . '"><ul class="pbl-abas-links">' . $itens . '</ul></nav>';
    }

    /** Estado vazio útil (ADR 0032): o que falta, por que, e a próxima ação (`$acaoHtml` já escapado). */
    public static function vazio(string $titulo, string $textoHtml, string $acaoHtml = ''): string
    {
        return '<div class="pbl-vazio"><p class="mb-1"><strong>' . self::h($titulo) . '</strong></p><p class="mb-0">' . $textoHtml . '</p>' . $acaoHtml . '</div>';
    }

    /**
     * Painel recolhível: `<details>`/`<summary>` nativos (D3, ADR 0031), que abrem e fecham sem JavaScript e pelo teclado; o navegador
     * já expõe o estado aberto/fechado ao leitor de tela. `$aberto` (há valor ou erro dentro) nasce com `open`. `$resumo` e `$corpo`
     * chegam como HTML já escapado; `$id` precisa ser único na página (vira o `id` do `<details>`, âncora de `aria-describedby`/foco).
     * Agrupe vários dentro de `<div class="pbl-recolhiveis">`.
     */
    public static function recolhivel(string $id, string $resumo, string $corpo, bool $aberto = false): string
    {
        return '<details class="pbl-recolhivel" id="' . self::h('painel-' . $id) . '"' . ($aberto ? ' open' : '') . '><summary class="pbl-resumo">' . $resumo . '</summary>'
            . '<div class="pbl-recolhivel-corpo">' . $corpo . '</div></details>';
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
     * Ajuda de um campo, bloco ou tela atrás de um "?" (`<details>`, D15): abre sem JavaScript, por teclado, e nasce recolhida. O texto
     * (`role="note"`, `id` pedido) está sempre no HTML, então o campo pode apontá-lo em `aria-describedby` e o leitor de tela o lê
     * mesmo fechado. Nome acessível do "?": "Ajuda: assunto". Ponha logo depois do rótulo/título, dentro de um `.pbl-rotulo`.
     * `$id` único na página; `$assunto` é texto puro (nome do campo); `$html` é HTML já escapado; `$aberta` abre (ex.: erro no campo).
     */
    public static function ajuda(string $id, string $assunto, string $html, bool $aberta = false): string
    {
        if ($html === '') {
            return '';
        }

        return '<details class="pbl-ajuda"' . ($aberta ? ' open' : '') . '><summary aria-label="Ajuda: ' . self::h($assunto) . '"><span aria-hidden="true">?</span></summary>'
            . '<div id="' . self::h($id) . '" class="pbl-ajuda-texto" role="note">' . $html . '</div></details>';
    }

    /** Só o "?" (para quem separa botão e painel); com `<details>` os dois são uma peça só, então devolve a ajuda inteira. */
    public static function ajudaBotao(string $id, string $assunto, string $html): string
    {
        return self::ajuda($id, $assunto, $html);
    }

    /** Mantida por compatibilidade: o painel agora vai junto do "?" (ver `ajuda`), então aqui não há nada a acrescentar. */
    public static function ajudaPainel(string $id, string $html): string
    {
        return '';
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
        $rot = self::h($titulo);
        $cab = $tag === 'fieldset' ? '<legend>' . $rot . '</legend>' : '<h2 class="pbl-card-titulo">' . $rot . '</h2>';

        return '<' . $tag . ' class="pbl-card' . ($classe === '' ? '' : ' ' . $classe) . '">' . $cab . self::ajuda($id, $titulo, $ajuda) . $corpo . '</' . $tag . '>';
    }

    /** Aviso Bootstrap: `$tipo` = danger, success, warning ou info; erro/aviso usam `role="alert"`, o resto `role="status"`. `$texto` é texto puro. */
    public static function alerta(string $tipo, string $texto, string $id = '', bool $html = false): string
    {
        return '<div' . ($id === '' ? '' : ' id="' . self::h($id) . '"') . ' class="alert alert-' . self::h($tipo) . '" role="' . (in_array($tipo, ['danger', 'warning'], true) ? 'alert' : 'status') . '">'
            . ($html ? $texto : self::h($texto)) . '</div>';
    }

    /**
     * Seção de formulário (ADR 0031): título (h2), uma linha de descrição e o corpo em coluna, separada da anterior por uma linha fina.
     * Sem cartão por campo. `$corpo` é HTML já escapado; título e descrição são texto puro.
     */
    public static function secao(string $titulo, string $descricao, string $corpo): string
    {
        return '<section class="pbl-secao"><div class="pbl-secao-cab"><h2>' . self::h($titulo) . '</h2>'
            . ($descricao === '' ? '' : '<p>' . self::h($descricao) . '</p>') . '</div><div class="pbl-secao-corpo">' . $corpo . '</div></section>';
    }

    /**
     * Interruptor com rótulo e frase de explicação que revela os campos ao ligar (D2). Contrato com o servidor: o envio traz
     * `$nome=0` (campo oculto) e, se ligado, `$nome=1`; o servidor é a autoridade e grava o padrão do grupo quando vem `0`.
     * Contrato com o JavaScript: `data-pbl-toggle` na raiz, `data-pbl-inicial` ("ligado" quando há valor ou erro) e o painel `data-pbl-toggle-campos`.
     * Sem JavaScript a caixa nasce marcada e os campos ficam visíveis: o fluxo completo funciona e nada digitado se perde.
     * `$ehValor`: o interruptor é o próprio dado (ex.: "Mostrar no menu"), então a caixa reflete `$ligado` também sem JavaScript.
     * `$titulo` e `$explicacao` são texto puro; `$campos` é HTML já escapado.
     */
    public static function toggle(string $id, string $nome, string $titulo, string $explicacao, bool $ligado, string $campos, bool $ehValor = false): string
    {
        $i = self::h($id);

        return '<div class="pbl-toggle" data-pbl-toggle' . ($ehValor ? ' data-pbl-valor' : '') . ' data-pbl-inicial="' . ($ligado ? 'ligado' : 'desligado') . '"><div class="pbl-toggle-cab">'
            . '<label class="pbl-toggle-texto" for="' . $i . '"><span class="pbl-toggle-titulo">' . self::h($titulo) . '</span><span class="pbl-toggle-ajuda" id="' . $i . '-ajuda">' . self::h($explicacao) . '</span></label>'
            . '<span class="pbl-interruptor"><input type="hidden" name="' . self::h($nome) . '" value="0">'
            . '<input type="checkbox" role="switch" id="' . $i . '" name="' . self::h($nome) . '" value="1"' . (!$ehValor || $ligado ? ' checked' : '') . ' aria-describedby="' . $i . '-ajuda" aria-controls="' . $i . '-campos">'
            . '<span class="pbl-trilho" aria-hidden="true"></span></span></div>'
            . '<div class="pbl-toggle-campos" id="' . $i . '-campos" data-pbl-toggle-campos>' . $campos . '</div></div>';
    }

    /**
     * Abas de um formulário com UM só `<form>` e UM só envio (ADR 0031): todos os painéis estão no HTML, empilhados e com título quando
     * não há JavaScript; com JavaScript a barra (`role="tablist"`, setas, Home e End) mostra um painel por vez. `$ativa` = aba inicial
     * (`?aba=` ou a do primeiro erro). `$erros` = número de campos com erro por aba: aparece como texto ("• 2 erros"), nunca só cor.
     *
     * @param array<string, array{0: string, 1: string}> $abas chave => [rótulo, HTML do painel]
     * @param array<string, int> $erros chave da aba => quantidade de erros
     */
    public static function abasDeFormulario(array $abas, string $ativa, string $rotuloDaBarra, array $erros = []): string
    {
        $nav = '<ul class="pbl-abas-nav" role="tablist" aria-label="' . self::h($rotuloDaBarra) . '" hidden data-pbl-abas-nav>';
        $paineis = '';
        foreach ($abas as $chave => [$rotulo, $html]) {
            $on = $chave === $ativa;
            $n = $erros[$chave] ?? 0;
            $marca = $n > 0 ? ' <span class="pbl-marca-aba">• ' . $n . ($n === 1 ? ' erro' : ' erros') . '</span>' : '';
            $nav .= '<li role="presentation"><button type="button" id="aba-botao-' . $chave . '" role="tab" data-pbl-aba="' . $chave . '" aria-controls="aba-painel-' . $chave . '" aria-selected="' . ($on ? 'true' : 'false') . '"' . ($on ? '' : ' tabindex="-1"') . '>' . self::h($rotulo) . $marca . '</button></li>';
            $paineis .= '<div id="aba-painel-' . $chave . '" class="pbl-aba-painel" role="tabpanel" aria-labelledby="aba-botao-' . $chave . '" tabindex="-1"' . ($on ? ' data-ativa' : '') . '>'
                . '<h2 class="pbl-aba-titulo">' . self::h($rotulo) . $marca . '</h2>' . $html . '</div>';
        }

        return '<div data-pbl-abas-form data-pbl-aba-inicial="' . self::h($ativa) . '">' . $nav . '</ul>' . $paineis . '</div>';
    }

    /**
     * Barra de ações fixa de um formulário (F3): a esquerda o estado (`role="status"`: "Sem alterações", "Alterações não salvas"), a direita
     * Cancelar, Salvar e, se `$publicar`, "Salvar e publicar" (`acao=salvar_publicar`; o servidor confere a permissão de novo). O botão
     * principal é o "Salvar e publicar" quando existe e o conteúdo ainda não está no ar; senão é o Salvar. Fica fixa no pé sem JavaScript.
     */
    public static function barraDeAcoes(string $voltarHref, bool $publicar, bool $principalPublicar = false, string $estado = 'salvo', string $rotuloSalvar = 'Salvar'): string
    {
        $texto = $estado === 'alterado' ? 'Alterações não salvas' : 'Sem alterações';

        return '<div class="pbl-barra-form" data-pbl-barra><div class="pbl-barra-miolo">'
            . '<p class="pbl-estado" role="status" aria-live="polite" data-pbl-estado data-estado="' . self::h($estado) . '">' . $texto . '</p>'
            . '<div class="pbl-barra-botoes"><a class="btn btn-outline-secondary pbl-voltar" href="' . self::h($voltarHref) . '">Cancelar</a>'
            . '<button type="submit" name="acao" value="salvar" class="btn ' . ($publicar && $principalPublicar ? 'btn-outline-secondary' : 'btn-primary') . '">' . self::h($rotuloSalvar) . '</button>'
            . ($publicar ? '<button type="submit" name="acao" value="salvar_publicar" class="btn ' . ($principalPublicar ? 'btn-primary' : 'btn-outline-secondary') . '">Salvar e publicar</button>' : '')
            . '</div></div></div>';
    }

    /**
     * "⋯ Mais ações": um `<details>` com o resto das ações de um conteúdo, cada item já gerado pela mesma checagem de permissão de
     * sempre (o chamador só inclui o que a pessoa pode). Alvo de toque ≥ 44 px. Itens: `[HTML do link ou botão, classe do <li>]`.
     *
     * @param list<array{0: string, 1?: string}> $itens
     */
    public static function maisAcoes(array $itens, string $rotulo = 'Mais ações'): string
    {
        if ($itens === []) {
            return '';
        }
        $lis = '';
        foreach ($itens as $item) {
            $classe = $item[1] ?? '';
            $lis .= '<li' . ($classe === '' ? '' : ' class="' . self::h($classe) . '"') . '>' . $item[0] . '</li>';
        }

        return '<details class="pbl-mais"><summary><span aria-hidden="true">⋯</span>&nbsp;' . self::h($rotulo) . '</summary><ul class="pbl-mais-lista">' . $lis . '</ul></details>';
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
     * `img-src` aceita `https:`. `script-src` continua `'self'`: nenhum script inline ou de fora em lugar nenhum. `connect-src 'self'`
     * é o que deixa o `fetch()` do próprio painel (ex.: Republicar em `pbl-admin.js`) falar com o servidor; sem ele o `default-src 'none'` o bloqueia.
     */
    public static function protegida(Response $r, bool $seguro, bool $editor = false): Response
    {
        // O painel passa por aqui de novo depois da tela: a página que traz o editor é reconhecida pela marca no corpo.
        $html = str_starts_with((string) $r->headers->get('Content-Type'), 'text/html') ? (string) $r->getContent() : '';
        $editor = $editor || ($html !== '' && (str_contains($html, self::MARCA_EDITOR) || str_contains($html, self::MARCA_PREVIA)));
        // blob: só onde há seletor de imagem com miniatura (URL.createObjectURL do arquivo escolhido); nenhuma outra tela o aceita.
        $imagem = $html !== '' && str_contains($html, self::MARCA_IMAGEM);
        $r->headers->set('Cache-Control', 'no-store');
        $r->headers->set('X-Frame-Options', 'DENY');
        $r->headers->set('X-Content-Type-Options', 'nosniff');
        $r->headers->set('Referrer-Policy', 'same-origin');
        // img-src data: só porque o CSS do Bootstrap desenha alguns ícones de formulário como SVG embutido.
        $r->headers->set('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self'" . ($editor ? " 'unsafe-inline'" : '') . "; img-src 'self' data:" . ($imagem ? ' blob:' : '') . ($editor ? ' https:' : '') . "; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        if ($seguro) {
            // o `max-age` configurável (seguranca.json) é aplicado depois, por `Http\CabecalhosDeSeguranca::aplicar`, em toda resposta
            $r->headers->set('Strict-Transport-Security', 'max-age=' . \Pbl\Http\CabecalhosDeSeguranca::HSTS_PADRAO);
        }
        $r->headers->set('Permissions-Policy', \Pbl\Http\CabecalhosDeSeguranca::PERMISSOES);

        return $r;
    }
}
