<?php

declare(strict_types=1);

namespace Pbl\Testes\Banco;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Driver\PDO\Exception as ExcecaoPdo;
use Pbl\Banco\ConfigBanco;
use Pbl\Banco\Dialeto\Dialeto;
use Pbl\Banco\Dialeto\DialetoMysql;
use Pbl\Banco\Dialeto\DialetoPostgres;
use Pbl\Banco\Dialeto\DialetoSqlite;
use Pbl\Banco\Dialeto\Dialetos;
use PHPUnit\Framework\TestCase;

final class DialetoTest extends TestCase
{
    public function testFabricaEscolhePorNome(): void
    {
        self::assertInstanceOf(DialetoSqlite::class, Dialetos::porNome('sqlite'));
        self::assertInstanceOf(DialetoMysql::class, Dialetos::porNome('mysql'));
        self::assertInstanceOf(DialetoPostgres::class, Dialetos::porNome('pgsql'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sqlite, mysql, pgsql');
        Dialetos::porNome('oracle');
    }

    public function testCaracteristicasDeCadaDialeto(): void
    {
        self::assertTrue(Dialetos::porNome('sqlite')->ddlTransacional());
        self::assertTrue(Dialetos::porNome('pgsql')->ddlTransacional());
        self::assertFalse(Dialetos::porNome('mysql')->ddlTransacional());
        self::assertSame(0, Dialetos::porNome('sqlite')->limiteDeIdentificador());
        self::assertSame(64, Dialetos::porNome('mysql')->limiteDeIdentificador());
        self::assertSame(63, Dialetos::porNome('pgsql')->limiteDeIdentificador());
        self::assertSame('InnoDB', Dialetos::porNome('mysql')->opcoesDeTabela()['engine']);
        self::assertSame('utf8mb4', Dialetos::porNome('mysql')->opcoesDeTabela()['charset']);
    }

    public function testOpcoesDeConexao(): void
    {
        self::assertSame(['driver' => 'pdo_sqlite', 'path' => '/a.sqlite'], Dialetos::porNome('sqlite')->opcoesDeConexao(ConfigBanco::sqlite('/a.sqlite')));
        self::assertSame(['driver' => 'pdo_sqlite', 'memory' => true], Dialetos::porNome('sqlite')->opcoesDeConexao(ConfigBanco::sqlite(':memory:')));

        $m = Dialetos::porNome('mysql')->opcoesDeConexao(new ConfigBanco('mysql', 'pbl', 'h', 3307, 'u', 'p'));
        self::assertSame(['pdo_mysql', 'h', 3307, 'pbl', 'utf8mb4'], [$m['driver'], $m['host'], $m['port'], $m['dbname'], $m['charset']]);
        self::assertArrayNotHasKey('driverOptions', $m);

        $p = Dialetos::porNome('pgsql')->opcoesDeConexao(new ConfigBanco('pgsql', 'pbl', 'h', null, 'u', 'p', null, 'verificar'));
        self::assertSame(['pdo_pgsql', 5432, 'verify-full'], [$p['driver'], $p['port'], $p['sslmode']]);
        self::assertSame('disable', Dialetos::porNome('pgsql')->opcoesDeConexao(new ConfigBanco('pgsql', 'pbl', 'h', null, 'u', 'p'))['sslmode']);
    }

    /** @return iterable<string, array{string, string, ?int, string, string}> */
    public static function erros(): iterable
    {
        yield 'mysql acesso' => ['mysql', '28000', 1045, "Access denied for user 'root'@'1.2.3.4' (using password: YES)", 'senha recusados'];
        yield 'mysql banco' => ['mysql', '42000', 1049, "Unknown database 'x'", 'não existe'];
        yield 'mysql host' => ['mysql', 'HY000', 2002, 'Connection refused', 'alcançar'];
        yield 'mysql tls' => ['mysql', 'HY000', 2026, 'SSL connection error: certificate verify failed', 'TLS'];
        yield 'mysql tabela' => ['mysql', '42S02', 1146, "Table 'x' doesn't exist", 'inexistente'];
        yield 'mysql timeout' => ['mysql', 'HY000', 1205, 'Lock wait timeout', 'demorou'];
        yield 'mysql deadlock' => ['mysql', '40001', 1213, 'Deadlock found', 'deadlock'];
        yield 'pg acesso' => ['pgsql', '28P01', null, 'password authentication failed for user "x"', 'senha recusados'];
        yield 'pg banco' => ['pgsql', '3D000', null, 'database "x" does not exist', 'não existe'];
        yield 'pg host' => ['pgsql', '08006', null, 'could not connect to server: Connection refused', 'alcançar'];
        yield 'pg tabela' => ['pgsql', '42P01', null, 'relation "x" does not exist', 'inexistente'];
        yield 'pg deadlock' => ['pgsql', '40P01', null, 'deadlock detected', 'deadlock'];
        yield 'sqlite tabela' => ['sqlite', 'HY000', 1, 'no such table: x', 'inexistente'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('erros')]
    public function testTraduzErroSemVazarOTextoDoDriver(string $driver, string $estado, ?int $codigo, string $texto, string $esperado): void
    {
        $pdo = new \PDOException($texto);
        $pdo->errorInfo = [$estado, $codigo, $texto];
        $erro = new DriverException(ExcecaoPdo::new($pdo), null);

        $msg = Dialetos::porNome($driver)->traduzirErro($erro);

        self::assertStringContainsString($esperado, $msg);
        self::assertStringNotContainsString('root', $msg);
        self::assertStringNotContainsString('1.2.3.4', $msg);
        self::assertStringNotContainsString($texto, $msg);
    }

    public function testDeadlockEDesconhecido(): void
    {
        $pdo = new \PDOException('x');
        $pdo->errorInfo = ['40001', 1213, 'Deadlock'];
        $erro = new DriverException(ExcecaoPdo::new($pdo), null);
        self::assertTrue(Dialetos::porNome('mysql')->ehDeadlock($erro));
        self::assertFalse(Dialetos::porNome('sqlite')->ehDeadlock($erro), 'SQLITE_BUSY não é deadlock');
        self::assertSame('Erro no banco de dados.', Dialetos::porNome('pgsql')->traduzirErro(new \RuntimeException('segredo')));
    }

    public function testNomeDeTravaInvalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->semConexao(Dialetos::porNome('mysql'), str_repeat('a', 65));
    }

    private function semConexao(Dialeto $d, string $nome): void
    {
        $d->obterTrava(\Pbl\Banco\Conexao::memoria(), $nome);
    }
}
