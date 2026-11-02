<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Symfony\Component\HttpFoundation\Response;

/**
 * Páginas HTML da administração e do instalador: sem JavaScript, tudo escapado, com os cabeçalhos de segurança. O visual vem do
 * Bootstrap 5 servido do próprio site (`public/_pbl/assets/`, sem CDN) mais `pbl.css`; nenhum estilo ou script inline, então a
 * CSP só libera `'self'` (ADR 0012).
 */
final class Pagina
{
    /** Pasta pública dos arquivos estáticos da administração. Arquivo existente é servido direto pelo servidor web. */
    public const ASSETS = '/_pbl/assets';
    public const BOOTSTRAP = self::ASSETS . '/bootstrap-5.3.8/bootstrap.min.css';
    public const CSS = self::ASSETS . '/pbl.css';

    public static function h(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** `$navegacao` (HTML já escapado, em geral uma `<nav>`) vai num `<header>` antes do `<main>`; o título vira o `<h1>`. */
    public static function resposta(string $titulo, string $corpo, int $status = Response::HTTP_OK, bool $seguro = false, string $navegacao = ''): Response
    {
        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . self::h($titulo) . ' — PBL4</title>'
            . '<link rel="stylesheet" href="' . self::BOOTSTRAP . '"><link rel="stylesheet" href="' . self::CSS . '">'
            . '</head><body><a class="visually-hidden-focusable" href="#conteudo">Pular para o conteúdo</a>'
            . ($navegacao === '' ? '' : '<header>' . $navegacao . '</header>')
            . '<main id="conteudo" class="container py-4"><h1 class="h3 mb-4">' . self::h($titulo) . '</h1>' . $corpo . '</main></body></html>';

        return self::protegida(new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']), $seguro);
    }

    /** Barra de navegação de quem entrou (sem JavaScript: sem menu recolhível). `$nome` e `$tokenCsrf` chegam sem escape. */
    public static function barra(string $nome, string $tokenCsrf, bool $administrador = false): string
    {
        return '<nav class="navbar navbar-expand bg-dark" data-bs-theme="dark" aria-label="Administração"><div class="container">'
            . '<a class="navbar-brand" href="/_pbl/">PBL4</a>'
            . '<ul class="navbar-nav pbl-links"><li class="nav-item"><a class="nav-link" href="/_pbl/classes">Tipos de conteúdo</a></li>'
            . ($administrador ? '<li class="nav-item"><a class="nav-link" href="/_pbl/contas">Contas</a></li><li class="nav-item"><a class="nav-link" href="/_pbl/atualizacao">Atualização</a></li>' : '')
            . '</ul>'
            . '<div class="d-flex align-items-center gap-2 ms-auto"><span class="navbar-text text-truncate">' . self::h($nome) . '</span>'
            . '<form method="post" action="/_pbl/sair"><input type="hidden" name="_csrf" value="' . self::h($tokenCsrf) . '">'
            . '<button type="submit" class="btn btn-sm btn-outline-light">Sair</button></form></div></div></nav>';
    }

    /** Cabeçalhos de segurança das páginas de administração (ADR 0011 e 0012). `$seguro` = a conexão é HTTPS (liga o HSTS). */
    public static function protegida(Response $r, bool $seguro): Response
    {
        $r->headers->set('Cache-Control', 'no-store');
        $r->headers->set('X-Frame-Options', 'DENY');
        $r->headers->set('X-Content-Type-Options', 'nosniff');
        $r->headers->set('Referrer-Policy', 'same-origin');
        // img-src data: só porque o CSS do Bootstrap desenha alguns ícones de formulário como SVG embutido.
        $r->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        if ($seguro) {
            $r->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $r;
    }
}
