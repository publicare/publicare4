<?php

declare(strict_types=1);

namespace Pbl\Banco\Dialeto;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/** Escolhe o dialeto pelo nome do driver da configuração. */
final class Dialetos
{
    /** Dialeto de uma conexão já aberta, pela plataforma do DBAL (MySQL e MariaDB usam o mesmo). */
    public static function paraConexao(Connection $conexao): Dialeto
    {
        $plataforma = $conexao->getDatabasePlatform();

        return match (true) {
            $plataforma instanceof AbstractMySQLPlatform => new DialetoMysql(),
            $plataforma instanceof PostgreSQLPlatform => new DialetoPostgres(),
            $plataforma instanceof SQLitePlatform => new DialetoSqlite(),
            default => throw new \InvalidArgumentException('Banco não suportado. Válidos: SQLite, MySQL, MariaDB e PostgreSQL.'),
        };
    }

    public static function porNome(string $nome): Dialeto
    {
        return match ($nome) {
            'sqlite' => new DialetoSqlite(),
            'mysql' => new DialetoMysql(),
            'pgsql' => new DialetoPostgres(),
            default => throw new \InvalidArgumentException("Driver de banco desconhecido. Válidos: sqlite, mysql, pgsql."),
        };
    }
}
