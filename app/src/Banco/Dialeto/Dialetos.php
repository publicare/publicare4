<?php

declare(strict_types=1);

namespace Pbl\Banco\Dialeto;

/** Escolhe o dialeto pelo nome do driver da configuração. */
final class Dialetos
{
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
