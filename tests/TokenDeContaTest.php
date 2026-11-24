<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Auditoria;
use Pbl\Acesso\TokenDeConta;
use Pbl\Acesso\UsuarioRepositorio;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use PHPUnit\Framework\TestCase;

/** Unidade: tokens de conta (256 bits, só hash no banco, uso único, expiração, tipo). */
final class TokenDeContaTest extends TestCase
{
    private \Doctrine\DBAL\Connection $con;
    private TokenDeConta $tokens;
    private int $uid;
    private int $agora = 1_800_000_000;

    protected function setUp(): void
    {
        $this->con = Conexao::memoria();
        (new Migrador($this->con))->migrar();
        $usuarios = new UsuarioRepositorio($this->con, new Auditoria($this->con), PASSWORD_BCRYPT);
        $this->uid = $usuarios->criar('ana', 'ana@exemplo.org', 'Ana', 'uma senha bem longa 2026', false)->id;
        $this->tokens = new TokenDeConta($this->con, fn (): int => $this->agora);
    }

    public function testTokenTem256BitsEOBancoGuardaSoOHash(): void
    {
        $t = $this->tokens->emitir(TokenDeConta::SENHA, $this->uid);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $t);
        $linhas = $this->con->fetchAllAssociative('SELECT * FROM token_conta');
        self::assertCount(1, $linhas);
        self::assertSame(hash('sha256', $t), $linhas[0]['token_hash']);
        self::assertStringNotContainsString($t, (string) json_encode($linhas));
        self::assertNotSame($t, $this->tokens->emitir(TokenDeConta::CONVITE, $this->uid));
    }

    public function testConsultarNaoConsomeEConsumirEhDeUsoUnico(): void
    {
        $t = $this->tokens->emitir(TokenDeConta::SENHA, $this->uid);
        self::assertNotNull($this->tokens->consultar($t, TokenDeConta::SENHA));
        self::assertNotNull($this->tokens->consultar($t, TokenDeConta::SENHA));
        self::assertSame($this->uid, $this->tokens->consumir($t, TokenDeConta::SENHA)['usuario_id'] ?? null);
        self::assertNull($this->tokens->consumir($t, TokenDeConta::SENHA));
        self::assertNull($this->tokens->consultar($t, TokenDeConta::SENHA));
    }

    public function testExpiraEm30MinutosEConviteEm72Horas(): void
    {
        $s = $this->tokens->emitir(TokenDeConta::SENHA, $this->uid);
        $c = $this->tokens->emitir(TokenDeConta::CONVITE, $this->uid);
        $this->agora += 1799;
        self::assertNotNull($this->tokens->consultar($s, TokenDeConta::SENHA));
        $this->agora += 2;
        self::assertNull($this->tokens->consumir($s, TokenDeConta::SENHA));
        self::assertNotNull($this->tokens->consultar($c, TokenDeConta::CONVITE));
        $this->agora += TokenDeConta::VALIDADE_CONVITE;
        self::assertNull($this->tokens->consultar($c, TokenDeConta::CONVITE));
    }

    public function testTipoErradoEMalformadoNaoServem(): void
    {
        $t = $this->tokens->emitir(TokenDeConta::SENHA, $this->uid);
        self::assertNull($this->tokens->consultar($t, TokenDeConta::CONVITE));
        foreach (['', 'abc', strtoupper($t), $t . "\n", substr($t, 1), str_repeat('0', 64)] as $ruim) {
            self::assertNull($this->tokens->consultar($ruim, TokenDeConta::SENHA), $ruim);
        }
        self::assertNotNull($this->tokens->consultar($t, TokenDeConta::SENHA), 'tentativas ruins não queimam o token');
    }

    public function testNovoPedidoInvalidaOAnteriorSoDoMesmoTipo(): void
    {
        $a = $this->tokens->emitir(TokenDeConta::SENHA, $this->uid);
        $conv = $this->tokens->emitir(TokenDeConta::CONVITE, $this->uid);
        $b = $this->tokens->emitir(TokenDeConta::SENHA, $this->uid);
        self::assertNull($this->tokens->consultar($a, TokenDeConta::SENHA));
        self::assertNotNull($this->tokens->consultar($b, TokenDeConta::SENHA));
        self::assertNotNull($this->tokens->consultar($conv, TokenDeConta::CONVITE));
        $this->tokens->invalidar($this->uid);
        self::assertNull($this->tokens->consultar($b, TokenDeConta::SENHA));
        self::assertNull($this->tokens->consultar($conv, TokenDeConta::CONVITE));
    }

    public function testLimpezaApagaSoOQueExpirouHaMaisDeUmDia(): void
    {
        $this->tokens->emitir(TokenDeConta::SENHA, $this->uid);
        $this->agora += 1800 + 86000;
        self::assertSame(0, $this->tokens->limparExpirados());
        $this->agora += 500;
        self::assertSame(1, $this->tokens->limparExpirados());
    }

    public function testTipoInvalidoELancaExcecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tokens->emitir('invalido', $this->uid);
    }
}
