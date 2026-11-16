<?php

declare(strict_types=1);

namespace Pbl\Testes\Banco;

use Pbl\Banco\ConfigBanco;
use Pbl\Banco\ConfigDeBancoInvalida;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigBancoTest extends TestCase
{
    public function testSqliteSoPrecisaDoArquivo(): void
    {
        $c = ConfigBanco::sqlite('/x/pbl4.sqlite');

        self::assertSame('sqlite', $c->driver);
        self::assertTrue($c->ehSqlite());
        self::assertSame('/x/pbl4.sqlite', $c->nome);
    }

    public function testRemotoAplicaPortaPadrao(): void
    {
        self::assertSame(3306, (new ConfigBanco('mysql', 'pbl', 'db.local', null, 'op', 's'))->porta);
        self::assertSame(5432, (new ConfigBanco('pgsql', 'pbl', 'db.local', null, 'op', 's', 'publico'))->porta);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidos(): array
    {
        $ok = ['driver' => 'mysql', 'nome' => 'pbl', 'host' => 'h', 'usuario' => 'u', 'senha' => 's'];

        return [
            'driver' => [['driver' => 'oracle'] + $ok, 'driver'],
            'tls' => [['tls' => 'talvez'] + $ok, 'tls'],
            'host vazio' => [['host' => ''] + $ok, 'host'],
            'host com espaço' => [['host' => 'a b'] + $ok, 'host'],
            'porta' => [['porta' => 70000] + $ok, 'porta'],
            'nome' => [['nome' => 'a;drop'] + $ok, 'nome'],
            'usuario vazio' => [['usuario' => ''] + $ok, 'usuário'],
            'schema em mysql' => [['schema' => 'x'] + $ok, 'schema'],
            'schema ruim' => [['driver' => 'pgsql', 'schema' => '1; drop'] + $ok, 'schema'],
            'sqlite com host' => [['driver' => 'sqlite', 'nome' => '/a', 'host' => 'h', 'usuario' => '', 'senha' => ''], 'SQLite'],
            'sqlite sem arquivo' => [['driver' => 'sqlite', 'nome' => '', 'host' => '', 'usuario' => '', 'senha' => ''], 'nome'],
            'senha com nulo' => [['senha' => "a\0b"] + $ok, 'senha'],
        ];
    }

    /**
     * @param array<string, mixed> $campos
     */
    #[DataProvider('invalidos')]
    public function testRejeitaValoresInvalidosSemVazarASenha(array $campos, string $trecho): void
    {
        $campos['senha'] = $campos['senha'] ?? 'SENHA-SECRETA-123';
        try {
            new ConfigBanco(...$campos);
            self::fail('deveria rejeitar');
        } catch (ConfigDeBancoInvalida $e) {
            self::assertStringContainsString($trecho, $e->getMessage());
            self::assertStringNotContainsString('SENHA-SECRETA-123', $e->getMessage());
        }
    }

    public function testSenhaNaoApareceEmDumpSerializacaoNemArray(): void
    {
        $c = new ConfigBanco('mysql', 'pbl', 'h', 3306, 'u', 'SENHA-SECRETA-123');

        ob_start();
        var_dump($c);
        $saida = (string) ob_get_clean();
        self::assertStringNotContainsString('SENHA-SECRETA-123', $saida . print_r($c, true) . json_encode($c->paraArray()) . serialize($c));
        self::assertSame('SENHA-SECRETA-123', $c->segredo());
    }
}
