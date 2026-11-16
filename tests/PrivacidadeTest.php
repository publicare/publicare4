<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Caminhos;
use Pbl\Console\ContaAnonimizarComando;
use Pbl\Console\ContaExportarComando;
use Pbl\Cron\Tarefas\RetencaoTarefa;
use Pbl\Instalacao\Instalador;
use Pbl\Privacidade\PoliticaDeRetencao;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** LGPD: retenção na auditoria com limpeza no cron, exportação e anonimização de conta. */
final class PrivacidadeTest extends TestCase
{
    private const SENHA = 'uma senha bem longa 2026';
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;
    private int $bia;
    private int $agora = 1_800_000_000;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Site', 'www.exemplo.org');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $this->s->usuarios->criar('admin', 'admin@exemplo.org', 'Admin', self::SENHA, true);
        $this->bia = $this->s->usuarios->criar('bia', 'bia@exemplo.org', 'Beatriz Silva', self::SENHA, false, 'cli')->id;
        $this->s->usuarios->conceder($this->bia, 1, \Pbl\Acesso\Perfil::Editor, 'cli');
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function linhaAntiga(string $acao, int $diasAtras, ?string $ip = 'ab12'): int
    {
        $this->s->conexao()->insert('auditoria', ['quando' => gmdate('c', $this->agora - $diasAtras * 86400), 'usuario_id' => $this->bia, 'ator' => 'usuario:' . $this->bia, 'acao' => $acao, 'ip_hash' => $ip]);

        return (int) $this->s->conexao()->lastInsertId();
    }

    private function config(string $json): void
    {
        @mkdir($this->caminhos->config(), 0775, true);
        file_put_contents($this->caminhos->config() . '/privacidade.json', $json);
    }

    public function testPoliticaPadraoEConfiguracaoInvalida(): void
    {
        $p = PoliticaDeRetencao::dosCaminhos($this->caminhos);
        self::assertSame(90, $p->auditoriaIpDias);
        self::assertSame(0, $p->auditoriaDias);
        $this->config('{"auditoria_ip_dias": 30, "auditoria_dias": "x"}');
        $p = PoliticaDeRetencao::dosCaminhos($this->caminhos);
        self::assertSame(30, $p->auditoriaIpDias);
        self::assertSame(0, $p->auditoriaDias);
    }

    public function testRetencaoZeraSoOIpAntigoEPreservaALinha(): void
    {
        $velha = $this->linhaAntiga('login.ok', 100);
        $nova = $this->linhaAntiga('login.ok', 10);
        $t = new RetencaoTarefa(fn (): int => $this->agora);
        self::assertTrue($t->temTrabalho($this->s));

        $r = $t->executar($this->s, microtime(true) + 5);

        self::assertStringContainsString('1 IP(s)', $r->resumo);
        $c = $this->s->conexao();
        self::assertNull($c->fetchOne('SELECT ip_hash FROM auditoria WHERE id = ?', [$velha]));
        self::assertSame('login.ok', $c->fetchOne('SELECT acao FROM auditoria WHERE id = ?', [$velha]), 'a linha continua');
        self::assertSame('ab12', $c->fetchOne('SELECT ip_hash FROM auditoria WHERE id = ?', [$nova]));
        self::assertFalse($t->temTrabalho($this->s), 'idempotente');
    }

    public function testPrazoZeroNaoLimpaENuncaApagaLinhaPorPadrao(): void
    {
        $this->config('{"auditoria_ip_dias": 0}');
        $id = $this->linhaAntiga('login.ok', 4000);
        $t = new RetencaoTarefa(fn (): int => $this->agora);
        self::assertFalse($t->temTrabalho($this->s));
        $t->executar($this->s, microtime(true) + 5);
        self::assertSame('ab12', $this->s->conexao()->fetchOne('SELECT ip_hash FROM auditoria WHERE id = ?', [$id]));
    }

    public function testAuditoriaDiasApagaLinhasAntigas(): void
    {
        $this->config('{"auditoria_dias": 365}');
        $velha = $this->linhaAntiga('login.ok', 400, null);
        $nova = $this->linhaAntiga('login.ok', 5, null);
        $t = new RetencaoTarefa(fn (): int => $this->agora);
        self::assertTrue($t->temTrabalho($this->s));
        $t->executar($this->s, microtime(true) + 5);
        self::assertFalse($this->s->conexao()->fetchOne('SELECT 1 FROM auditoria WHERE id = ?', [$velha]));
        self::assertNotFalse($this->s->conexao()->fetchOne('SELECT 1 FROM auditoria WHERE id = ?', [$nova]));
    }

    public function testExportarTemOsDadosENaoTemASenha(): void
    {
        $this->linhaAntiga('login.ok', 1);
        $t = new CommandTester(new ContaExportarComando($this->caminhos));
        self::assertSame(0, $t->execute(['login' => 'bia']));

        $json = $t->getDisplay();
        $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('bia@exemplo.org', $d['conta']['email']);
        self::assertSame('Beatriz Silva', $d['conta']['nome']);
        self::assertSame([['objeto_id' => 1, 'perfil' => 'editor']], $d['acessos']);
        $acoes = array_column($d['auditoria'], 'acao');
        self::assertContains('login.ok', $acoes);
        self::assertContains('usuario.criar', $acoes, 'inclui a linha em que o login aparece no detalhe');
        self::assertStringNotContainsString('senha_hash', $json);
        $hash = (string) $this->s->conexao()->fetchOne('SELECT senha_hash FROM usuario WHERE id = ?', [$this->bia]);
        self::assertStringNotContainsString($hash, $json);
        self::assertStringNotContainsString('admin@exemplo.org', $json, 'não vaza dado de outra conta');
        self::assertSame('conta.exportar', $this->s->auditoria->recentes(1)[0]['acao']);
    }

    public function testAnonimizarSemConfirmarNaoMudaNada(): void
    {
        $t = new CommandTester(new ContaAnonimizarComando($this->caminhos));
        self::assertSame(0, $t->execute(['login' => 'bia']));
        self::assertStringContainsString('Nada foi alterado', $t->getDisplay());
        self::assertSame('bia@exemplo.org', $this->s->usuarios->porId($this->bia)?->email);
    }

    public function testAnonimizarRemoveODadoPessoalEPreservaATrilha(): void
    {
        $this->linhaAntiga('login.ok', 1);
        $sessao = $this->s->sessoes()->criar($this->bia);
        $antes = (int) $this->s->conexao()->fetchOne('SELECT COUNT(*) FROM auditoria WHERE usuario_id = ?', [$this->bia]);

        $t = new CommandTester(new ContaAnonimizarComando($this->caminhos));
        self::assertSame(0, $t->execute(['login' => 'bia', '--confirmar' => true]));

        $s = Servicos::doBancoInstalado($this->caminhos);
        $u = $s->usuarios->porId($this->bia);
        self::assertNotNull($u);
        self::assertSame("anonimo-{$this->bia}", $u->login);
        self::assertSame('Conta anonimizada', $u->nome);
        self::assertFalse($u->ativo);
        self::assertNull($s->usuarios->porLogin('bia'));
        self::assertNull($s->usuarios->autenticar('bia', self::SENHA), 'o login antigo não autentica mais');
        self::assertNull($s->usuarios->autenticar("anonimo-{$this->bia}", self::SENHA));
        self::assertSame([], $s->usuarios->acessosDe($this->bia));
        self::assertSame(0, (int) $s->conexao()->fetchOne('SELECT COUNT(*) FROM sessao WHERE usuario_id = ?', [$this->bia]));
        self::assertNotEmpty($sessao);

        $todo = (string) json_encode($s->conexao()->fetchAllAssociative('SELECT * FROM auditoria'), JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('"bia"', $todo);
        self::assertStringNotContainsString('bia@exemplo.org', $todo);
        self::assertSame(0, (int) $s->conexao()->fetchOne('SELECT COUNT(*) FROM auditoria WHERE usuario_id = ? AND ip_hash IS NOT NULL', [$this->bia]));
        self::assertGreaterThanOrEqual($antes, (int) $s->conexao()->fetchOne('SELECT COUNT(*) FROM auditoria WHERE usuario_id = ?', [$this->bia]), 'a trilha foi mantida');
        $ultima = $s->auditoria->recentes(1)[0];
        self::assertSame('conta.anonimizar', $ultima['acao']);
        self::assertSame('cli', $ultima['ator']);
    }

    public function testNaoAnonimizaAUltimaContaDeAdministradorAtiva(): void
    {
        $t = new CommandTester(new ContaAnonimizarComando($this->caminhos));
        self::assertSame(1, $t->execute(['login' => 'admin', '--confirmar' => true]));
        self::assertStringContainsString('última conta de administrador', $t->getDisplay());
        self::assertSame('admin@exemplo.org', $this->s->usuarios->porLogin('admin')?->email);
    }

    public function testComandosFalhamComContaInexistente(): void
    {
        self::assertSame(1, (new CommandTester(new ContaExportarComando($this->caminhos)))->execute(['login' => 'ninguem']));
        self::assertSame(1, (new CommandTester(new ContaAnonimizarComando($this->caminhos)))->execute(['login' => 'ninguem', '--confirmar' => true]));
    }
}
