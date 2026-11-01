<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\LimiteDeTentativas;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoRepositorio;
use Pbl\Acesso\UsuarioRepositorio;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use Pbl\Caminhos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** Sessão (expiração, hash do token), CSRF, limite de tentativas e conferência de origem, em unidade, com relógio controlado. */
final class SessaoCsrfRedeTest extends TestCase
{
    private int $t = 1_800_000_000;
    private \Doctrine\DBAL\Connection $con;
    private UsuarioRepositorio $usuarios;
    private SessaoRepositorio $sessoes;
    private int $uid;

    protected function setUp(): void
    {
        $this->con = Conexao::memoria();
        (new Migrador($this->con))->migrar();
        $this->usuarios = new UsuarioRepositorio($this->con);
        $this->uid = $this->usuarios->criar('ana', 'ana@exemplo.org', 'Ana', 'uma senha bem longa 2026')->id;
        $this->sessoes = new SessaoRepositorio($this->con, $this->usuarios, fn (): int => $this->t);
    }

    public function testTokenTem64HexNaoEGuardadoEmTextoEEUnicoACadaLogin(): void
    {
        $a = $this->sessoes->criar($this->uid);
        $b = $this->sessoes->criar($this->uid);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        self::assertNotSame($a, $b);
        self::assertSame(0, (int) $this->con->fetchOne('SELECT COUNT(*) FROM sessao WHERE id_hash = ?', [$a]), 'o banco não tem o token');
        self::assertSame(1, (int) $this->con->fetchOne('SELECT COUNT(*) FROM sessao WHERE id_hash = ?', [hash('sha256', $a)]));
        self::assertSame('ana', $this->sessoes->validar($a)?->usuario->login);
    }

    public function testTokenMalFormadoOuInexistenteNaoValida(): void
    {
        foreach (['', 'abc', str_repeat('g', 64), str_repeat('0', 64), str_repeat('a', 65), "x'; DROP TABLE sessao;--"] as $token) {
            self::assertNull($this->sessoes->validar($token), $token);
        }
    }

    public function testExpiraPorInatividadeMasAtividadeRenova(): void
    {
        $token = $this->sessoes->criar($this->uid);

        $this->t += SessaoRepositorio::INATIVIDADE - 60;
        self::assertNotNull($this->sessoes->validar($token), 'dentro da janela de inatividade');
        $this->t += SessaoRepositorio::INATIVIDADE - 60;
        self::assertNotNull($this->sessoes->validar($token), 'o uso anterior renovou a janela');
        $this->t += SessaoRepositorio::INATIVIDADE + 1;
        self::assertNull($this->sessoes->validar($token), 'parada por mais de 30 minutos');
        self::assertSame(0, (int) $this->con->fetchOne('SELECT COUNT(*) FROM sessao'), 'a sessão vencida foi apagada');
    }

    public function testTemTetoAbsolutoMesmoComUsoContinuo(): void
    {
        $token = $this->sessoes->criar($this->uid);
        $passos = intdiv(SessaoRepositorio::DURACAO_MAXIMA, 600);
        for ($i = 0; $i < $passos - 1; $i++) {
            $this->t += 600;
            self::assertNotNull($this->sessoes->validar($token));
        }
        $this->t += 600;

        self::assertNull($this->sessoes->validar($token), 'passou de 12 horas desde o login');
    }

    public function testDesativarOuTrocarASenhaDerrubaAsSessoes(): void
    {
        $a = $this->sessoes->criar($this->uid);
        $this->usuarios->definirSenha($this->uid, 'outra senha igualmente longa');
        self::assertNull($this->sessoes->validar($a), 'trocar a senha derruba quem estava logado');

        $b = $this->sessoes->criar($this->uid);
        $this->usuarios->definirAtivo($this->uid, false);
        self::assertNull($this->sessoes->validar($b), 'desativar derruba a sessão');
        self::assertSame(0, (int) $this->con->fetchOne('SELECT COUNT(*) FROM sessao WHERE usuario_id = ?', [$this->uid]));
    }

    public function testEncerrarUmaOuTodas(): void
    {
        $a = $this->sessoes->criar($this->uid);
        $b = $this->sessoes->criar($this->uid);
        $this->sessoes->encerrar($a);

        self::assertNull($this->sessoes->validar($a));
        self::assertNotNull($this->sessoes->validar($b));
        $this->sessoes->encerrarTodasDe($this->uid);
        self::assertNull($this->sessoes->validar($b));
    }

    public function testCsrfDaSessaoEPorSessaoEOTokenDeEntrarVale1hMais1h(): void
    {
        $csrf = new Csrf(str_repeat('a', 64), fn (): int => $this->t);

        self::assertTrue($csrf->conferirSessao('hash-1', $csrf->daSessao('hash-1')));
        self::assertFalse($csrf->conferirSessao('hash-2', $csrf->daSessao('hash-1')), 'token de outra sessão');
        self::assertFalse($csrf->conferirSessao('hash-1', ''));
        self::assertFalse((new Csrf(str_repeat('b', 64)))->conferirSessao('hash-1', $csrf->daSessao('hash-1')), 'outro segredo');

        $token = $csrf->paraEntrar();
        self::assertTrue($csrf->conferirEntrar($token));
        $this->t += 3600;
        self::assertTrue($csrf->conferirEntrar($token), 'ainda vale na hora seguinte');
        $this->t += 3600;
        self::assertFalse($csrf->conferirEntrar($token), 'venceu');
        self::assertFalse($csrf->conferirEntrar('qualquer coisa'));
    }

    public function testLimiteBloqueiaDepoisDe5FalhasPorLoginELiberaAposAJanela(): void
    {
        $limite = new LimiteDeTentativas($this->con, str_repeat('c', 64), fn (): int => $this->t);
        for ($i = 0; $i < LimiteDeTentativas::POR_LOGIN - 1; $i++) {
            $limite->registrarFalha('ana', '10.0.0.1');
        }
        self::assertNull($limite->bloqueadoPor('ana', '10.0.0.1'), '4 falhas ainda não bloqueiam');
        $limite->registrarFalha('ana', '10.0.0.1');

        $espera = $limite->bloqueadoPor('ANA ', '10.0.0.9');
        self::assertNotNull($espera, 'o login é bloqueado de qualquer IP, sem diferenciar maiúsculas');
        self::assertGreaterThan(0, $espera);
        self::assertLessThanOrEqual(LimiteDeTentativas::JANELA, $espera);
        self::assertNull($limite->bloqueadoPor('bia', '10.0.0.9'), 'outro login não é afetado');

        $this->t += LimiteDeTentativas::JANELA + 1;
        self::assertNull($limite->bloqueadoPor('ana', '10.0.0.1'), 'a janela passou');
    }

    public function testLimitePorIpEClearDoLoginAposSucesso(): void
    {
        $limite = new LimiteDeTentativas($this->con, str_repeat('c', 64), fn (): int => $this->t);
        for ($i = 0; $i < LimiteDeTentativas::POR_IP; $i++) {
            $limite->registrarFalha("login{$i}", '10.0.0.5');
        }
        self::assertNotNull($limite->bloqueadoPor('qualquer', '10.0.0.5'), 'o IP que falhou muito é bloqueado');
        self::assertNull($limite->bloqueadoPor('qualquer', '10.0.0.6'));

        $limite2 = new LimiteDeTentativas($this->con, str_repeat('c', 64), fn (): int => $this->t);
        for ($i = 0; $i < 4; $i++) {
            $limite2->registrarFalha('ana', '10.1.1.1');
        }
        $limite2->limparLogin('ana');
        $limite2->registrarFalha('ana', '10.1.1.1');
        self::assertNull($limite2->bloqueadoPor('ana', '10.1.1.1'), 'depois de um acerto o contador do login recomeça');
    }

    public function testLoginEIpSoFicamNoBancoComoHash(): void
    {
        $limite = new LimiteDeTentativas($this->con, str_repeat('c', 64), fn (): int => $this->t);
        $limite->registrarFalha('ana.secreta', '203.0.113.7');

        $tudo = json_encode($this->con->fetchAllAssociative('SELECT * FROM tentativa_login'), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('ana.secreta', $tudo);
        self::assertStringNotContainsString('203.0.113.7', $tudo);
    }

    /** @return iterable<string, array{array<string, string>, bool}> */
    public static function origens(): iterable
    {
        yield 'sem Origin nem Referer (o token decide)' => [[], true];
        yield 'Origin igual' => [['HTTP_ORIGIN' => 'https://www.exemplo.org'], true];
        yield 'Origin com porta padrão explícita' => [['HTTP_ORIGIN' => 'https://www.exemplo.org:443'], true];
        yield 'Origin de outro site' => [['HTTP_ORIGIN' => 'https://evil.example'], false];
        yield 'Origin com o host como subdomínio de outro' => [['HTTP_ORIGIN' => 'https://www.exemplo.org.evil.example'], false];
        yield 'Origin com esquema diferente (porta 80)' => [['HTTP_ORIGIN' => 'http://www.exemplo.org'], false];
        yield 'Origin null' => [['HTTP_ORIGIN' => 'null'], false];
        yield 'Referer igual' => [['HTTP_REFERER' => 'https://www.exemplo.org/_pbl/entrar'], true];
        yield 'Referer de outro site' => [['HTTP_REFERER' => 'https://evil.example/x'], false];
        yield 'Origin certo vence Referer errado' => [['HTTP_ORIGIN' => 'https://www.exemplo.org', 'HTTP_REFERER' => 'https://evil.example/'], true];
        yield 'Origin lixo' => [['HTTP_ORIGIN' => 'javascript:alert(1)'], false];
    }

    /**
     * @param array<string, string> $cabecalhos
     */
    #[DataProvider('origens')]
    public function testOrigemConfere(array $cabecalhos, bool $esperado): void
    {
        $r = Request::create('https://www.exemplo.org/_pbl/entrar', 'POST', [], [], [], $cabecalhos);

        self::assertSame($esperado, (new Rede(new Caminhos(sys_get_temp_dir() . '/nao-existe-pbl4')))->origemConfere($r));
    }

    public function testCabecalhoDeIpSoEConfiavelQuandoConfigurado(): void
    {
        $raiz = Ajuda::pastaTemporaria();
        try {
            mkdir($raiz . '/dados/config', 0775, true);
            $r = Request::create('https://www.exemplo.org/', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https']);

            $semConfig = new Rede(new Caminhos($raiz));
            self::assertSame('10.0.0.1', $semConfig->ip($r), 'sem configuração, o cabeçalho (forjável) é ignorado');

            file_put_contents($raiz . '/dados/config/rede.json', '{"cabecalho_ip": "CF-Connecting-IP", "confiar_proto": true}');
            $comConfig = new Rede(new Caminhos($raiz));
            self::assertSame('203.0.113.9', $comConfig->ip($r));
            $lixo = Request::create('https://www.exemplo.org/', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => 'não é ip']);
            self::assertSame('10.0.0.1', $comConfig->ip($lixo), 'valor que não é IP volta ao endereço da conexão');

            $http = Request::create('http://www.exemplo.org/', 'GET', [], [], [], ['HTTP_X_FORWARDED_PROTO' => 'https']);
            self::assertFalse($semConfig->seguro($http));
            self::assertTrue($comConfig->seguro($http));
        } finally {
            Ajuda::apagar($raiz);
        }
    }
}
