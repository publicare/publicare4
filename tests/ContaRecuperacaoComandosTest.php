<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\TokenDeConta;
use Pbl\Caminhos;
use Pbl\Console\ContaConvidarComando;
use Pbl\Console\ContaRecuperarComando;
use Pbl\Email\ConfigDeEmail;
use Pbl\Email\Transporte;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** `bin/pbl conta-recuperar` e `conta-convidar`: paridade com o painel, sem senha no terminal nem no log. */
final class ContaRecuperacaoComandosTest extends TestCase
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
        $this->s->usuarios->criar('ana', 'ana@exemplo.org', 'Ana', self::SENHA, false);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function arquivo(): void
    {
        $this->s->configDeEmail()->salvar(new ConfigDeEmail(Transporte::ARQUIVO, remetente: 'Site <nao@exemplo.org>'));
    }

    /** @return list<string> */
    private function caixa(): array
    {
        return glob($this->caminhos->dados() . '/email/caixa/*.eml') ?: [];
    }

    private function tokenDe(string $saida): string
    {
        self::assertSame(1, preg_match('#/([0-9a-f]{64})\s*$#m', $saida, $m), $saida);

        return $m[1] ?? '';
    }

    public function testRecuperarSemSmtpImprimeLinkQueRedefineEUsaSoUmaVez(): void
    {
        $t = new CommandTester(new ContaRecuperarComando($this->caminhos));
        self::assertSame(0, $t->execute(['login' => 'ana']));
        $token = $this->tokenDe($t->getDisplay());
        self::assertStringNotContainsString(self::SENHA, $t->getDisplay());
        self::assertSame([], $this->caixa());

        $tokens = Servicos::doBancoInstalado($this->caminhos)->tokensDeConta();
        self::assertNotNull($tokens->consultar($token, TokenDeConta::SENHA));
        self::assertNotNull($tokens->consumir($token, TokenDeConta::SENHA));
        self::assertNull($tokens->consultar($token, TokenDeConta::SENHA));

        $auditoria = (string) json_encode($this->s->auditoria->recentes(50));
        self::assertStringNotContainsString($token, $auditoria);
        self::assertStringNotContainsString(hash('sha256', $token), $auditoria);
    }

    public function testRecuperarComEmailEnviaSemImprimirOLink(): void
    {
        $this->arquivo();
        $t = new CommandTester(new ContaRecuperarComando($this->caminhos));
        self::assertSame(0, $t->execute(['login' => 'ana', '--email' => true]));
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $t->getDisplay());
        self::assertCount(1, $this->caixa());
    }

    public function testRecuperarEmailSemSmtpELoginInexistenteFalham(): void
    {
        $t = new CommandTester(new ContaRecuperarComando($this->caminhos));
        self::assertSame(1, $t->execute(['login' => 'ana', '--email' => true]));
        self::assertStringContainsString('não está configurado', $t->getDisplay());
        self::assertSame(1, $t->execute(['login' => 'fantasma']));
        self::assertStringContainsString('não encontrado', $t->getDisplay());
    }

    public function testConvidarSemEnvioImprimeLinkEAContaFicaPendente(): void
    {
        $t = new CommandTester(new ContaConvidarComando($this->caminhos));
        self::assertSame(0, $t->execute(['login' => 'beto', 'email' => 'beto@exemplo.org', '--sem-envio' => true]));
        $token = $this->tokenDe($t->getDisplay());
        $s2 = Servicos::doBancoInstalado($this->caminhos);
        $u = $s2->usuarios->porLogin('beto');
        self::assertNotNull($u);
        self::assertTrue($s2->usuarios->convitePendente($u->id));
        self::assertNotNull($s2->tokensDeConta()->consultar($token, TokenDeConta::CONVITE));
        self::assertNull($s2->tokensDeConta()->consultar($token, TokenDeConta::SENHA));
    }

    public function testConvidarComEnvioGravaEmailERecusaRepetido(): void
    {
        $this->arquivo();
        $t = new CommandTester(new ContaConvidarComando($this->caminhos));
        self::assertSame(0, $t->execute(['login' => 'beto', 'email' => 'beto@exemplo.org']));
        self::assertCount(1, $this->caixa());
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $t->getDisplay());
        self::assertSame(1, $t->execute(['login' => 'beto', 'email' => 'outro@exemplo.org']));
        self::assertSame(1, $t->execute(['login' => 'novo', 'email' => 'ana@exemplo.org']));
    }
}
