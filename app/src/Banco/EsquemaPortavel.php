<?php

declare(strict_types=1);

namespace Pbl\Banco;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaConfig;
use Pbl\Banco\Dialeto\Dialetos;

/**
 * Esquema novo já com as opções de tabela do dialeto (ADR 0028 §3): no MySQL e no MariaDB, `utf8mb4` com colação binária e InnoDB,
 * para endereços, logins e chaves se compararem como no SQLite (a colação padrão do servidor ignora maiúsculas e acentos).
 * Toda migração que cria tabela parte daqui, e não de `new Schema()`.
 */
final class EsquemaPortavel
{
    public static function novo(Connection $conexao): Schema
    {
        $config = new SchemaConfig();
        $config->setDefaultTableOptions(Dialetos::paraConexao($conexao)->opcoesDeTabela());

        return new Schema([], [], $config);
    }
}
