<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Pbl\Caminhos;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Arquivos de uma pele (`dados/peles/<pele>/assets/…`) servidos em `/_pbl/peles/<pele>/<arquivo>` (ADR 0022): CSS, JS, imagens
 * e fontes do próprio domínio, sem CDN. Cada pele tem o seu caminho, então o cache do navegador nunca mistura peles. Só GET/HEAD,
 * só extensões da lista, nome sem `..` nem links simbólicos, e o arquivo real tem de estar dentro da pasta de assets da pele.
 */
final class AssetsDePele
{
    private const ROTA = '#^/_pbl/peles/([a-z0-9_-]{1,50})/((?:[A-Za-z0-9_-][A-Za-z0-9._-]{0,80}/){0,3}[A-Za-z0-9_-][A-Za-z0-9._-]{0,80})$#D';

    private const TIPOS = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'map' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    public function __construct(private readonly Caminhos $caminhos)
    {
    }

    public static function atende(Request $requisicao): bool
    {
        return str_starts_with($requisicao->getPathInfo(), '/_pbl/peles/');
    }

    public function tratar(Request $requisicao): Response
    {
        if (!in_array($requisicao->getMethod(), ['GET', 'HEAD'], true)) {
            return $this->resposta('Método não permitido', Response::HTTP_METHOD_NOT_ALLOWED);
        }
        if (preg_match(self::ROTA, $requisicao->getPathInfo(), $m) !== 1 || str_contains($m[2], '..')) {
            return $this->resposta('Não encontrado', Response::HTTP_NOT_FOUND);
        }
        $extensao = strtolower(pathinfo($m[2], PATHINFO_EXTENSION));
        $base = realpath($this->caminhos->peles() . '/' . $m[1] . '/assets');
        $caminho = $this->caminhos->peles() . '/' . $m[1] . '/assets/' . $m[2];
        $real = $base === false || !isset(self::TIPOS[$extensao]) || is_link($caminho) ? false : realpath($caminho);
        if ($base === false || $real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_file($real)) {
            return $this->resposta('Não encontrado', Response::HTTP_NOT_FOUND);
        }
        $r = new Response($requisicao->isMethod('HEAD') ? '' : (string) file_get_contents($real));
        $r->headers->set('Content-Type', self::TIPOS[$extensao]);
        $r->headers->set('X-Content-Type-Options', 'nosniff');
        $r->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        // Com `?v=hash` (a versão dos assets, `#pele_v`) o arquivo muda de endereço quando muda: cache longo.
        $r->headers->set('Cache-Control', $requisicao->query->has('v') ? 'public, max-age=31536000, immutable' : 'public, max-age=3600');

        return $r;
    }

    private function resposta(string $texto, int $status): Response
    {
        return new Response($texto, $status, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Content-Type-Options' => 'nosniff']);
    }
}
