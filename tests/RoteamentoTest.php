<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Aplicacao;
use Pbl\Caminhos;
use Pbl\Conteudo\Dominio;
use Pbl\Conteudo\RedirecionamentoRepositorio;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Domínios (principal, servir, redirecionar) e regras manuais de redirecionamento, de ponta a ponta. */
final class RoteamentoTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $servicos;
    private int $siteId;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Site', 'www.exemplo.org');
        $this->servicos = Servicos::doBancoInstalado($this->caminhos);
        $site = $this->servicos->sites->todos()[0];
        $this->siteId = $site->id;
        $this->servicos->publicador->publicarSite($site);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function pegar(string $url, string $host = 'www.exemplo.org', string $esquema = 'https'): Response
    {
        return (new Aplicacao($this->caminhos))->tratar(Request::create("{$esquema}://{$host}{$url}"));
    }

    public function testDominioPrincipalServeNormalmente(): void
    {
        $r = $this->pegar('/sobre');

        self::assertSame(200, $r->getStatusCode());
        self::assertFalse($r->headers->has('Link'));
    }

    public function testDominioAntigoRedirecionaParaOPrincipalMantendoCaminhoEConsulta(): void
    {
        $this->servicos->dominios->adicionar($this->siteId, 'antigo.exemplo.org', Dominio::REDIRECIONAR);

        $r = $this->pegar('/sobre?aba=2&x=%C3%A1', 'antigo.exemplo.org');

        self::assertSame(301, $r->getStatusCode());
        self::assertSame('https://www.exemplo.org/sobre?aba=2&x=%C3%A1', $r->headers->get('Location'));
        self::assertSame('http://www.exemplo.org/', $this->pegar('/', 'antigo.exemplo.org', 'http')->headers->get('Location'), 'mantém o esquema');
    }

    public function testRedirecionamentoDeDominioNuncaUsaTextoDaRequisicaoNoHost(): void
    {
        $this->servicos->dominios->adicionar($this->siteId, 'antigo.exemplo.org', Dominio::REDIRECIONAR);

        $r = $this->pegar('//evil.example/x?next=https://evil.example', 'antigo.exemplo.org');

        self::assertSame(301, $r->getStatusCode());
        self::assertStringStartsWith('https://www.exemplo.org/', (string) $r->headers->get('Location'), 'o host é sempre o do cadastro');
    }

    public function testDominioQueServeMostraOMesmoConteudoComCanonicoNoCabecalho(): void
    {
        $this->servicos->dominios->adicionar($this->siteId, 'apelido.exemplo.org', Dominio::SERVIR);

        $r = $this->pegar('/sobre', 'apelido.exemplo.org');

        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('<h1>Sobre</h1>', (string) $r->getContent());
        self::assertSame('<https://www.exemplo.org/sobre>; rel="canonical"', $r->headers->get('Link'));
    }

    public function testTrocarOPrincipalFazOAntigoRedirecionar(): void
    {
        $this->servicos->dominios->adicionar($this->siteId, 'novo.exemplo.org', Dominio::SERVIR);

        $this->servicos->dominios->definirPrincipal($this->siteId, 'novo.exemplo.org');

        self::assertSame('novo.exemplo.org', $this->servicos->dominios->principalDe($this->siteId)?->host);
        self::assertSame(301, $this->pegar('/sobre', 'www.exemplo.org')->getStatusCode());
        self::assertSame('https://novo.exemplo.org/sobre', $this->pegar('/sobre', 'www.exemplo.org')->headers->get('Location'));
        self::assertSame(200, $this->pegar('/sobre', 'novo.exemplo.org')->getStatusCode());
    }

    public function testRegrasDeDominio(): void
    {
        $d = $this->servicos->dominios;
        $d->adicionar($this->siteId, 'a.exemplo.org', Dominio::SERVIR);

        foreach ([
            fn () => $d->adicionar($this->siteId, 'a.exemplo.org'),
            fn () => $d->adicionar($this->siteId, 'Mau Host!'),
            fn () => $d->adicionar($this->siteId, 'b.exemplo.org', 'qualquer'),
            fn () => $d->adicionar($this->siteId, 'c.exemplo.org', Dominio::PRINCIPAL),
            fn () => $d->adicionar(999, 'd.exemplo.org'),
            fn () => $d->remover('www.exemplo.org'),
            fn () => $d->remover('nao-existe.org'),
            fn () => $d->definirPrincipal($this->siteId, 'nao-existe.org'),
            fn () => $d->definirPrincipal($this->siteId, 'a.exemplo.org', 'principal'),
        ] as $operacao) {
            try {
                $operacao();
                self::fail('deveria recusar');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $d->remover('a.exemplo.org');
        self::assertNull($d->porHost('a.exemplo.org'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function hosts(): iterable
    {
        yield 'simples' => ['exemplo.org', true];
        yield 'subdomínio com hífen' => ['meu-site.exemplo.org.br', true];
        yield 'localhost' => ['localhost', true];
        yield 'punycode' => ['xn--exemplo-9cb.org', true];
        yield 'maiúscula' => ['Exemplo.org', false];
        yield 'espaço' => ['a b.org', false];
        yield 'ponto duplo' => ['a..org', false];
        yield 'hífen na ponta do rótulo' => ['-a.org', false];
        yield 'underline' => ['a_b.org', false];
        yield 'com porta' => ['exemplo.org:8080', false];
        yield 'rótulo com 64 caracteres' => [str_repeat('a', 64) . '.org', false];
        yield 'vazio' => ['', false];
    }

    #[DataProvider('hosts')]
    public function testHostValido(string $host, bool $esperado): void
    {
        self::assertSame($esperado, Dominio::hostValido($host));
    }

    public function testHostHostilNaoEstouraNemDemora(): void
    {
        $inicio = hrtime(true);
        foreach ([str_repeat('a-', 120) . '!', str_repeat('a.', 120) . '!', str_repeat('a', 5000) . '.org'] as $h) {
            self::assertFalse(Dominio::hostValido($h));
            self::assertSame(PREG_NO_ERROR, preg_last_error());
        }
        self::assertLessThan(50, (hrtime(true) - $inicio) / 1_000_000);
    }

    public function testHostInvalidoNaRequisicaoDa400(): void
    {
        $requisicao = Request::create('http://exemplo.org/');
        $requisicao->headers->set('Host', 'exemplo.org/..;@evil');

        self::assertSame(400, (new Aplicacao($this->caminhos))->tratar($requisicao)->getStatusCode());
    }

    public function testRegraManualExataParaEnderecoLegadoComMaiusculasEExtensao(): void
    {
        $this->servicos->redirecionamentos->adicionar($this->siteId, '/Index.php', '/sobre');
        $this->servicos->redirecionamentos->adicionar($this->siteId, '/Sistemas/Agenda.PHP', 'https://agenda.exemplo.org/', 302);

        $a = $this->pegar('/Index.php?cod=12');
        $b = $this->pegar('/Sistemas/Agenda.PHP');

        self::assertSame([301, '/sobre?cod=12'], [$a->getStatusCode(), $a->headers->get('Location')]);
        self::assertSame([302, 'https://agenda.exemplo.org/'], [$b->getStatusCode(), $b->headers->get('Location')]);
        self::assertSame(404, $this->pegar('/index.php')->getStatusCode(), 'case-sensitive: só o que foi cadastrado');
    }

    public function testRegraPorPrefixoPreservaORestoDoCaminho(): void
    {
        $this->servicos->redirecionamentos->adicionar($this->siteId, '/antigo', '/sobre', 301, true);
        $this->servicos->redirecionamentos->adicionar($this->siteId, '/antigo/docs', 'https://docs.exemplo.org', 301, true);

        self::assertSame('/sobre', $this->pegar('/antigo')->headers->get('Location'));
        self::assertSame('/sobre/a/b', $this->pegar('/antigo/a/b')->headers->get('Location'));
        self::assertSame('https://docs.exemplo.org/x/y', $this->pegar('/antigo/docs/x/y')->headers->get('Location'), 'o prefixo mais longo vence');
        self::assertSame(404, $this->pegar('/antigos')->getStatusCode(), 'prefixo é por segmento, não por texto');
    }

    public function testPaginaNoCacheVenceARegraManualEHistoricoVenceARegra(): void
    {
        $this->servicos->redirecionamentos->adicionar($this->siteId, '/sobre', '/outro');
        self::assertSame(200, $this->pegar('/sobre')->getStatusCode(), 'a página existe: a regra não a esconde');

        $classe = $this->servicos->classes->porNome('pagina');
        self::assertNotNull($classe);
        $o = $this->servicos->objetos->criar($this->siteId, $classe, 1, 'Guia', 'guia', []);
        $this->servicos->objetos->atualizar($o->id, ['url_amigavel' => 'manual']);
        $this->servicos->publicador->publicarSite($this->servicos->sites->todos()[0]);
        $this->servicos->redirecionamentos->adicionar($this->siteId, '/guia', '/regra');

        self::assertSame('/manual', $this->pegar('/guia')->headers->get('Location'), 'o histórico do conteúdo vem antes da regra manual');
    }

    /** @return iterable<string, array{string, string}> */
    public static function regrasInvalidas(): iterable
    {
        yield 'origem com ..' => ['/a/../b', '/novo'];
        yield 'origem com // ' => ['/a//b', '/novo'];
        yield 'origem com consulta' => ['/a?x=1', '/novo'];
        yield 'destino protocolo relativo (open redirect)' => ['/a', '//evil.example'];
        yield 'destino javascript' => ['/a', 'javascript:alert(1)'];
        yield 'destino http (não https)' => ['/a', 'http://exemplo.org/'];
        yield 'destino com credenciais' => ['/a', 'https://user:pass@exemplo.org/'];
        yield 'destino com host inválido' => ['/a', 'https://exe mplo.org/'];
        yield 'destino com ..' => ['/a', '/x/../y'];
        yield 'destino vazio' => ['/a', ''];
        yield 'destino com quebra de linha' => ['/a', "/x\r\nSet-Cookie: a=b"];
        yield 'destino com barra invertida' => ['/a', '/x\\y'];
    }

    #[DataProvider('regrasInvalidas')]
    public function testRegrasPerigosasOuMalFormadasSaoRecusadas(string $origem, string $destino): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->servicos->redirecionamentos->adicionar($this->siteId, $origem, $destino);
    }

    public function testRegrasDuplicadasCodigoInvalidoELimites(): void
    {
        $r = $this->servicos->redirecionamentos;
        $id = $r->adicionar($this->siteId, '/x', '/y');

        foreach ([fn () => $r->adicionar($this->siteId, '/x/', '/z'), fn () => $r->adicionar($this->siteId, '/q', '/z', 307), fn () => $r->adicionar(999, '/q', '/z'), fn () => $r->remover($this->siteId, 99999)] as $operacao) {
            try {
                $operacao();
                self::fail('deveria recusar');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $r->adicionar($this->siteId, '/x', '/z', 301, true); // exata e por prefixo da mesma origem convivem
        self::assertCount(2, $r->doSite($this->siteId));
        $r->remover($this->siteId, $id);
        self::assertCount(1, $r->doSite($this->siteId));
        self::assertNull($r->resolver($this->siteId, '/nada'));
        self::assertSame(RedirecionamentoRepositorio::MAX_PREFIXOS, 200);
    }

    public function testRegrasSaoPorSite(): void
    {
        $outro = $this->servicos->sites->criar('Outro', 'outro.exemplo.org');
        $this->servicos->redirecionamentos->adicionar($outro->id, '/so-do-outro', '/x');

        self::assertSame(404, $this->pegar('/so-do-outro')->getStatusCode());
    }

    public function testCaminhoGigantePedidoDa404SemConsultarOBanco(): void
    {
        self::assertSame(404, $this->pegar('/' . str_repeat('a', 5000))->getStatusCode());
    }
}
