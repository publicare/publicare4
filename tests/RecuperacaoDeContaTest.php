<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Doctrine\DBAL\Connection;
use Pbl\Acesso\Auditoria;
use Pbl\Acesso\ConviteDeUsuario;
use Pbl\Acesso\EmailsDeConta;
use Pbl\Acesso\LimiteDeRecuperacao;
use Pbl\Acesso\RecuperacaoDeAcesso;
use Pbl\Acesso\SessaoRepositorio;
use Pbl\Acesso\TokenDeConta;
use Pbl\Acesso\UsuarioRepositorio;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use Pbl\Email\Transporte;
use Pbl\Email\TransporteArquivo;
use Pbl\Email\TransporteNulo;
use PHPUnit\Framework\TestCase;

/** Recuperação de senha e usuário, tokens de uso único e convite, de ponta a ponta com o driver `arquivo` (ADR 0035 a 0038). */
final class RecuperacaoDeContaTest extends TestCase
{
    private const SENHA = 'uma senha bem longa 2026';
    private const NOVA = 'outra senha ainda mais longa 2027';

    private Connection $con;
    private UsuarioRepositorio $usuarios;
    private Auditoria $auditoria;
    private TokenDeConta $tokens;
    private RecuperacaoDeAcesso $recuperacao;
    private ConviteDeUsuario $convites;
    private string $pasta;
    private int $agora = 1_800_000_000;

    protected function setUp(): void
    {
        $this->pasta = sys_get_temp_dir() . '/pbl-rec-' . bin2hex(random_bytes(4));
        $this->con = Conexao::memoria();
        (new Migrador($this->con))->migrar();
        $this->auditoria = new Auditoria($this->con);
        $this->usuarios = new UsuarioRepositorio($this->con, $this->auditoria, PASSWORD_BCRYPT);
        $this->montar(new TransporteArquivo($this->pasta . '/caixa', 'Site <nao@exemplo.org>'));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->pasta . '/*/*') ?: [] as $f) {
            is_file($f) && unlink($f);
        }
        foreach (glob($this->pasta . '/*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->pasta);
    }

    private function montar(Transporte $t, string $base = 'https://exemplo.org'): void
    {
        $relogio = fn (): int => $this->agora;
        $this->tokens = new TokenDeConta($this->con, $relogio);
        $emails = new EmailsDeConta($t, $base);
        $this->recuperacao = new RecuperacaoDeAcesso($this->usuarios, $this->tokens, new LimiteDeRecuperacao($this->con, str_repeat('s', 64), $relogio), $emails, $this->auditoria, $this->pasta . '/fila');
        $this->convites = new ConviteDeUsuario($this->usuarios, $this->tokens, $emails, $this->auditoria);
    }

    /** @return list<string> */
    private function emails(): array
    {
        $a = glob($this->pasta . '/caixa/*.eml') ?: [];
        sort($a);

        return array_map(static fn (string $f): string => (string) file_get_contents($f), $a);
    }

    private function tokenDoUltimoEmail(): string
    {
        $ultimo = (string) ($this->emails()[count($this->emails()) - 1] ?? '');
        self::assertSame(1, preg_match('#/_pbl/[a-z-]+/([0-9a-f]{64})#', $ultimo, $m));

        return $m[1] ?? '';
    }

    private function limparCaixa(): void
    {
        array_map('unlink', glob($this->pasta . '/caixa/*.eml') ?: []);
    }

    public function testFluxoCompletoDeRecuperacaoDeSenha(): void
    {
        $ana = $this->usuarios->criar('ana', 'ana@exemplo.org', 'Ana', self::SENHA);
        $sessoes = new SessaoRepositorio($this->con, $this->usuarios, fn (): int => $this->agora);
        $sessoes->criar($ana->id);

        $this->recuperacao->solicitar(RecuperacaoDeAcesso::SENHA, 'ana', '203.0.113.7');
        self::assertSame([], $this->emails(), 'nada é enviado na parte síncrona');
        self::assertSame(1, $this->recuperacao->processarFila());
        $token = $this->tokenDoUltimoEmail();
        self::assertStringContainsString('https://exemplo.org/_pbl/redefinir-senha/' . $token, $this->emails()[0]);
        self::assertSame($token === '' ? 0 : 1, (int) $this->con->fetchOne('SELECT COUNT(*) FROM token_conta WHERE token_hash = ?', [hash('sha256', $token)]));
        self::assertSame(0, (int) $this->con->fetchOne('SELECT COUNT(*) FROM token_conta WHERE token_hash = ?', [$token]), 'o token em claro não vai ao banco');

        self::assertTrue($this->recuperacao->tokenValido($token));
        self::assertTrue($this->recuperacao->tokenValido($token), 'abrir o link (GET) não consome');
        $this->limparCaixa();
        self::assertNull($this->recuperacao->redefinir($token, self::NOVA));

        self::assertNotNull($this->usuarios->autenticar('ana', self::NOVA));
        self::assertNull($this->usuarios->autenticar('ana', self::SENHA));
        self::assertSame(0, (int) $this->con->fetchOne('SELECT COUNT(*) FROM sessao WHERE usuario_id = ?', [$ana->id]), 'encerra as sessões');
        self::assertStringContainsString(base64_encode('Sua senha foi alterada'), implode('', $this->emails()), 'aviso de senha alterada');
        self::assertSame(RecuperacaoDeAcesso::MENSAGEM_TOKEN_INVALIDO, $this->recuperacao->redefinir($token, 'mais uma senha longa 2028'), 'uso único');
        self::assertFalse($this->recuperacao->tokenValido($token));
    }

    public function testRespostaEIdenticaParaContaExistenteEInexistenteEOTrabalhoSincronoTambem(): void
    {
        $this->usuarios->criar('ana', 'ana@exemplo.org', 'Ana', self::SENHA);
        $a = $this->recuperacao->solicitar(RecuperacaoDeAcesso::SENHA, 'ana', '203.0.113.7');
        $b = $this->recuperacao->solicitar(RecuperacaoDeAcesso::SENHA, 'fantasma', '203.0.113.7');
        $c = $this->recuperacao->solicitar(RecuperacaoDeAcesso::SENHA, 'fantasma@exemplo.org', '203.0.113.8');
        self::assertSame($a, $b);
        self::assertSame($a, $c);
        self::assertCount(3, glob($this->pasta . '/fila/*.json') ?: [], 'o mesmo trabalho (um job) para todos');
        $this->recuperacao->processarFila();
        self::assertCount(1, $this->emails(), 'só a conta que existe recebe');
        self::assertSame(0, $this->con->fetchOne("SELECT COUNT(*) FROM token_conta WHERE usuario_id NOT IN (SELECT id FROM usuario)"));
    }

    public function testContaInativaDeServicoOuComConvitePendenteNaoRecebe(): void
    {
        $inativa = $this->usuarios->criar('inativa', 'in@exemplo.org', 'In', self::SENHA);
        $this->usuarios->definirAtivo($inativa->id, false);
        $this->usuarios->criarContaDeServico('robo', 'Robô');
        $this->convites->convidar('novo', 'Novo', 'novo@exemplo.org', false, 'teste');
        $this->limparCaixa();
        foreach (['inativa', 'in@exemplo.org', 'robo', 'robo@servico.invalid', 'novo', 'novo@exemplo.org'] as $id) {
            $this->recuperacao->solicitar(RecuperacaoDeAcesso::SENHA, $id, '203.0.113.9');
        }
        $this->recuperacao->processarFila();
        self::assertSame([], $this->emails());
    }

    public function testTokenExpiradoEAdulteradoENovoPedidoInvalidaOAnterior(): void
    {
        $ana = $this->usuarios->criar('ana', 'ana@exemplo.org', 'Ana', self::SENHA);
        $this->recuperacao->solicitar(RecuperacaoDeAcesso::SENHA, 'ana', '203.0.113.7');
        $this->recuperacao->processarFila();
        $primeiro = $this->tokenDoUltimoEmail();
        $this->limparCaixa();
        $this->recuperacao->solicitar(RecuperacaoDeAcesso::SENHA, 'ana@exemplo.org', '203.0.113.7');
        $this->recuperacao->processarFila();
        $segundo = $this->tokenDoUltimoEmail();
        self::assertNotSame($primeiro, $segundo);
        self::assertSame(RecuperacaoDeAcesso::MENSAGEM_TOKEN_INVALIDO, $this->recuperacao->redefinir($primeiro, self::NOVA));
        self::assertSame(RecuperacaoDeAcesso::MENSAGEM_TOKEN_INVALIDO, $this->recuperacao->redefinir(strrev($segundo), self::NOVA));
        self::assertSame(RecuperacaoDeAcesso::MENSAGEM_TOKEN_INVALIDO, $this->recuperacao->redefinir('lixo', self::NOVA));

        $this->agora += 1801; // passou de 30 minutos
        self::assertSame(RecuperacaoDeAcesso::MENSAGEM_TOKEN_INVALIDO, $this->recuperacao->redefinir($segundo, self::NOVA));
        self::assertNotNull($this->usuarios->autenticar('ana', self::SENHA), 'a senha antiga segue valendo');
        self::assertSame($ana->id, $this->usuarios->porLogin('ana')?->id);
    }

    public function testSenhaFracaNaoQueimaOLink(): void
    {
        $this->usuarios->criar('ana', 'ana@exemplo.org', 'Ana', self::SENHA);
        $this->recuperacao->solicitar(RecuperacaoDeAcesso::SENHA, 'ana', '203.0.113.7');
        $this->recuperacao->processarFila();
        $token = $this->tokenDoUltimoEmail();
        self::assertSame('A senha precisa ter pelo menos 10 caracteres', $this->recuperacao->redefinir($token, 'curta'));
        self::assertTrue($this->recuperacao->tokenValido($token));
        self::assertNull($this->recuperacao->redefinir($token, self::NOVA));
    }

    public function testTrocarSenhaInvalidaOutrosTokensDeSenha(): void
    {
        $ana = $this->usuarios->criar('ana', 'ana@exemplo.org', 'Ana', self::SENHA);
        $t = $this->tokens->emitir(TokenDeConta::SENHA, $ana->id);
        $this->recuperacao->solicitar(RecuperacaoDeAcesso::SENHA, 'ana', '203.0.113.7');
        $this->recuperacao->processarFila(); // o pedido novo já invalida $t
        self::assertFalse($this->recuperacao->tokenValido($t));
    }

    public function testLimiteDeCincoPorContaOmiteOEnvioSemMudarAResposta(): void
    {
        $this->usuarios->criar('ana', 'ana@exemplo.org', 'Ana', self::SENHA);
        $respostas = [];
        for ($i = 0; $i < 7; $i++) {
            $respostas[] = $this->recuperacao->solicitar(RecuperacaoDeAcesso::SENHA, 'ana', '203.0.113.' . (10 + $i));
        }
        self::assertCount(1, array_unique($respostas));
        $this->recuperacao->processarFila();
        self::assertCount(5, $this->emails(), '5 por conta em 15 min; o resto é omitido');
        self::assertGreaterThan(0, (int) $this->con->fetchOne("SELECT COUNT(*) FROM auditoria WHERE acao = 'recuperacao.limitada'"));
        $this->agora += 901;
        $this->recuperacao->solicitar(RecuperacaoDeAcesso::SENHA, 'ana', '203.0.113.99');
        $this->recuperacao->processarFila();
        self::assertCount(6, $this->emails(), 'passada a janela volta a enviar');
    }

    public function testLimiteDeVintePorIp(): void
    {
        for ($i = 0; $i < 22; $i++) {
            $this->usuarios->criar('u' . $i . 'xx', "u{$i}@exemplo.org", 'U', self::SENHA);
            $this->recuperacao->solicitar(RecuperacaoDeAcesso::SENHA, 'u' . $i . 'xx', '198.51.100.1');
        }
        $this->recuperacao->processarFila();
        self::assertCount(20, $this->emails());
    }

    public function testEsqueciUsuarioListaTodosOsLoginsDoEmailEIgnoraLogin(): void
    {
        $this->usuarios->criar('ana', 'dupla@exemplo.org', 'Ana', self::SENHA);
        $this->usuarios->criar('ana2', 'Dupla@Exemplo.org', 'Ana', self::SENHA);
        $this->recuperacao->solicitar(RecuperacaoDeAcesso::USUARIO, 'dupla@exemplo.org', '203.0.113.7');
        $this->recuperacao->solicitar(RecuperacaoDeAcesso::USUARIO, 'ana', '203.0.113.7');
        $this->recuperacao->solicitar(RecuperacaoDeAcesso::USUARIO, 'ninguem@exemplo.org', '203.0.113.7');
        $this->recuperacao->processarFila();
        $e = $this->emails();
        self::assertCount(1, $e);
        self::assertStringContainsString("ana\r\nana2", $e[0]);
        self::assertSame(0, (int) $this->con->fetchOne('SELECT COUNT(*) FROM token_conta'), 'esqueci usuário não emite token');
    }

    public function testEmailComVariasContasRecebeUmLinkPorConta(): void
    {
        $this->usuarios->criar('ana', 'dupla@exemplo.org', 'Ana', self::SENHA);
        $this->usuarios->criar('ana2', 'dupla@exemplo.org', 'Ana', self::SENHA);
        $this->recuperacao->solicitar(RecuperacaoDeAcesso::SENHA, 'dupla@exemplo.org', '203.0.113.7');
        $this->recuperacao->processarFila();
        self::assertCount(2, $this->emails());
    }

    public function testSemEmailConfiguradoARecuperacaoFicaDesabilitada(): void
    {
        $this->montar(new TransporteNulo());
        self::assertFalse($this->recuperacao->habilitado());
        $this->recuperacao->solicitar(RecuperacaoDeAcesso::SENHA, 'ana', '203.0.113.7');
        self::assertSame([], glob($this->pasta . '/fila/*.json') ?: []);
        $this->montar(new TransporteArquivo($this->pasta . '/caixa', 'nao@exemplo.org'), '');
        self::assertFalse($this->recuperacao->habilitado(), 'sem domínio principal não há como montar o link');
    }

    public function testAuditoriaNaoGuardaTokenNemIdentificador(): void
    {
        $this->usuarios->criar('ana', 'ana@exemplo.org', 'Ana', self::SENHA);
        $this->recuperacao->solicitar(RecuperacaoDeAcesso::SENHA, 'ana@exemplo.org', '203.0.113.7', 'ip-hash');
        $this->recuperacao->processarFila();
        $token = $this->tokenDoUltimoEmail();
        $this->recuperacao->redefinir($token, self::NOVA);
        $log = json_encode($this->con->fetchAllAssociative('SELECT * FROM auditoria'), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($token, $log);
        self::assertStringNotContainsString(self::NOVA, $log);
        self::assertStringNotContainsString('203.0.113.7', $log);
        self::assertStringContainsString('recuperacao.enviada', $log);
        self::assertStringContainsString('recuperacao.concluida', $log);
        self::assertSame([], glob($this->pasta . '/fila/*.json') ?: [], 'a fila não guarda o pedido depois de processar');
    }

    public function testTokenDeOutroTipoNaoServe(): void
    {
        $ana = $this->usuarios->criar('ana', 'ana@exemplo.org', 'Ana', self::SENHA);
        $t = $this->tokens->emitir(TokenDeConta::EMAIL_NOVO, $ana->id, 'novo@exemplo.org');
        self::assertNull($this->tokens->consultar($t, TokenDeConta::SENHA));
        self::assertSame(RecuperacaoDeAcesso::MENSAGEM_TOKEN_INVALIDO, $this->recuperacao->redefinir($t, self::NOVA));
        self::assertNotNull($this->tokens->consumir($t, TokenDeConta::EMAIL_NOVO));
        self::assertNull($this->tokens->consumir($t, TokenDeConta::EMAIL_NOVO));
    }

    public function testLimparExpiradosEApagarDoUsuario(): void
    {
        $ana = $this->usuarios->criar('ana', 'ana@exemplo.org', 'Ana', self::SENHA);
        $this->tokens->emitir(TokenDeConta::SENHA, $ana->id);
        $this->agora += 3 * 86400;
        self::assertSame(1, $this->tokens->limparExpirados());
        $this->tokens->emitir(TokenDeConta::SENHA, $ana->id);
        $this->tokens->apagarDoUsuario($ana->id);
        self::assertSame(0, (int) $this->con->fetchOne('SELECT COUNT(*) FROM token_conta'));
    }

    public function testLinkParaOperadorSemSmtp(): void
    {
        $this->montar(new TransporteNulo());
        $ana = $this->usuarios->criar('ana', 'ana@exemplo.org', 'Ana', self::SENHA);
        $link = $this->recuperacao->emitirLinkParaOperador($ana);
        self::assertMatchesRegularExpression('#^https://exemplo.org/_pbl/redefinir-senha/[0-9a-f]{64}$#', $link);
        self::assertNull($this->recuperacao->redefinir(substr($link, -64), self::NOVA));
    }

    public function testConviteFluxoCompleto(): void
    {
        $link = $this->convites->convidar('bia', 'Bia', 'bia@exemplo.org', false, 'cli');
        self::assertMatchesRegularExpression('#/_pbl/convite/[0-9a-f]{64}$#', $link);
        $token = $this->tokenDoUltimoEmail();
        self::assertNull($this->usuarios->autenticar('bia', self::SENHA), 'conta pendente não entra');
        self::assertNull($this->usuarios->autenticar('bia', \Pbl\Acesso\Usuario::SENHA_DE_CONVITE));
        self::assertTrue($this->convites->tokenValido($token));
        self::assertNotNull($this->convites->aceitar($token, 'curta'), 'política antes de consumir');
        self::assertTrue($this->convites->tokenValido($token));
        self::assertNull($this->convites->aceitar($token, self::NOVA));
        self::assertNotNull($this->usuarios->autenticar('bia', self::NOVA));
        self::assertNotNull($this->convites->aceitar($token, self::NOVA), 'uso único');
        self::assertNotNull($this->con->fetchOne("SELECT email_confirmado_em FROM usuario WHERE login = 'bia'"));
    }

    public function testConviteReenviarInvalidaOAnteriorECancelarApagaAConta(): void
    {
        $this->convites->convidar('bia', 'Bia', 'bia@exemplo.org', false, 'cli');
        $primeiro = $this->tokenDoUltimoEmail();
        $id = ($this->usuarios->porLogin('bia') ?? throw new \LogicException('sem conta'))->id;
        $this->limparCaixa();
        $this->convites->reenviar($id, 'cli');
        self::assertFalse($this->convites->tokenValido($primeiro));
        self::assertTrue($this->convites->tokenValido($this->tokenDoUltimoEmail()));
        self::assertTrue($this->convites->cancelar($id, 'cli'));
        self::assertNull($this->usuarios->porLogin('bia'));
        self::assertFalse($this->convites->tokenValido($primeiro));
    }

    public function testConviteComEmailOuLoginRepetidoERecusado(): void
    {
        $this->usuarios->criar('ana', 'ana@exemplo.org', 'Ana', self::SENHA);
        $this->expectException(\InvalidArgumentException::class);
        $this->convites->convidar('bia', 'Bia', 'ANA@exemplo.org', false, 'cli');
    }

    public function testConviteSemEmailConfiguradoExigeSemEnvio(): void
    {
        $this->montar(new TransporteNulo());
        try {
            $this->convites->convidar('bia', 'Bia', 'bia@exemplo.org', false, 'cli');
            self::fail('devia recusar');
        } catch (\InvalidArgumentException) {
            self::assertNull($this->usuarios->porLogin('bia'), 'não deixa conta pendente sem poder enviar');
        }
        $link = $this->convites->convidar('bia', 'Bia', 'bia@exemplo.org', false, 'cli', false);
        self::assertStringContainsString('/_pbl/convite/', $link);
    }

    public function testConviteExpiraEm72Horas(): void
    {
        $this->convites->convidar('bia', 'Bia', 'bia@exemplo.org', false, 'cli');
        $token = $this->tokenDoUltimoEmail();
        $this->agora += 72 * 3600 - 1;
        self::assertTrue($this->convites->tokenValido($token));
        $this->agora += 2;
        self::assertFalse($this->convites->tokenValido($token));
    }
}
