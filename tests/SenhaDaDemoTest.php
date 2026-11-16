<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Caminhos;
use Pbl\Console\DemoSenhaComando;
use Pbl\Demo\SenhaDaDemo;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Senha do administrador da demo (S-03): aleatória, gravada com 0600 e atomicamente, mostrada só pelo comando. */
final class SenhaDaDemoTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        mkdir($this->raiz . '/dados/config', 0775, true);
        $this->caminhos = new Caminhos($this->raiz);
    }

    protected function tearDown(): void
    {
        putenv('PBL_DEMO');
        Ajuda::apagar($this->raiz);
    }

    public function testGeraSenhasLongasEDiferentes(): void
    {
        $a = SenhaDaDemo::gerar();
        self::assertGreaterThanOrEqual(24, strlen($a));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/D', $a);
        self::assertNotSame($a, SenhaDaDemo::gerar());
    }

    public function testGravaComModo0600EmDadosDemoSemRestos(): void
    {
        $senha = SenhaDaDemo::gerar();
        SenhaDaDemo::gravar($this->caminhos, $senha);

        $arquivo = $this->raiz . '/dados/demo/senha-admin';
        self::assertFileExists($arquivo);
        self::assertSame(0600, fileperms($arquivo) & 0777);
        self::assertSame($senha, SenhaDaDemo::ler($this->caminhos));
        self::assertSame(['senha-admin'], array_values(array_diff(scandir($this->raiz . '/dados/demo') ?: [], ['.', '..'])));

        SenhaDaDemo::gravar($this->caminhos, 'outra');
        self::assertSame('outra', SenhaDaDemo::ler($this->caminhos));
        self::assertSame(0600, fileperms($arquivo) & 0777);
    }

    public function testLerSemArquivoDevolveNulo(): void
    {
        self::assertNull(SenhaDaDemo::ler($this->caminhos));
    }

    public function testComandoRecusaSemFlagDeDemo(): void
    {
        SenhaDaDemo::gravar($this->caminhos, 'segredo-de-teste');
        $c = new CommandTester(new DemoSenhaComando($this->caminhos));
        self::assertSame(1, $c->execute([]));
        self::assertStringNotContainsString('segredo-de-teste', $c->getDisplay());
    }

    public function testComandoRecusaSemArquivoEMandaReiniciar(): void
    {
        file_put_contents($this->raiz . '/dados/config/demo.json', '{"demo": true}');
        $c = new CommandTester(new DemoSenhaComando($this->caminhos));
        self::assertSame(1, $c->execute([]));
        self::assertStringContainsString('demo-resetar', $c->getDisplay());
    }

    public function testComandoImprimeASenhaAtual(): void
    {
        file_put_contents($this->raiz . '/dados/config/demo.json', '{"demo": true}');
        SenhaDaDemo::gravar($this->caminhos, 'segredo-de-teste');
        $c = new CommandTester(new DemoSenhaComando($this->caminhos));
        self::assertSame(0, $c->execute([]));
        self::assertStringContainsString('segredo-de-teste', $c->getDisplay());
    }
}
