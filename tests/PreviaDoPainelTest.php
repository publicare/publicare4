<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Aplicacao;
use Pbl\Previa\ModoPrevia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** Modo prévia (rodada 2): host `pv-…` e entrada das contas fictícias só com o marcador do pacote; seletor de conteúdo (Q2). */
final class PreviaDoPainelTest extends TestCase
{
    use AdminAuxiliar;

    private function noHost(string $host, string $caminho, ?string $token = null): \Symfony\Component\HttpFoundation\Response
    {
        $cookies = $token === null ? [] : ['__Host-pbl4' => $token];

        return (new Aplicacao($this->caminhos))->tratar(Request::create('https://' . $host . $caminho, 'GET', [], $cookies, [], ['REMOTE_ADDR' => '198.51.100.7']));
    }

    private function ligarPrevia(): void
    {
        file_put_contents($this->caminhos->dados() . '/' . ModoPrevia::MARCADOR, "previa\n");
    }

    public function testSemMarcadorOHostDaPreviaNaoEhAceitoNemHaEntradaAutomatica(): void
    {
        self::assertSame(404, $this->noHost('pv-abc123.previa.test', '/')->getStatusCode());
        self::assertSame(404, $this->noHost('www.exemplo.org', '/_pbl/previa-entrar?conta=admin')->getStatusCode());
    }

    public function testComMarcadorOHostPvAtendeOSiteEOutrosHostsContinuamRecusados(): void
    {
        $this->ligarPrevia();
        self::assertSame(200, $this->noHost('pv-abc123.previa.test', '/_pbl/entrar')->getStatusCode());
        self::assertSame(404, $this->noHost('outro.example', '/_pbl/entrar')->getStatusCode(), 'só o host pv-… ganha o atalho');
        self::assertSame(404, $this->noHost('previa-abc.test', '/_pbl/entrar')->getStatusCode());
    }

    public function testEntradaDePreviaAbreSessaoDaContaFicticiaEVaiParaODestinoDoPainel(): void
    {
        $this->ligarPrevia();
        $r = $this->noHost('pv-abc123.previa.test', '/_pbl/previa-entrar?conta=editor&tema=escuro&ir=/_pbl/mais');
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/_pbl/mais', $r->headers->get('Location'));
        $cookies = [];
        foreach ($r->headers->getCookies() as $c) {
            $cookies[$c->getName()] = $c;
        }
        self::assertArrayHasKey('__Host-pbl4', $cookies);
        self::assertTrue($cookies['__Host-pbl4']->isHttpOnly());
        self::assertSame('escuro', $cookies['pbl-tema-previa']->getValue());
        // com o token de sessão, o painel responde 200 (https usa o prefixo __Host-)
        $ok = $this->noHost('pv-abc123.previa.test', '/_pbl/mais', $cookies['__Host-pbl4']->getValue());
        self::assertSame(200, $ok->getStatusCode());
        self::assertStringContainsString('Mais', $this->html($ok));
    }

    /** @return array<string, array{0: string}> */
    public static function destinosRuins(): array
    {
        return ['outro site' => ['https://evil.example/'], 'esquema' => ['//evil.example/_pbl'], 'fora do painel' => ['/admin'], 'subida' => ['/_pbl/../x'], 'vazio' => ['']];
    }

    #[DataProvider('destinosRuins')]
    public function testDestinoForaDoPainelCaiNoInicio(string $ir): void
    {
        self::assertSame('/_pbl/', ModoPrevia::destinoSeguro($ir));
        $this->ligarPrevia();
        $r = $this->noHost('pv-abc123.previa.test', '/_pbl/previa-entrar?conta=admin&ir=' . rawurlencode($ir));
        self::assertSame('/_pbl/', $r->headers->get('Location'));
    }

    public function testSoContasFicticiasEntram(): void
    {
        $this->ligarPrevia();
        self::assertSame(404, $this->noHost('pv-abc123.previa.test', '/_pbl/previa-entrar?conta=leitor')->getStatusCode());
        self::assertSame(404, $this->noHost('pv-abc123.previa.test', '/_pbl/previa-entrar?conta=inexistente')->getStatusCode());
    }

    public function testHostDaPreviaTemFormatoEstrito(): void
    {
        self::assertTrue(ModoPrevia::hostDaPrevia('pv-9f2.previa.exemplo.dev'));
        foreach (['pv-', 'PV-abc', 'xpv-abc', 'pv-a b', 'pv-a/b', 'pv-' . str_repeat('a', 130)] as $ruim) {
            self::assertFalse(ModoPrevia::hostDaPrevia($ruim), $ruim);
        }
    }

    public function testCampoDeReferenciaViraListaDeConteudosFiltravelEMantemOValorAtual(): void
    {
        $classe = $this->s->classes->criar('evento', [['nome' => 'relacionada', 'tipo' => 'ref', 'classe' => 'pagina']]);
        $pagina = $this->s->classes->porNome('pagina') ?? self::fail();
        $alvo = $this->s->objetos->criar($this->siteId, $pagina, $this->inicioId, 'Página <b>alvo</b>', 'alvo', ['corpo' => '<p>x</p>'], 'publicado', []);
        $e = $this->s->objetos->criar($this->siteId, $classe, $this->inicioId, 'Evento', 'evento-1', ['relacionada' => $alvo->id], 'publicado', []);
        $h = $this->html($this->req('GET', '/_pbl/objeto/' . $e->id . '/editar', $this->token('admin')));
        self::assertSame(1, preg_match('#<select[^>]*name="dados\[relacionada\]"[^>]*>(.*?)</select>#s', $h, $m), 'select no lugar do número');
        self::assertStringContainsString('data-pbl-seletor', $h);
        self::assertStringContainsString('<option value="' . $alvo->id . '" selected>#' . $alvo->id . ' · Página &lt;b&gt;alvo&lt;/b&gt;</option>', $m[1]);
        self::assertStringNotContainsString('<b>alvo', $h, 'título escapado');
        self::assertStringNotContainsString('value="' . $e->id . '">#' . $e->id . ' · Evento', $m[1], 'só conteúdos do tipo pedido');
        self::assertStringNotContainsString('name="dados[relacionada]" type="number"', $h);
    }

    public function testReferenciaSemNenhumConteudoDoTipoVoltaAoCampoNumerico(): void
    {
        $this->s->classes->criar('galeria', [['nome' => 'x', 'tipo' => 'texto']]);
        $classe = $this->s->classes->criar('vitrine', [['nome' => 'destaque', 'tipo' => 'ref', 'classe' => 'galeria']]);
        $v = $this->s->objetos->criar($this->siteId, $classe, $this->inicioId, 'Vitrine', 'vitrine-1', [], 'publicado', []);
        $h = $this->html($this->req('GET', '/_pbl/objeto/' . $v->id . '/editar', $this->token('admin')));
        self::assertMatchesRegularExpression('#<input[^>]*name="dados\[destaque\]"[^>]*type="number"#', $h);
        self::assertDoesNotMatchRegularExpression('#<select[^>]*name="dados\[destaque\]"#', $h);
    }
}
