<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Symfony\Component\HttpFoundation\Response;

/** Páginas HTML mínimas da administração e do instalador: sem JavaScript, tudo escapado, com os cabeçalhos de segurança. */
final class Pagina
{
    public static function h(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function resposta(string $titulo, string $corpo, int $status = Response::HTTP_OK, bool $seguro = false): Response
    {
        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . self::h($titulo) . ' — PBL4</title>'
            . '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:34rem;margin:3rem auto;padding:0 1rem;color:#1d1d1b}'
            . 'label{display:block;margin:.75rem 0}input{display:block;width:100%;padding:.5rem;font:inherit;box-sizing:border-box}'
            . 'button{margin-top:1rem;padding:.5rem 1rem;font:inherit}.erro{color:#a40000}.ok{color:#146c2e}.no{color:#a40000}'
            . 'table{border-collapse:collapse;width:100%}td{padding:.25rem .5rem;border-bottom:1px solid #ddd;vertical-align:top}code{background:#f0f0ee;padding:0 .25rem}</style>'
            . '</head><body><h1>' . self::h($titulo) . '</h1>' . $corpo . '</body></html>';

        return self::protegida(new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']), $seguro);
    }

    /** Cabeçalhos de segurança das páginas de administração (ADR 0011). `$seguro` = a conexão é HTTPS (liga o HSTS). */
    public static function protegida(Response $r, bool $seguro): Response
    {
        $r->headers->set('Cache-Control', 'no-store');
        $r->headers->set('X-Frame-Options', 'DENY');
        $r->headers->set('X-Content-Type-Options', 'nosniff');
        $r->headers->set('Referrer-Policy', 'same-origin');
        $r->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        if ($seguro) {
            $r->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $r;
    }
}
