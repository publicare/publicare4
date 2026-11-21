<?php

declare(strict_types=1);

namespace Pbl\Testes\Backup;

use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use Pbl\Caminhos;
use Pbl\Console\BackupComando;
use Pbl\Testes\Ajuda;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Smoke do fluxo crítico: criar → listar → enviar (indisponível) sem tocar na cópia local. */
final class BackupSmokeTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::pastaTemporaria();
        $this->caminhos = new Caminhos($this->raiz);
        $this->caminhos->garantirDados();
        (new Migrador(Conexao::sqlite($this->caminhos->banco())))->migrar();
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    public function testCriarListarEEnviarFalhandoPreservaACopiaLocal(): void
    {
        $t = new CommandTester(new BackupComando($this->caminhos, static fn (): int => 1773112200));
        self::assertSame(0, $t->execute(['acao' => 'criar']));
        $antes = glob($this->raiz . '/dados/backups/*.zip') ?: [];
        self::assertCount(1, $antes);

        self::assertSame(1, $t->execute(['acao' => 'enviar']));
        self::assertSame($antes, glob($this->raiz . '/dados/backups/*.zip') ?: []);
        self::assertFileExists($antes[0]);
        self::assertSame([], glob($this->raiz . '/dados/backups/*.tmp') ?: [], 'sem resíduos temporários');
        self::assertStringNotContainsString($this->raiz . '/dados/config', $t->getDisplay());
    }

    public function testPacoteNaoFicaLegivelPorOutrosUsuarios(): void
    {
        $t = new CommandTester(new BackupComando($this->caminhos, static fn (): int => 1773112200));
        $t->execute(['acao' => 'criar']);
        $zip = (glob($this->raiz . '/dados/backups/*.zip') ?: [''])[0];
        self::assertSame(0, fileperms($zip) & 0077, 'pacote com segredos deve ser 0600');
    }
}
