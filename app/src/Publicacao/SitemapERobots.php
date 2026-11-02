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

    public function __construct(private readonly Servicos $servicos, private readonly int $maximo = self::MAX_URLS)
    {
    }

    public static function atende(string $caminho): bool
    {
        return $caminho === '/sitemap.xml' || $caminho === '/robots.txt' || preg_match('#^/sitemap-[1-9]\d{0,5}\.xml$#', $caminho) === 1;
    }

    public function tratar(Site $site, string $caminho): ?Response
    {
        $base = $this->servicos->enderecos->base($site->id);
        if ($base === '') {
            return null;
        }

        return $caminho === '/robots.txt' ? $this->robots($base) : $this->sitemap($site, $base, $caminho);
    }

    private function robots(string $base): Response
    {
        $texto = "User-agent: *\nDisallow: /_pbl/\nDisallow: /dados/\n\nSitemap: {$base}/sitemap.xml\n";

        return $this->resposta($texto, 'text/plain');
    }

    private function resposta(string $corpo, string $tipo): Response
    {
        return new Response($corpo, Response::HTTP_OK, ['Content-Type' => $tipo . '; charset=utf-8', 'Cache-Control' => 'public, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Até `$maximo` URLs, `sitemap.xml` é a lista; acima disso vira índice de `sitemap-N.xml` (limite do protocolo: 50.000 por arquivo). */
    private function sitemap(Site $site, string $base, string $caminho): ?Response
    {
        $raiz = $site->objetoRaizId === null ? null : $this->servicos->objetos->porId($site->objetoRaizId);
        if ($raiz === null) {
            return null;
        }
        $itens = [];
        foreach ($this->servicos->objetos->indicePublicadoDaSubarvore($site->id, $raiz->caminho) as $o) {
            if ($o['classe'] !== 'link' && !$this->naoIndexar($o['dados'])) {
                $itens[] = $o;
            }
        }
        $paginas = max(1, (int) ceil(count($itens) / max(1, $this->maximo)));
        $cab = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

        if ($caminho === '/sitemap.xml' && $paginas > 1) {
            $xml = $cab . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
            for ($i = 1; $i <= $paginas; $i++) {
                $xml .= '<sitemap><loc>' . htmlspecialchars("{$base}/sitemap-{$i}.xml", ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc></sitemap>\n";
            }

            return $this->resposta($xml . '</sitemapindex>' . "\n", 'application/xml');
        }

        $pagina = 1;
        if ($caminho !== '/sitemap.xml') {
            $pagina = (int) substr($caminho, 9, -4);
            if ($paginas === 1 || $pagina > $paginas) {
                return null;
            }
        }
        $xml = $cab . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (array_slice($itens, ($pagina - 1) * $this->maximo, $this->maximo) as $o) {
            $xml .= '<url><loc>' . htmlspecialchars($base . '/' . $o['url'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $o['atualizado_em'], $m) === 1) {
                $xml .= '<lastmod>' . $m[0] . '</lastmod>';
            }
            $xml .= "</url>\n";
        }

        return $this->resposta($xml . '</urlset>' . "\n", 'application/xml');
    }

    private function naoIndexar(string $dados): bool
    {
        $d = json_decode($dados, true);
        $v = is_array($d) ? ($d['seo_nao_indexar'] ?? null) : null;

        return $v === 1 || $v === '1' || $v === true;
    }
}
