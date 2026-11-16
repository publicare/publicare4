<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Caminhos;
use Pbl\Console\ContaDestravarComando;
use Pbl\Console\ContaSenhaComando;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** `bin/pbl conta-senha` e `conta-destravar` (S-05): recuperação pela linha de comando, sem senha na auditoria. */
final class ContaComandosTest extends TestCase
{
    private const SENHA = 'uma senha bem longa 2026';
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Site', 'www.exemplo.org');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $this->s->usuarios->criar('admin', 'admin@exemplo.org', 'Admin', self::SENHA, true);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    public function testGerarMostraASenhaUmaVezEElaAutentica(): void
    {
        $t = new CommandTester(new ContaSenhaComando($this->caminhos));
        self::assertSame(0, $t->execute(['login' => 'admin', '--gerar' => true]));

        self::assertSame(1, preg_match('/anote em lugar seguro\): (\S{24})$/m', $t->getDisplay(), $m));
        $nova = (string) ($m[1] ?? '');
        $s2 = Servicos::doBancoInstalado($this->caminhos);
        self::assertNotNull($s2->usuarios->autenticar('admin', $nova));
        self::assertNull($s2->usuarios->autenticar('admin', self::SENHA));

        $auditoria = (string) json_encode($s2->auditoria->recentes(20));
        self::assertStringNotContainsString($nova, $auditoria);
        self::assertStringContainsString('senha.trocar', $auditoria);
        self::assertStringContainsString('cli', $auditoria);
    }

    public function testSenhaPerguntadaDeFormaInterativaEValidadaPelaPolitica(): void
    {
        $t = new CommandTester(new ContaSenhaComando($this->caminhos));
        $t->setInputs(['curta', 'curta']);
        self::assertSame(1, $t->execute(['login' => 'admin']));
        self::assertStringContainsString('pelo menos', $t->getDisplay());

        $t = new CommandTester(new ContaSenhaComando($this->caminhos));
        $t->setInputs(['outra senha comprida 77', 'outra senha comprida 77']);
        self::assertSame(0, $t->execute(['login' => 'admin']));
        self::assertNotNull(Servicos::doBancoInstalado($this->caminhos)->usuarios->autenticar('admin', 'outra senha comprida 77'));
        self::assertStringNotContainsString('outra senha comprida 77', $t->getDisplay());
    }

    public function testContaInexistenteFalha(): void
    {
        $t = new CommandTester(new ContaSenhaComando($this->caminhos));
        self::assertSame(1, $t->execute(['login' => 'ninguem', '--gerar' => true]));
        $t = new CommandTester(new ContaDestravarComando($this->caminhos));
        self::assertSame(1, $t->execute(['login' => 'ninguem']));
    }

    public function testDestravarLiberaOLoginEAudita(): void
    {
        $limite = $this->s->limiteDeTentativas();
        for ($i = 0; $i < 5; $i++) {
            $limite->registrarFalha('admin', '203.0.113.5');
        }
        self::assertNotNull($limite->bloqueadoPor('admin', '203.0.113.5'));

        $t = new CommandTester(new ContaDestravarComando($this->caminhos));
        self::assertSame(0, $t->execute(['login' => 'admin']));

        self::assertNull($limite->bloqueadoPor('admin', '203.0.113.5'));
        $log = $this->s->auditoria->recentes(1)[0];
        self::assertSame('conta.destravar', $log['acao']);
        self::assertSame('cli', $log['ator']);
    }

    public function testDestravarComIpLiberaAOrigemEGuardaSoOHash(): void
    {
        $limite = $this->s->limiteDeTentativas();
        for ($i = 0; $i < 30; $i++) {
            $limite->registrarFalha("x{$i}", '203.0.113.5');
        }
        $t = new CommandTester(new ContaDestravarComando($this->caminhos));
        self::assertSame(0, $t->execute(['login' => 'admin', '--ip' => '203.0.113.5']));
        self::assertNull($limite->bloqueadoPor('y', '203.0.113.5'));
        self::assertStringNotContainsString('203.0.113.5', (string) json_encode($this->s->auditoria->recentes(5)));
        self::assertSame(1, (new CommandTester(new ContaDestravarComando($this->caminhos)))->execute(['login' => 'admin', '--ip' => 'não-é-ip']));
    }
}
