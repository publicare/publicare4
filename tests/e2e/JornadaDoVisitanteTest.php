<?php

declare(strict_types=1);

namespace Pbl\Testes\E2E;

use Pbl\Caminhos;
use Pbl\Console\DemoPopularComando;
use Pbl\Demo\ConteudoPublicare;
use Pbl\Instalacao\Instalador;
use Pbl\Instalacao\PelesDistribuidas;
use Pbl\Servicos;
use Pbl\Testes\Ajuda;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/steps/Navegador.php';

/** Implementa tests/e2e/visitante.feature, tests/e2e/contract/cli.feature e tests/e2e/regression/peles.feature. */
final class JornadaDoVisitanteTest extends TestCase
{
    private const HOST = 'www.exemplo.org';

    private static string $raiz;
    private static Caminhos $c;
    private static Navegador $nav;
    /** @var array<string, string> caminho => html, de tudo que a varredura alcançou (200) */
    private static array $paginas = [];

    public static function setUpBeforeClass(): void
    {
        self::$raiz = Ajuda::raizDeProjeto();
        self::$c = new Caminhos(self::$raiz);
        (new Instalador(self::$c))->instalar(ConteudoPublicare::NOME_DO_SITE, self::HOST, null, [], PelesDistribuidas::PADRAO);
        $t = new CommandTester(new DemoPopularComando(self::$c));
        self::assertSame(0, $t->execute([]), $t->getDisplay());
        self::$nav = new Navegador(self::$c, self::HOST);

        $fila = ['/'];
        while ($fila !== [] && count(self::$paginas) < 150) {
            $caminho = array_shift($fila);
            if (isset(self::$paginas[$caminho])) {
                continue;
            }
            $r = self::$nav->abrir($caminho);
            self::assertSame(200, $r->getStatusCode(), "GET {$caminho}");
            $html = (string) $r->getContent();
            self::$paginas[$caminho] = $html;
            foreach (Navegador::linksInternos($html) as $l) {
                if (!isset(self::$paginas[$l]) && preg_match('#\.(xml|txt|pdf|zip|jpe?g|png|webp|gif|svg)$#i', $l) !== 1 && !str_starts_with($l, '/busca') && !str_starts_with($l, '/tag/')) {
                    $fila[] = $l;
                }
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        Ajuda::apagar(self::$raiz);
    }

    public function testVarreduraDaHomeAlcancaOSiteTodoSem404(): void
    {
        self::assertGreaterThan(15, count(self::$paginas), 'a varredura precisa chegar às páginas internas');
        foreach (['/', '/perguntas-frequentes'] as $obrigatoria) {
            self::assertArrayHasKey($obrigatoria, self::$paginas);
        }
        foreach (self::$paginas as $caminho => $html) {
            self::assertStringNotContainsStringIgnoringCase('Instituto ' . 'Exemplo', $html, $caminho);
            self::assertStringContainsString('<main', $html, $caminho);
            self::assertMatchesRegularExpression('#<h1\b#', $html, "{$caminho} sem h1");
        }
    }

    public function testRecursosDaPeleSaoServidosLocalmenteComTipoCerto(): void
    {
        $home = self::$paginas['/'];
        preg_match_all('#(?:href|src)="((?:/_pbl/peles/publicare/)[^"]+)"#', $home, $m);
        $recursos = array_values(array_unique($m[1]));
        self::assertNotEmpty($recursos);
        $css = array_filter($recursos, static fn (string $u): bool => str_contains($u, '/assets/pele.css'));
        self::assertNotEmpty($css, 'a home aponta para o pele.css: ' . implode(', ', $recursos));
        $tipos = ['css' => 'text/css', 'svg' => 'image/svg+xml', 'js' => 'javascript'];
        foreach ($recursos as $url) {
            $r = self::$nav->abrir(html_entity_decode($url));
            self::assertSame(200, $r->getStatusCode(), $url);
            $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
            if (isset($tipos[$ext])) {
                self::assertStringContainsString($tipos[$ext], (string) $r->headers->get('Content-Type'), $url);
            }
        }
        $css = (string) self::$nav->abrir('/_pbl/peles/publicare/assets/pele.css')->getContent();
        self::assertStringContainsString('prefers-color-scheme:dark', $css);
        self::assertStringContainsString('prefers-reduced-motion', $css);
        self::assertStringContainsString(':focus-visible', $css);
        self::assertDoesNotMatchRegularExpression('#(?:@import|url\()\s*[\'"]?(?:https?:)?//#i', $css, 'CSS sem CDN');
    }

    public function testNenhumaPaginaCarregaRecursoExternoNemCodigoEmbutido(): void
    {
        foreach (self::$paginas as $caminho => $html) {
            self::assertSame([], Navegador::recursosExternos($html), "recurso externo em {$caminho}");
            self::assertDoesNotMatchRegularExpression('#<style\b#i', $html, "style embutido em {$caminho}");
            self::assertDoesNotMatchRegularExpression('#<script\b(?![^>]*\ssrc=)(?![^>]*type="application/ld\+json")#i', $html, "script embutido em {$caminho}");
            self::assertDoesNotMatchRegularExpression('#\s(?:style|on[a-z]+)="#i', $html, "atributo inline em {$caminho}");
        }
    }

    public function testImagensTemAltEFaqEmAcordeao(): void
    {
        foreach (self::$paginas as $caminho => $html) {
            preg_match_all('#<img\b[^>]*>#i', $html, $m);
            foreach ($m[0] as $img) {
                self::assertMatchesRegularExpression('#\salt="#', $img, "img sem alt em {$caminho}");
            }
        }
        $faq = self::$paginas['/perguntas-frequentes'];
        $n = substr_count($faq, '<details');
        self::assertGreaterThanOrEqual(8, $n);
        self::assertLessThanOrEqual(12, $n);
        self::assertSame($n, substr_count($faq, '<summary'));
    }

    public function testPaginaInexistenteEBuscaFicamNaPele(): void
    {
        $r = self::$nav->abrir('/isto-nao-existe');
        self::assertSame(404, $r->getStatusCode());
        $html = (string) $r->getContent();
        self::assertStringContainsString('<footer', $html);
        self::assertStringContainsString('pele.css', $html);
        self::assertStringContainsString('noindex', $html);

        $b = self::$nav->abrir('/busca?q=moldes');
        self::assertSame(200, $b->getStatusCode());
        self::assertStringContainsString('pele.css', (string) $b->getContent());
        self::assertSame(200, self::$nav->abrir('/busca?q=zzzxqwvk')->getStatusCode(), 'busca sem resultado não quebra');
    }

    public function testCabecalhosDeSegurancaProibemCodigoEmbutido(): void
    {
        $r = self::$nav->abrir('/');
        $csp = (string) $r->headers->get('Content-Security-Policy');
        self::assertNotSame('', $csp);
        // Concessão documentada da plataforma (CabecalhosDeSeguranca): `style-src` aceita inline por causa de `style=` em conteúdo editado; script nunca.
        self::assertMatchesRegularExpression("#script-src 'self'(;|$)#", $csp);
        self::assertStringNotContainsString("'unsafe-eval'", $csp);
        self::assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
    }

    // --- contract/cli.feature ----------------------------------------------------------------------------------------

    public function testContratoDemoPopularIdempotenteEPeleBasicaDisponivel(): void
    {
        $antes = (int) Servicos::doBancoInstalado(self::$c)->conexao()->fetchOne('SELECT COUNT(*) FROM objeto');
        $t = new CommandTester(new DemoPopularComando(self::$c));
        self::assertSame(1, $t->execute([]), 'site cheio: recusa sem --forcar');
        self::assertSame(0, $t->execute(['--forcar' => true, '--sem-publicar' => true]), $t->getDisplay());
        $depois = (int) Servicos::doBancoInstalado(self::$c)->conexao()->fetchOne('SELECT COUNT(*) FROM objeto');
        self::assertSame($antes, $depois, 'rodar demo-popular de novo não duplica objetos');
        self::assertFileExists(self::$c->peles() . '/basica/padrao.pbl');
        self::assertFileExists(self::$c->peles() . '/publicare/padrao.pbl');
    }

    // --- regression/peles.feature ------------------------------------------------------------------------------------

    public function testRegressaoSiteNaPeleBasicaNaoMudaDePeleSozinho(): void
    {
        \Pbl\Testes\Ajuda::pularSeBancoDeServidor();
        $raiz = Ajuda::raizDeProjeto();
        try {
            $c = new Caminhos($raiz);
            (new Instalador($c))->instalar('Site antigo', self::HOST, null, [], 'basica');
            $t = new CommandTester(new DemoPopularComando($c));
            self::assertSame(0, $t->execute(['--pele' => 'basica', '--sem-publicar' => true]), $t->getDisplay());
            $site = Servicos::doBancoInstalado($c)->sites->todos()[0];
            self::assertSame('basica', $site->pele);
        } finally {
            Ajuda::apagar($raiz);
        }
    }
}
