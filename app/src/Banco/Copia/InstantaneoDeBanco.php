<?php

declare(strict_types=1);

namespace Pbl\Banco\Copia;

use Doctrine\DBAL\Connection;
use Pbl\Banco\CarregadorDeConfigBanco;
use Pbl\Banco\ConfigBanco;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use Pbl\Caminhos;

/**
 * O banco da instalação visto como "uma cópia que se tira e se devolve" (cópias de segurança, atualização e volta atrás).
 *
 * O instantâneo é SEMPRE um arquivo SQLite, qualquer que seja o banco de verdade: no SQLite é a cópia consistente do próprio arquivo;
 * no MySQL, MariaDB e PostgreSQL é um arquivo SQLite novo, migrado até a mesma versão do esquema e preenchido com o `CopiadorDeTabelas`
 * (conferido tabela a tabela). Assim o formato do pacote, as somas de verificação e a checagem de integridade ficam iguais para todos
 * os bancos, e um instantâneo pode ser devolvido até em outro tipo de banco (ADR 0028, D7: cópia em PHP, sem `mysqldump`).
 */
final class InstantaneoDeBanco
{
    public function __construct(private readonly Caminhos $caminhos)
    {
    }

    public function config(): ConfigBanco
    {
        return (new CarregadorDeConfigBanco($this->caminhos))->carregar();
    }

    /** Há banco instalado para copiar? (SQLite: o arquivo existe; servidor: a tabela de versão existe) */
    public function existe(): bool
    {
        $config = $this->config();
        if ($config->ehSqlite()) {
            return is_file($config->nome);
        }
        try {
            return Conexao::abrir($config)->createSchemaManager()->tablesExist(['schema_versao']);
        } catch (\Throwable) {
            return false;
        }
    }

    /** A maior migração aplicada hoje, ou null se o banco não é legível. */
    public function versaoDoEsquema(): ?int
    {
        $config = $this->config();
        if ($config->ehSqlite() && !is_file($config->nome)) {
            return null; // abrir criaria um arquivo vazio
        }
        try {
            $v = Conexao::abrir($config)->fetchOne('SELECT MAX(versao) FROM schema_versao');
        } catch (\Throwable) {
            return null;
        }

        return is_numeric($v) ? (int) $v : null;
    }

    /** Tamanho aproximado do instantâneo, para conferir o espaço em disco antes. */
    public function tamanhoAproximado(): int
    {
        $config = $this->config();
        if ($config->ehSqlite()) {
            return is_file($config->nome) ? (int) filesize($config->nome) : 0;
        }
        $c = Conexao::abrir($config);
        $linhas = 0;
        foreach (CopiadorDeTabelas::tabelas($c) as $t) {
            $linhas += (int) $c->fetchOne('SELECT COUNT(*) FROM ' . $c->quoteSingleIdentifier($t));
        }

        return $linhas * 2048;
    }

    /** @throws \RuntimeException se não foi possível copiar ou a conferência achou diferença */
    public function tirar(string $arquivoSqlite): void
    {
        $config = $this->config();
        if ($config->ehSqlite()) {
            \Pbl\Atualizacao\CopiaDeBanco::copiar($config->nome, $arquivoSqlite);

            return;
        }
        $origem = Conexao::abrir($config);
        @unlink($arquivoSqlite);
        $copia = Conexao::sqlite($arquivoSqlite);
        $versao = $origem->fetchOne('SELECT MAX(versao) FROM schema_versao');
        (new Migrador($copia))->migrar(is_numeric($versao) ? (int) $versao : null);
        $copiador = new CopiadorDeTabelas($origem, $copia);
        $copiador->copiar();
        $erros = $copiador->divergencias();
        $copia->close();
        if ($erros !== []) {
            @unlink($arquivoSqlite);
            throw new \RuntimeException('A cópia do banco não confere com o original: ' . implode(' ', $erros));
        }
        @chmod($arquivoSqlite, 0600);
    }

    /**
     * Devolve um instantâneo ao banco da instalação. No SQLite troca o arquivo (o `arquivoSqlite` é consumido: é movido para o lugar);
     * nos de servidor apaga as tabelas do Publicare, recria o esquema na versão do instantâneo e copia o conteúdo de volta.
     *
     * @throws \RuntimeException
     */
    public function restaurar(string $arquivoSqlite): void
    {
        $config = $this->config();
        if ($config->ehSqlite()) {
            $banco = $config->nome;
            // Restos do modo WAL de outro processo valeriam para o banco antigo, não para o que entra.
            @unlink($banco . '-wal');
            @unlink($banco . '-shm');
            if (!@rename($arquivoSqlite, $banco)) {
                if (!@copy($arquivoSqlite, $banco)) {
                    throw new \RuntimeException('Não foi possível colocar o banco restaurado no lugar.');
                }
            }
            @chmod($banco, 0600);

            return;
        }
        $copia = Conexao::sqlite($arquivoSqlite);
        $versao = $copia->fetchOne('SELECT MAX(versao) FROM schema_versao');
        if (!is_numeric($versao)) {
            throw new \RuntimeException('O instantâneo não é um banco do Publicare legível.');
        }
        $destino = Conexao::abrir($config);
        self::apagarTabelasDoPublicare($destino);
        (new Migrador($destino))->migrar((int) $versao);
        $copiador = new CopiadorDeTabelas($copia, $destino);
        $copiador->copiar();
        $erros = $copiador->divergencias();
        $copia->close();
        if ($erros !== []) {
            throw new \RuntimeException('O banco restaurado não confere com a cópia: ' . implode(' ', $erros));
        }
    }

    /** Só as tabelas que as migrações do Publicare criam: um banco compartilhado com outro sistema não perde o que não é nosso. */
    private static function apagarTabelasDoPublicare(Connection $c): void
    {
        $modelo = Conexao::memoria();
        (new Migrador($modelo))->migrar();
        $nossas = array_merge(CopiadorDeTabelas::tabelas($modelo), ['schema_versao']);
        $modelo->close();
        $mysql = str_contains($c->getDatabasePlatform()::class, 'MySQL') || str_contains($c->getDatabasePlatform()::class, 'MariaDB');
        $pg = str_contains($c->getDatabasePlatform()::class, 'PostgreSQL');
        if ($mysql) {
            $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        }
        foreach ($c->createSchemaManager()->listTableNames() as $tabela) {
            if (in_array($tabela, $nossas, true)) {
                $c->executeStatement('DROP TABLE ' . $c->quoteSingleIdentifier($tabela) . ($pg ? ' CASCADE' : ''));
            }
        }
        if ($mysql) {
            $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
