<?php

declare(strict_types=1);

namespace Pbl\Banco;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/** Fábrica de conexões. SQLite é o padrão (ADR 0003); MySQL/Postgres entram por configuração depois. */
final class Conexao
{
    public static function sqlite(string $arquivo): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $arquivo]);
    }

    public static function memoria(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }
}
