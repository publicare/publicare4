<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Pbl\Conteudo\Site;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\Response;

/**
 * `sitemap.xml` e `robots.txt` de cada site, gerados a cada pedido (uma consulta). Só entra no sitemap o que o público vê: objetos
 * publicados e vigentes, sem `seo_nao_indexar` e sem `link` (que não tem conteúdo próprio). Os endereços são absolutos, com o host do
 * domínio principal do site (`EnderecoDoSite`), nunca o da requisição. Site sem domínio principal não tem sitemap (404).
 */
final class SitemapERobots
{
    public const MAX_URLS = 50000;

    public function __construct(private readonly Servicos $servicos)
    {
    }

    public static function atende(string $caminho): bool
    {
        return $caminho === '/sitemap.xml' || $caminho === '/robots.txt';
    }

    public function tratar(Site $site, string $caminho): ?Response
    {
        $base = $this->servicos->enderecos->base($site->id);
        if ($base === '') {
            return null;
        }

        return $caminho === '/robots.txt' ? $this->robots($base) : $this->sitemap($site, $base);
    }

    private function robots(string $base): Response
    {
        $texto = "User-agent: *\nDisallow: /_pbl/\nDisallow: /dados/\n\nSitemap: {$base}/sitemap.xml\n";

        return new Response($texto, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function sitemap(Site $site, string $base): ?Response
    {
        $raiz = $site->objetoRaizId === null ? null : $this->servicos->objetos->porId($site->objetoRaizId);
        if ($raiz === null) {
            return null;
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        $n = 0;
        foreach ($this->servicos->objetos->indicePublicadoDaSubarvore($site->id, $raiz->caminho) as $o) {
            if ($o['classe'] === 'link' || $this->naoIndexar($o['dados'])) {
                continue;
            }
            if (++$n > self::MAX_URLS) {
                break;
            }
            $xml .= '<url><loc>' . htmlspecialchars($base . '/' . $o['url'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $o['atualizado_em'], $m) === 1) {
                $xml .= '<lastmod>' . $m[0] . '</lastmod>';
            }
            $xml .= "</url>\n";
        }

        return new Response($xml . '</urlset>' . "\n", Response::HTTP_OK, ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => 'public, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function naoIndexar(string $dados): bool
    {
        $d = json_decode($dados, true);
        $v = is_array($d) ? ($d['seo_nao_indexar'] ?? null) : null;

        return $v === 1 || $v === '1' || $v === true;
    }
}
