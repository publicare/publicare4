<?php

declare(strict_types=1);

namespace Pbl\Banco\Dialeto;

use Doctrine\DBAL\Connection;
use Pbl\Banco\ConfigBanco;

/**
 * O que muda de um banco para outro (ADR 0028 §1). O SQL dos repositórios fica no subconjunto comum; tudo o que é
 * específico de banco mora numa implementação desta interface (ou em `Banco/Copia`).
 */
interface Dialeto
{
    /** Nome do driver na configuração: `sqlite`, `mysql` ou `pgsql`. */
    public function nome(): string;

    /** @return array<string, mixed> Parâmetros para `DriverManager::getConnection`. */
    public function opcoesDeConexao(ConfigBanco $config): array;

    /** Ajustes de sessão logo após conectar (UTC, modo estrito, schema, TLS efetivo). */
    public function aposConectar(Connection $conexao, ConfigBanco $config): void;

    /** @return array<string, string> Opções de tabela do DBAL (charset, engine, colação). */
    public function opcoesDeTabela(): array;

    /** O banco desfaz DDL num rollback? (SQLite e PostgreSQL sim; MySQL comita DDL sozinho.) */
    public function ddlTransacional(): bool;

    /** Tamanho máximo de um identificador em caracteres; 0 = sem limite prático. */
    public function limiteDeIdentificador(): int;

    /** Tenta obter a trava nomeada, esperando até `$esperaSeg` segundos. Não bloqueia além disso. */
    public function obterTrava(Connection $conexao, string $nome, int $esperaSeg = 0): bool;

    public function soltarTrava(Connection $conexao, string $nome): void;

    public function ehDeadlock(\Throwable $erro): bool;

    /** Mensagem em português, de forma fechada: nunca repete o texto do driver, a senha nem o DSN. */
    public function traduzirErro(\Throwable $erro): string;
}
