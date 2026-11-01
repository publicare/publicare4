<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Caminhos;
use Pbl\Console\UsuarioComando;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class UsuarioComandoTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;
    private CommandTester $t;
    private const SENHA = 'uma senha bem longa 2026';

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Site', 'www.exemplo.org');
        $this->t = new CommandTester(new UsuarioComando($this->caminhos));
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    public function testCriarPedeASenhaDuasVezesENuncaAAceitaNaLinhaDeComando(): void
    {
        $this->t->setInputs([self::SENHA, self::SENHA]);
        self::assertSame(0, $this->t->execute(['acao' => 'criar', 'login' => 'ana', '--email' => 'ana@exemplo.org', '--nome' => 'Ana', '--admin' => true]));
        self::assertStringContainsString('administrador global', $this->t->getDisplay());
        self::assertStringNotContainsString(self::SENHA, $this->t->getDisplay(), 'a senha não é repetida na tela');
        self::assertNotNull(Servicos::doBancoInstalado($this->caminhos)->usuarios->autenticar('ana', self::SENHA));

        $this->t->setInputs([self::SENHA, 'outra senha completamente diferente']);
        self::assertSame(1, $this->t->execute(['acao' => 'criar', 'login' => 'bia', '--email' => 'bia@exemplo.org']));
        self::assertStringContainsString('não conferem', $this->t->getDisplay());
        self::assertNull(Servicos::doBancoInstalado($this->caminhos)->usuarios->porLogin('bia'));
    }

    public function testRecusaSenhaFraca(): void
    {
        $this->t->setInputs(['curta', 'curta']);

        self::assertSame(1, $this->t->execute(['acao' => 'criar', 'login' => 'ana', '--email' => 'ana@exemplo.org']));
        self::assertStringContainsString('pelo menos 10', $this->t->getDisplay());
    }

    public function testListarConcederRevogarDesativarEAuditoria(): void
    {
        $this->t->setInputs([self::SENHA, self::SENHA]);
        $this->t->execute(['acao' => 'criar', 'login' => 'ana', '--email' => 'ana@exemplo.org', '--nome' => 'Ana']);
        $raizId = Servicos::doBancoInstalado($this->caminhos)->sites->todos()[0]->objetoRaizId;

        self::assertSame(0, $this->t->execute(['acao' => 'conceder', 'login' => 'ana', 'a' => 'editor', 'b' => (string) $raizId]));
        $this->t->execute(['acao' => 'acessos', 'login' => 'ana']);
        self::assertMatchesRegularExpression("/editor\\s+objeto {$raizId}/", $this->t->getDisplay());
        self::assertSame(1, $this->t->execute(['acao' => 'conceder', 'login' => 'ana', 'a' => 'chefe', 'b' => '1']), 'perfil inventado');
        self::assertSame(0, $this->t->execute(['acao' => 'desativar', 'login' => 'ana']));
        $this->t->execute(['acao' => 'listar']);
        self::assertMatchesRegularExpression('/ana\s+equipe\s+inativo/', $this->t->getDisplay());
        self::assertSame(0, $this->t->execute(['acao' => 'revogar', 'login' => 'ana', 'a' => (string) $raizId]));
        self::assertSame(1, $this->t->execute(['acao' => 'ativar', 'login' => 'fantasma']));
        $this->t->execute(['acao' => 'auditoria']);
        foreach (['usuario.criar', 'acesso.conceder', 'usuario.desativar', 'acesso.revogar'] as $acao) {
            self::assertStringContainsString($acao, $this->t->getDisplay());
        }
        self::assertStringNotContainsString(self::SENHA, $this->t->getDisplay());
    }
}
