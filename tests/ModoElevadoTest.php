<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\ModoElevado;
use Pbl\Caminhos;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;

/**
 * `ModoElevado` isolado (ADR 0011 §11, ADR 0012 §13, fase 5h): confirmação de senha, janela de 10 min, limite de
 * tentativas, expiração, `$sempreExigir` e o que a auditoria guarda. Os testes de ponta a ponta (cada tela) ficam
 * em `AdminContasTest`, `AdminDominiosTest`, `AdminSiteTest`, `AdminClassesTest` e `AdminAtualizacaoTest`.
 */
final class ModoElevadoTest extends TestCase
{
    private const SENHA = 'uma senha bem longa 2026';
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;
    private int $usuarioId;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Meu Site', 'www.exemplo.org');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $this->usuarioId = $this->s->usuarios->criar('admin', 'admin@exemplo.org', 'Admin', self::SENHA, true)->id;
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    public function testSessaoComecaSemModoElevado(): void
    {
        $sessao = $this->sessao();
        self::assertFalse($this->s->modoElevado()->ativo($sessao));
    }

    public function testSenhaCertaAbreOModoElevado(): void
    {
        $sessao = $this->sessao();
        $modo = $this->s->modoElevado();
        $falha = $modo->confirmar($sessao, self::SENHA, '203.0.113.1', 'teste');
        self::assertNull($falha);
        self::assertTrue($modo->ativo($sessao));
    }

    public function testSenhaErradaNaoAbreENaoRevelaOMotivo(): void
    {
        $sessao = $this->sessao();
        $modo = $this->s->modoElevado();
        $falha = $modo->confirmar($sessao, 'senha-errada-qualquer', '203.0.113.1', 'teste');
        self::assertNotNull($falha);
        self::assertSame('Senha incorreta.', $falha['mensagem']);
        self::assertSame(401, $falha['status']);
        self::assertFalse($modo->ativo($sessao));
        $log = $this->s->auditoria->recentes(10);
        self::assertSame('teste.senha_errada', $log[0]['acao']);
        self::assertStringNotContainsString('senha-errada-qualquer', (string) json_encode($log));
    }

    public function testLimiteDeTentativasBloqueiaComoNoLogin(): void
    {
        $sessao = $this->sessao();
        $modo = $this->s->modoElevado();
        for ($i = 0; $i < 5; $i++) {
            $modo->confirmar($sessao, 'errada', '203.0.113.9', 'teste');
        }
        $falha = $modo->confirmar($sessao, self::SENHA, '203.0.113.9', 'teste');
        self::assertNotNull($falha);
        self::assertSame(429, $falha['status']);
        self::assertGreaterThan(0, $falha['retryAfter']);
        self::assertFalse($modo->ativo($sessao), 'nem com a senha certa, bloqueado é bloqueado');
        self::assertSame('teste.bloqueado', $this->s->auditoria->recentes(1)[0]['acao']);
    }

    public function testJanelaExpiraSozinha(): void
    {
        $agora = 1_000_000;
        $relogio = static function () use (&$agora): int {
            return $agora;
        };
        $sessao = $this->sessao();
        $modo = $this->s->modoElevado($relogio);
        self::assertNull($modo->confirmar($sessao, self::SENHA, '203.0.113.1', 'teste'));
        self::assertTrue($modo->ativo($sessao));
        $agora += ModoElevado::DURACAO - 1;
        self::assertTrue($modo->ativo($sessao), 'ainda dentro da janela');
        $agora += 2;
        self::assertFalse($modo->ativo($sessao), 'a janela venceu');
    }

    public function testSempreExigirPedeASenhaMesmoJaElevado(): void
    {
        $sessao = $this->sessao();
        $modo = $this->s->modoElevado();
        self::assertNull($modo->confirmar($sessao, self::SENHA, '203.0.113.1', 'teste'));
        self::assertTrue($modo->ativo($sessao));
        // Sem $sempreExigir: já elevado, não confere nada (nem precisaria da senha certa).
        self::assertNull($modo->confirmar($sessao, 'nao importa', '203.0.113.1', 'teste'));
        // Com $sempreExigir: confere de novo, mesmo elevado.
        $falha = $modo->confirmar($sessao, 'senha errada', '203.0.113.1', 'atualizacao', true);
        self::assertNotNull($falha);
        self::assertSame('Senha incorreta.', $falha['mensagem']);
    }

    public function testConfirmarComSempreExigirRenovaAJanela(): void
    {
        $sessao = $this->sessao();
        $modo = $this->s->modoElevado();
        self::assertNull($modo->confirmar($sessao, self::SENHA, '203.0.113.1', 'atualizacao', true));
        self::assertTrue($modo->ativo($sessao), 'confirmar com sucesso eleva a sessão também quando $sempreExigir é verdadeiro');
    }

    public function testTrocarASenhaEncerraASessaoEOModoElevadoComEla(): void
    {
        $token = $this->s->sessoes()->criar($this->usuarioId);
        $sessao = $this->s->sessoes()->validar($token) ?? self::fail();
        self::assertNull($this->s->modoElevado()->confirmar($sessao, self::SENHA, '203.0.113.1', 'teste'));
        $this->s->usuarios->definirSenha($this->usuarioId, 'uma senha nova bem longa 2026');
        self::assertNull($this->s->sessoes()->validar($token), 'a sessão (e o modo elevado com ela) não existe mais');
    }

    public function testSairEncerraASessaoEOModoElevadoComEla(): void
    {
        $token = $this->s->sessoes()->criar($this->usuarioId);
        $sessao = $this->s->sessoes()->validar($token) ?? self::fail();
        self::assertNull($this->s->modoElevado()->confirmar($sessao, self::SENHA, '203.0.113.1', 'teste'));
        $this->s->sessoes()->encerrar($token);
        self::assertNull($this->s->sessoes()->validar($token));
    }

    private function sessao(): \Pbl\Acesso\SessaoAtiva
    {
        $token = $this->s->sessoes()->criar($this->usuarioId);

        return $this->s->sessoes()->validar($token) ?? self::fail('sessão deveria existir');
    }
}
