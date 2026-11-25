<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Doctrine\DBAL\Connection;
use Pbl\Acesso\Auditoria;
use Pbl\Acesso\EmailsDeConta;
use Pbl\Acesso\LimiteDeRecuperacao;
use Pbl\Acesso\RecuperacaoDeAcesso;
use Pbl\Acesso\SessaoRepositorio;
use Pbl\Acesso\TokenDeConta;
use Pbl\Acesso\UsuarioRepositorio;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migracoes\M029SenhaProvisoria;
use Pbl\Banco\Migrador;
use Pbl\Caminhos;
use Pbl\Console\ContaSenhaComando;
use Pbl\Console\InstalarComando;
use Pbl\Console\UsuarioComando;
use Pbl\Email\TransporteNulo;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Senha provisória (ADR 0046/0048): marca no banco, limpeza em toda definição de senha, recuperação, convite e CLI. */
final class SenhaProvisoriaTest extends TestCase
{
    private const SENHA = 'uma senha bem longa 2026';
    private const NOVA = 'outra senha ainda mais longa 2027';

    private Connection $con;
    private UsuarioRepositorio $usuarios;

    protected function setUp(): void
    {
        $this->con = Conexao::memoria();
        (new Migrador($this->con))->migrar();
        $this->usuarios = new UsuarioRepositorio($this->con, new Auditoria($this->con), PASSWORD_BCRYPT);
    }

    public function testCriarComProvisoriaMarcaAContaEOLoginAEntrega(): void
    {
        $criada = $this->usuarios->criar('ana', 'ana@exemplo.org', 'Ana', self::SENHA, false, 'cli', true);
        self::assertTrue($criada->senhaProvisoria);
        self::assertTrue($this->usuarios->porLogin('ana')?->senhaProvisoria);
        self::assertTrue($this->usuarios->autenticar('ana', self::SENHA)?->senhaProvisoria);
    }

    public function testContaNormalNaoTemMarca(): void
    {
        $u = $this->usuarios->criar('bia', 'bia@exemplo.org', 'Bia', self::SENHA);
        self::assertFalse($u->senhaProvisoria);
        self::assertFalse($this->usuarios->porId($u->id)?->senhaProvisoria);
    }

    public function testTrocarASenhaRemoveAMarcaEMantemSoASessaoAtual(): void
    {
        $u = $this->usuarios->criar('ana', 'ana@exemplo.org', 'Ana', self::SENHA, false, 'cli', true);
        $sessoes = new SessaoRepositorio($this->con, $this->usuarios);
        $atual = $sessoes->criar($u->id);
        $outra = $sessoes->criar($u->id);
        self::assertTrue($sessoes->validar($atual)?->usuario->senhaProvisoria);

        $idHash = $sessoes->validar($atual)->idHash;
        $this->usuarios->definirSenha($u->id, self::NOVA, 'usuario:' . $u->id, $idHash);

        self::assertFalse($this->usuarios->porId($u->id)?->senhaProvisoria);
        self::assertFalse($sessoes->validar($atual)->usuario->senhaProvisoria, 'a sessão atual segue valendo, já sem a marca');
        self::assertNull($sessoes->validar($outra), 'as outras sessões caem');
    }

    public function testDefinirSenhaComProvisoriaMarcaDeNovo(): void
    {
        $u = $this->usuarios->criar('ana', 'ana@exemplo.org', 'Ana', self::SENHA);
        $this->usuarios->definirSenha($u->id, self::NOVA, 'cli', null, true);
        self::assertTrue($this->usuarios->porId($u->id)?->senhaProvisoria);
    }

    public function testAuditoriaLevaOBooleanoENuncaASenha(): void
    {
        $this->usuarios->criar('ana', 'ana@exemplo.org', 'Ana', self::SENHA, false, 'cli', true);
        $detalhes = implode("\n", array_map('strval', $this->con->fetchFirstColumn('SELECT detalhe FROM auditoria')));
        self::assertStringContainsString('"provisoria":true', $detalhes);
        self::assertStringNotContainsString(self::SENHA, $detalhes);
    }

    public function testContaDeServicoNuncaTemMarca(): void
    {
        self::assertFalse($this->usuarios->criarContaDeServico('robo', 'Robô')->senhaProvisoria);
    }

    public function testAceitarConviteNaoDeixaMarca(): void
    {
        $u = $this->usuarios->criarConvidado('cadu', 'cadu@exemplo.org', 'Cadu');
        $this->usuarios->aceitarConvite($u->id, self::SENHA);
        self::assertFalse($this->usuarios->porId($u->id)?->senhaProvisoria);
    }

    public function testRecuperarPorLinkLimpaAMarca(): void
    {
        $u = $this->usuarios->criar('ana', 'ana@exemplo.org', 'Ana', self::SENHA, false, 'cli', true);
        $relogio = static fn (): int => 1_800_000_000;
        $tokens = new TokenDeConta($this->con, $relogio);
        $pasta = sys_get_temp_dir() . '/pbl-prov-' . bin2hex(random_bytes(4));
        $rec = new RecuperacaoDeAcesso($this->usuarios, $tokens, new LimiteDeRecuperacao($this->con, str_repeat('s', 64), $relogio), new EmailsDeConta(new TransporteNulo(), 'https://exemplo.org'), new Auditoria($this->con), $pasta);
        $token = $tokens->emitir(TokenDeConta::SENHA, $u->id);

        self::assertNull($rec->redefinir($token, self::NOVA));
        self::assertFalse($this->usuarios->porId($u->id)?->senhaProvisoria);
    }

    public function testMigracaoAdicionaColunaComPadraoZeroEIdempotenteERollbackFunciona(): void
    {
        $c = Conexao::memoria();
        (new Migrador($c))->migrar();
        foreach (M029SenhaProvisoria::ROLLBACK as $sql) {
            $c->executeStatement($sql); // o rollback documentado
        }
        self::assertArrayNotHasKey('senha_provisoria', $c->createSchemaManager()->listTableColumns('usuario'));
        $c->insert('usuario', ['login' => 'velho', 'email' => 'v@exemplo.org', 'nome' => 'V', 'senha_hash' => 'x', 'administrador' => 0, 'ativo' => 1, 'criado_em' => gmdate('c')]);

        self::assertSame([29], (new Migrador($c))->migrar());
        self::assertSame(0, (int) $c->fetchOne("SELECT senha_provisoria FROM usuario WHERE login = 'velho'"));
        (new M029SenhaProvisoria())->aplicar($c);
        (new M029SenhaProvisoria())->aplicar($c);
        self::assertSame(29, (new Migrador($c))->versaoAtual());
    }

    public function testCliUsuarioCriarESenhaComProvisoria(): void
    {
        $raiz = Ajuda::raizDeProjeto();
        try {
            $caminhos = new Caminhos($raiz);
            (new Instalador($caminhos))->instalar('Site', 'www.exemplo.org');
            $t = new CommandTester(new UsuarioComando($caminhos));
            $t->setInputs([self::SENHA, self::SENHA]);
            self::assertSame(0, $t->execute(['acao' => 'criar', 'login' => 'ana', '--email' => 'ana@exemplo.org', '--provisoria' => true]));
            self::assertStringContainsString('trocá-la no primeiro acesso', $t->getDisplay());
            $repo = Servicos::doBancoInstalado($caminhos)->usuarios;
            self::assertTrue($repo->porLogin('ana')?->senhaProvisoria);

            $t->setInputs([self::NOVA, self::NOVA]);
            self::assertSame(0, $t->execute(['acao' => 'senha', 'login' => 'ana']));
            self::assertFalse($repo->porLogin('ana')->senhaProvisoria, 'sem a opção a marca cai');

            $t->setInputs([self::SENHA, self::SENHA]);
            self::assertSame(0, $t->execute(['acao' => 'senha', 'login' => 'ana', '--provisoria' => true]));
            self::assertStringContainsString('troca será exigida', $t->getDisplay());
            self::assertTrue($repo->porLogin('ana')->senhaProvisoria);

            self::assertSame(1, $t->execute(['acao' => 'criar', 'login' => 'robo', '--servico' => true, '--provisoria' => true]));
            self::assertStringContainsString('--provisoria não vale com --servico', $t->getDisplay());
            self::assertSame(1, $t->execute(['acao' => 'ativar', 'login' => 'ana', '--provisoria' => true]));
        } finally {
            Ajuda::apagar($raiz);
        }
    }

    public function testCliContaSenhaGeradaProvisoria(): void
    {
        $raiz = Ajuda::raizDeProjeto();
        try {
            $caminhos = new Caminhos($raiz);
            (new Instalador($caminhos))->instalar('Site', 'www.exemplo.org');
            $repo = Servicos::doBancoInstalado($caminhos)->usuarios;
            $repo->criar('ana', 'ana@exemplo.org', 'Ana', self::SENHA);
            $t = new CommandTester(new ContaSenhaComando($caminhos));
            self::assertSame(0, $t->execute(['login' => 'ana', '--gerar' => true, '--provisoria' => true]));
            self::assertTrue($repo->porLogin('ana')?->senhaProvisoria);
            self::assertSame(0, $t->execute(['login' => 'ana', '--gerar' => true]));
            self::assertFalse($repo->porLogin('ana')->senhaProvisoria);
        } finally {
            Ajuda::apagar($raiz);
        }
    }

    public function testInstalarComAdminSenhaProvisoria(): void
    {
        $raiz = Ajuda::raizDeProjeto();
        try {
            $caminhos = new Caminhos($raiz);
            (new Instalador($caminhos))->instalar('Site', 'www.exemplo.org', ['login' => 'admin', 'email' => 'a@exemplo.org', 'nome' => 'Admin', 'senha' => self::SENHA, 'provisoria' => true]);
            self::assertTrue(Servicos::doBancoInstalado($caminhos)->usuarios->porLogin('admin')?->senhaProvisoria);
        } finally {
            Ajuda::apagar($raiz);
        }
    }

    public function testInstalarExigeAdminLoginParaASenhaProvisoria(): void
    {
        $raiz = Ajuda::raizDeProjeto();
        try {
            $t = new CommandTester(new InstalarComando(new Caminhos($raiz)));
            self::assertSame(1, $t->execute(['--nome' => 'Site', '--host' => 'www.exemplo.org', '--admin-senha-provisoria' => true]));
            self::assertStringContainsString('--admin-login', $t->getDisplay());
        } finally {
            Ajuda::apagar($raiz);
        }
    }
}
