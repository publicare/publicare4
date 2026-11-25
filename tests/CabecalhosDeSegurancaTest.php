<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Aplicacao;
use Pbl\Caminhos;
use Pbl\Http\CabecalhosDeSeguranca;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** S-02: cabeçalhos de segurança do site público e do painel, HSTS configurável e peles compatíveis com a CSP. */
final class CabecalhosDeSegurancaTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Site', 'www.exemplo.org');
        $s = Servicos::doBancoInstalado($this->caminhos);
        foreach ($s->sites->todos() as $site) {
            $s->publicador->publicarSite($site);
        }
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    /** @param array<string, string> $servidor */
    private function pegar(string $caminho, bool $https = true, array $servidor = []): Response
    {
        $r = Request::create(($https ? 'https' : 'http') . '://www.exemplo.org' . $caminho, 'GET', [], [], [], $servidor + ['REMOTE_ADDR' => '198.51.100.7']);

        return (new Aplicacao($this->caminhos))->tratar($r);
    }

    private function config(string $json): void
    {
        @mkdir($this->caminhos->config(), 0775, true);
        file_put_contents($this->caminhos->config() . '/seguranca.json', $json);
    }

    /** @return iterable<string, array{string, int}> */
    public static function paginasPublicas(): iterable
    {
        yield 'página publicada' => ['/', 200];
        yield 'página interna' => ['/sobre', 200];
        yield '404' => ['/nao-existe', 404];
        yield 'busca' => ['/busca?q=sobre', 200];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('paginasPublicas')]
    public function testPaginasPublicasTemOsCabecalhos(string $caminho, int $status): void
    {
        $r = $this->pegar($caminho);
        self::assertSame($status, $r->getStatusCode());
        $csp = (string) $r->headers->get('Content-Security-Policy');
        self::assertStringContainsString("script-src 'self'", $csp);
        self::assertStringContainsString("frame-ancestors 'self'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        self::assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $r->headers->get('Referrer-Policy'));
        self::assertStringContainsString('camera=()', (string) $r->headers->get('Permissions-Policy'));
    }

    public function testFeedERobotsTambemTemCabecalhos(): void
    {
        $r = $this->pegar('/robots.txt');
        self::assertNotNull($r->headers->get('Content-Security-Policy'));
        self::assertNotNull($r->headers->get('Permissions-Policy'));
    }

    public function testHstsSoEmHttpsSemIncludeSubDomains(): void
    {
        self::assertNull($this->pegar('/', false)->headers->get('Strict-Transport-Security'));
        $hsts = (string) $this->pegar('/')->headers->get('Strict-Transport-Security');
        self::assertSame('max-age=31536000', $hsts);
        self::assertStringNotContainsString('includeSubDomains', $hsts);
        self::assertStringNotContainsString('preload', $hsts);
    }

    public function testHstsConfiguravelEDesligavel(): void
    {
        $this->config('{"hsts_max_age": 600}');
        self::assertSame('max-age=600', $this->pegar('/')->headers->get('Strict-Transport-Security'));
        self::assertSame('max-age=600', $this->pegar('/_pbl/entrar')->headers->get('Strict-Transport-Security'), 'o painel segue a mesma configuração');
        $this->config('{"hsts_max_age": 0}');
        self::assertNull($this->pegar('/')->headers->get('Strict-Transport-Security'));
        $this->config('{"hsts_max_age": "muito"}');
        self::assertSame('max-age=31536000', $this->pegar('/')->headers->get('Strict-Transport-Security'), 'valor inválido volta ao padrão');
    }

    public function testHstsComProxyConfiavel(): void
    {
        $proxy = ['HTTP_X_FORWARDED_PROTO' => 'https'];
        self::assertNull($this->pegar('/', false, $proxy)->headers->get('Strict-Transport-Security'), 'sem confiar_proto o cabeçalho é ignorado');
        @mkdir($this->caminhos->config(), 0775, true);
        file_put_contents($this->caminhos->config() . '/rede.json', '{"confiar_proto": true}');
        self::assertNotNull($this->pegar('/', false, $proxy)->headers->get('Strict-Transport-Security'));
    }

    public function testSomenteRelatorioTrocaOCabecalhoDaCspPublica(): void
    {
        $this->config('{"csp_publica_somente_relatorio": true}');
        $r = $this->pegar('/');
        self::assertNull($r->headers->get('Content-Security-Policy'));
        self::assertNotNull($r->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function testPainelMantemASuaPoliticaEGanhaPermissionsPolicy(): void
    {
        $r = $this->pegar('/_pbl/entrar');
        $csp = (string) $r->headers->get('Content-Security-Policy');
        self::assertStringStartsWith("default-src 'none'; script-src 'self'; style-src 'self';", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertSame('DENY', $r->headers->get('X-Frame-Options'));
        self::assertStringContainsString('camera=()', (string) $r->headers->get('Permissions-Policy'));
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
    }

    public function testRespostaQueJaTemCspNaoESobrescrita(): void
    {
        $r = new Response('x', 200, ['Content-Security-Policy' => "default-src 'none'", 'Referrer-Policy' => 'no-referrer']);
        (new CabecalhosDeSeguranca())->aplicar($r, false);
        self::assertSame("default-src 'none'", $r->headers->get('Content-Security-Policy'));
        self::assertSame('no-referrer', $r->headers->get('Referrer-Policy'));
    }

    /** A CSP pública é `script-src 'self'`: pele com `<script>` inline ou `on*=` quebraria (o JSON-LD, `type="application/ld+json"`, é só dado e não executa). Provado em todas as peles distribuídas. */
    public function testPelesDistribuidasNaoUsamScriptInlineNemManipuladorEmAtributo(): void
    {
        $base = dirname(__DIR__) . '/app/recursos/peles';
        $arquivos = [];
        foreach (glob($base . '/*/*.pbl') ?: [] as $a) {
            $arquivos[] = $a;
        }
        self::assertNotEmpty($arquivos);
        foreach ($arquivos as $a) {
            $conteudo = (string) file_get_contents($a);
            self::assertSame(0, preg_match('/<script(?![^>]*\ssrc=)(?![^>]*type="application\/ld\+json")[^>]*>/i', $conteudo), "script inline em {$a}");
            self::assertSame(0, preg_match('/\son[a-z]+\s*=\s*["\']/i', $conteudo), "manipulador em atributo em {$a}");
            self::assertSame(0, preg_match('/javascript:/i', $conteudo), "javascript: em {$a}");
        }
        // e a página renderizada pelo site instalado também (a pele padrão)
        $html = (string) $this->pegar('/')->getContent();
        self::assertSame(0, preg_match('/<script(?![^>]*\ssrc=)(?![^>]*type="application\/ld\+json")[^>]*>/i', $html));
        self::assertSame(0, preg_match('/\son[a-z]+\s*=\s*["\']/i', $html));
    }
}
