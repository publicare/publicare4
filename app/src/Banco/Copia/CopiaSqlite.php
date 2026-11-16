<?php

declare(strict_types=1);

namespace Pbl\Banco\Copia;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Conexao;

/** Operações de cópia e verificação que só existem no SQLite (arquivo, WAL, VACUUM). Moradia do SQL específico do SQLite fora do dialeto. */
final class CopiaSqlite
{
    /**
     * Cópia consistente do arquivo, mesmo aberto por outro processo (SQLite 3.27+).
     *
     * @throws \Throwable se o SQLite não consegue (o chamador decide o plano B)
     */
    public static function copiarConsistente(string $origem, string $destino): void
    {
        Conexao::sqlite($origem)->executeStatement('VACUUM INTO ' . "'" . str_replace("'", "''", $destino) . "'");
    }

    /** O arquivo abre como SQLite e passa na checagem de integridade do próprio SQLite. */
    public static function integro(string $arquivo): bool
    {
        try {
            return Conexao::sqlite($arquivo)->fetchOne('PRAGMA integrity_check') === 'ok';
        } catch (\Throwable) {
            return false;
        }
    }

    /** Passa ao arquivo principal tudo o que está pendente no WAL, para copiar só o `.sqlite`. */
    public static function fecharWal(Connection $conexao): void
    {
        $conexao->executeQuery('PRAGMA wal_checkpoint(TRUNCATE)')->free();
    }
}
