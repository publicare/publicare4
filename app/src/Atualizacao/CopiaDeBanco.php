<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

use Pbl\Banco\Conexao;

/** Cópia consistente do banco SQLite e leitura da versão do esquema (a maior migração aplicada). */
final class CopiaDeBanco
{
    /** @throws ErroDeAtualizacao */
    public static function copiar(string $origem, string $destino): void
    {
        try {
            // VACUUM INTO gera uma cópia consistente mesmo com o arquivo aberto por outro processo (SQLite 3.27+).
            Conexao::sqlite($origem)->executeStatement('VACUUM INTO ' . "'" . str_replace("'", "''", $destino) . "'");
        } catch (\Throwable) {
            @unlink($destino);
            if (!@copy($origem, $destino)) {
                throw new ErroDeAtualizacao('Não foi possível copiar o banco.');
            }
        }
        @chmod($destino, 0600);
    }

    /** A maior migração aplicada, ou null se o arquivo não é um banco do PBL4 legível. */
    public static function versaoDoEsquema(string $arquivo): ?int
    {
        if (!is_file($arquivo)) {
            return null;
        }
        try {
            $v = Conexao::sqlite($arquivo)->fetchOne('SELECT MAX(versao) FROM schema_versao');
        } catch (\Throwable) {
            return null;
        }

        return is_numeric($v) ? (int) $v : null;
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
}
