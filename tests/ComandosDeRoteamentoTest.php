<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Caminhos;
use Pbl\Console\DominioComando;
use Pbl\Console\RedirecionamentoComando;
use Pbl\Instalacao\Instalador;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ComandosDeRoteamentoTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Site', 'www.exemplo.org');
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    public function testDominioAdicionarListarPrincipalRemover(): void
    {
        $t = new CommandTester(new DominioComando($this->caminhos));

        self::assertSame(0, $t->execute(['acao' => 'adicionar', 'host' => 'novo.exemplo.org', '--modo' => 'servir']));
        self::assertSame(0, $t->execute(['acao' => 'principal', 'host' => 'novo.exemplo.org']));
        $t->execute(['acao' => 'listar']);
        self::assertMatchesRegularExpression('/novo\.exemplo\.org\s+principal/', $t->getDisplay());
        self::assertMatchesRegularExpression('/www\.exemplo\.org\s+redirecionar/', $t->getDisplay());
        self::assertSame(0, $t->execute(['acao' => 'remover', 'host' => 'www.exemplo.org']));
        self::assertSame(1, $t->execute(['acao' => 'remover', 'host' => 'novo.exemplo.org']), 'o principal não pode ser removido');
        self::assertStringContainsString('principal não pode ser removido', $t->getDisplay());
        self::assertSame(1, $t->execute(['acao' => 'adicionar', 'host' => 'Host Inválido']));
        self::assertSame(1, $t->execute(['acao' => 'nada']));
    }

    public function testRedirecionamentoAdicionarListarRemover(): void
    {
        $t = new CommandTester(new RedirecionamentoComando($this->caminhos));

        self::assertSame(0, $t->execute(['acao' => 'adicionar', 'origem' => '/antigo', 'destino' => '/sobre', '--prefixo' => true]));
        self::assertSame(1, $t->execute(['acao' => 'adicionar', 'origem' => '/x', 'destino' => '//evil.example']), 'open redirect recusado');
        $t->execute(['acao' => 'listar']);
        self::assertMatchesRegularExpression('/301 \/antigo\/\*\s+→ \/sobre/', $t->getDisplay());
        self::assertSame(0, $t->execute(['acao' => 'remover', 'origem' => '1']));
        self::assertSame(1, $t->execute(['acao' => 'remover', 'origem' => '1']));
    }
}
