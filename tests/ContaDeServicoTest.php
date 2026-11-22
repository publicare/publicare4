<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Perfil;
use Pbl\Acesso\SessaoRepositorio;
use Pbl\Aplicacao;
use Pbl\Console\ContaSenhaComando;
use Pbl\Console\UsuarioComando;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;

/** Conta de serviço (ADR 0031): sem senha, sem painel, sem privilégio global, desativável a qualquer momento. */
final class ContaDeServicoTest extends TestCase
{
    use ConteudoAuxiliar;

    /**
     * @param array<string, mixed> $entrada
     * @return array{0: int, 1: string}
     */
    private function usuarioCmd(array $entrada): array
    {
        $t = new CommandTester(new UsuarioComando($this->caminhos));
        $codigo = $t->execute($entrada);

        return [$codigo, $t->getDisplay()];
    }

    public function testCriarPeloCliSemSenhaNemEmail(): void
    {
        [$codigo, $saida] = $this->usuarioCmd(['acao' => 'criar', 'login' => 'robo', '--servico' => true, '--nome' => 'Robô de teste']);

        self::assertSame(0, $codigo, $saida);
        $u = $this->servicos()->usuarios->porLogin('robo');
        self::assertNotNull($u);
        self::assertTrue($u->ehServico());
        self::assertFalse($u->administrador);
        self::assertTrue($u->ativo);
        self::assertSame('robo@servico.invalid', $u->email);
        self::assertSame('Robô de teste', $u->nome);
        $criar = array_values(array_filter($this->servicos()->auditoria->recentes(50), static fn (array $e): bool => $e['acao'] === 'usuario.criar' && str_contains((string) $e['detalhe'], 'robo')));
        self::assertStringContainsString('"tipo":"servico"', (string) $criar[0]['detalhe']);
    }

    public function testServicoNaoCombinaComAdminEmailOuSenha(): void
    {
        foreach ([['--admin' => true], ['--email' => 'x@exemplo.org'], ['--senha-stdin' => true]] as $extra) {
            [$codigo, $saida] = $this->usuarioCmd(['acao' => 'criar', 'login' => 'robo2', '--servico' => true] + $extra);
            self::assertSame(1, $codigo);
            self::assertStringContainsString('Conta de serviço', $saida);
        }
        self::assertNull($this->servicos()->usuarios->porLogin('robo2'));
    }

    public function testNaoAutenticaComNenhumaSenha(): void
    {
        $u = $this->servicos()->usuarios;
        foreach (['', 'qualquer coisa', Perfil::class, '!servico', 'uma senha bem longa 2026'] as $tentativa) {
            self::assertNull($u->autenticar('claudim', $tentativa), 'senha: ' . $tentativa);
        }
    }

    public function testNaoEntraPeloPainel(): void
    {
        $r = Request::create('https://www.exemplo.org/_pbl/entrar', 'POST', ['login' => 'claudim', 'senha' => '!servico', '_csrf' => $this->s->csrf()->paraEntrar()], [], [], ['REMOTE_ADDR' => '198.51.100.7']);
        $resposta = (new Aplicacao($this->caminhos))->tratar($r);

        self::assertNotSame(303, $resposta->getStatusCode());
        $cookies = array_map(static fn ($c): string => $c->getName(), $resposta->headers->getCookies());
        self::assertNotContains('__Host-pbl4', $cookies, 'nenhuma sessão aberta');
    }

    public function testSessaoNaoSeAbreNemValidaParaConta(): void
    {
        try {
            $this->s->sessoes()->criar($this->ids['claudim']);
            self::fail('a sessão não podia ser criada');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Conta de serviço', $e->getMessage());
        }

        // Mesmo que alguém grave uma sessão direto no banco, a validação recusa (segunda camada).
        $token = bin2hex(random_bytes(32));
        $agora = time();
        $this->s->conexao()->insert('sessao', ['id_hash' => SessaoRepositorio::hash($token), 'usuario_id' => $this->ids['claudim'], 'criado_em' => $agora, 'expira_em' => $agora + 3600, 'ultimo_uso' => $agora]);
        self::assertNull($this->s->sessoes()->validar($token));
        $r = Request::create('https://www.exemplo.org/_pbl/', 'GET', [], ['__Host-pbl4' => $token], [], ['REMOTE_ADDR' => '198.51.100.7']);
        self::assertSame(303, (new Aplicacao($this->caminhos))->tratar($r)->getStatusCode(), 'o painel manda entrar');
    }

    public function testNaoTemSenhaParaTrocar(): void
    {
        try {
            $this->s->usuarios->definirSenha($this->ids['claudim'], 'uma senha bem longa 2026');
            self::fail('conta de serviço não tem senha');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('não tem senha', $e->getMessage());
        }
        $t = new CommandTester(new ContaSenhaComando($this->caminhos));
        self::assertSame(1, $t->execute(['login' => 'claudim', '--gerar' => true]));
        self::assertStringContainsString('não tem senha', $t->getDisplay());
        self::assertNull($this->servicos()->usuarios->autenticar('claudim', $t->getDisplay()));
    }

    public function testNuncaEhAdministradora(): void
    {
        try {
            $this->s->usuarios->definirAdministrador($this->ids['claudim'], true);
            self::fail('privilégio mínimo');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('não pode ser administradora', $e->getMessage());
        }
        self::assertFalse($this->servicos()->usuarios->porLogin('claudim')?->administrador);
    }

    public function testListarMostraOTipo(): void
    {
        [, $saida] = $this->usuarioCmd(['acao' => 'listar']);
        self::assertMatchesRegularExpression('/claudim\s+serviço\s+ativo/u', $saida);
        self::assertMatchesRegularExpression('/editor\s+equipe\s+ativo/', $saida);
    }

    public function testPapeisPorSecaoLimitamOQueAContaEnxerga(): void
    {
        $secao = $this->pagina('Seção A', 'secao-a');
        $fora = $this->pagina('Seção B', 'secao-b');
        $robo = $this->s->usuarios->criarContaDeServico('robo', 'Robô');
        $this->s->usuarios->conceder($robo->id, $secao, Perfil::Editor);

        [$codigo] = $this->cli(['acao' => 'ver', 'alvo' => (string) $secao, '--como' => 'robo', '--json' => null]);
        self::assertSame(0, $codigo);
        [$codigo] = $this->cli(['acao' => 'ver', 'alvo' => (string) $fora, '--como' => 'robo', '--json' => null]);
        self::assertSame(3, $codigo);
        [$codigo] = $this->cli(['acao' => 'editar', 'alvo' => (string) $secao, '--como' => 'robo', '--versao' => '1', '--campo' => ['titulo=Seção A2'], '--json' => null]);
        self::assertSame(0, $codigo);
        [$codigo, , $j] = $this->cli(['acao' => 'criar', '--pai' => '/', '--tipo' => 'pagina', '--como' => 'robo', '--campo' => ['titulo=Na raiz'], '--json' => null]);
        self::assertContains($codigo, [3, 4], 'sem papel na raiz');
        self::assertFalse($j['ok']);
    }

    public function testAdministradorDesativaEATodasAsAcoesPassamASerNegadas(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        [$codigo] = $this->cli(['acao' => 'ver', 'alvo' => (string) $id, '--como' => 'claudim', '--json' => null]);
        self::assertSame(0, $codigo);

        [$codigo] = $this->usuarioCmd(['acao' => 'desativar', 'login' => 'claudim']);
        self::assertSame(0, $codigo);
        foreach ([['acao' => 'ver', 'alvo' => (string) $id], ['acao' => 'listar'], ['acao' => 'editar', 'alvo' => (string) $id, '--versao' => '1', '--campo' => ['titulo=x']]] as $entrada) {
            [$codigo, , $j] = $this->cli($entrada + ['--como' => 'claudim', '--json' => null]);
            self::assertSame(4, $codigo, $entrada['acao']);
            self::assertSame('sem_permissao', $j['erro']['codigo']);
        }
        self::assertSame('Sobre nós', $this->objeto($id)->titulo);

        $this->usuarioCmd(['acao' => 'ativar', 'login' => 'claudim']);
        [$codigo] = $this->cli(['acao' => 'ver', 'alvo' => (string) $id, '--como' => 'claudim', '--json' => null]);
        self::assertSame(0, $codigo, 'reativar devolve o acesso');
    }

    public function testContaPessoaContinuaIgual(): void
    {
        $u = $this->servicos()->usuarios;
        self::assertNotNull($u->autenticar('editor', 'uma senha bem longa 2026'));
        self::assertFalse($u->porLogin('editor')?->ehServico());
        self::assertNotSame('', $this->s->sessoes()->criar($this->ids['editor']));
    }
}
