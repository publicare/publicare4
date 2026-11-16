<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Aplicacao;
use Pbl\Caminhos;
use Pbl\Instalacao\Instalador;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Antes da instalação, a raiz mostra uma página em português explicando como instalar, sem login nem erro. */
final class NaoInstaladoTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function pegar(string $caminho): Response
    {
        return (new Aplicacao($this->caminhos))->tratar(Request::create('https://www.exemplo.org' . $caminho, 'GET', [], [], [], ['REMOTE_ADDR' => '198.51.100.7']));
    }

    public function testRaizExplicaComoInstalar(): void
    {
        $r = $this->pegar('/');
        $html = (string) $r->getContent();

        self::assertSame(503, $r->getStatusCode());
        self::assertStringStartsWith('text/html', (string) $r->headers->get('Content-Type'));
        self::assertStringContainsString('lang="pt-BR"', $html);
        self::assertStringContainsString('ainda não foi instalado', $html);
        self::assertStringContainsString('bin/pbl instalar', $html);
        self::assertStringContainsString('/_pbl/instalar', $html);
        self::assertStringContainsString('instalar.liberar', $html);
        self::assertStringNotContainsStringIgnoringCase('<form', $html);
        self::assertStringNotContainsString('password', $html);
        self::assertStringNotContainsString($this->raiz, $html, 'nada de caminho do disco');
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('style=', $html);
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        self::assertNotNull($r->headers->get('Retry-After'));
        self::assertStringContainsString('noindex', (string) $r->headers->get('X-Robots-Tag'));
    }

    public function testQualquerEnderecoAntesDaInstalacaoMostraAPagina(): void
    {
        foreach (['/_pbl/entrar', '/qualquer/coisa', '/_pbl/instalar/outra'] as $caminho) {
            $r = $this->pegar($caminho);
            self::assertSame(503, $r->getStatusCode(), $caminho);
            self::assertStringContainsString('bin/pbl instalar', (string) $r->getContent(), $caminho);
        }
    }

    public function testAssistenteWebContinuaNoSeuCaminho(): void
    {
        $r = $this->pegar('/_pbl/instalar');
        self::assertStringNotContainsString('ainda não foi instalado', (string) $r->getContent());
    }

    public function testTemOsCabecalhosDeSeguranca(): void
    {
        $r = $this->pegar('/');
        self::assertStringContainsString("script-src 'self'", (string) $r->headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        self::assertNotNull($r->headers->get('Permissions-Policy'));
    }

    public function testInstaladoARaizNaoMostraAPagina(): void
    {
        (new Instalador($this->caminhos))->instalar('Site', 'www.exemplo.org');
        $r = $this->pegar('/');
        self::assertStringNotContainsString('ainda não foi instalado', (string) $r->getContent());
        self::assertNotSame(503, $r->getStatusCode());
    }

    public function testTravaSemBancoNaoMandaInstalarPorCimaDeUmaInstalacao(): void
    {
        (new Instalador($this->caminhos))->instalar('Site', 'www.exemplo.org');
        unlink($this->caminhos->banco());
        $r = $this->pegar('/');

        self::assertSame(503, $r->getStatusCode());
        self::assertStringNotContainsString('bin/pbl instalar', (string) $r->getContent());
    }
}
