<?php

declare(strict_types=1);

namespace Pbl\Banco\Dialeto;

use Doctrine\DBAL\Connection;
use Pbl\Banco\ConfigBanco;

/** PostgreSQL 16. */
final class DialetoPostgres extends DialetoBase
{
    private const SSLMODE = ['desligado' => 'disable', 'exigir' => 'require', 'verificar' => 'verify-full'];

    public function nome(): string
    {
        return 'pgsql';
    }

    public function opcoesDeConexao(ConfigBanco $config): array
    {
        return [
            'driver' => 'pdo_pgsql', 'host' => $config->host, 'port' => $config->porta, 'dbname' => $config->nome,
            'user' => $config->usuario, 'password' => $config->segredo(), 'charset' => 'utf8',
            'sslmode' => self::SSLMODE[$config->tls],
        ];
    }

    public function aposConectar(Connection $conexao, ConfigBanco $config): void
    {
        $conexao->executeStatement("SET TIME ZONE 'UTC'");
        if ($config->schema !== null) {
            // O nome já foi validado em ConfigBanco (só [A-Za-z0-9_]), então a citação é segura.
            $conexao->executeStatement('SET search_path TO "' . $config->schema . '"');
        }
    }

    public function opcoesDeTabela(): array
    {
        return [];
    }

    public function ddlTransacional(): bool
    {
        return true;
    }

    public function limiteDeIdentificador(): int
    {
        return 63;
    }

    public function obterTrava(Connection $conexao, string $nome, int $esperaSeg = 0): bool
    {
        if ($nome === '') {
            throw new \InvalidArgumentException('Nome de trava inválido.');
        }
        $limite = microtime(true) + max(0, $esperaSeg);
        do {
            if ($conexao->fetchOne('SELECT pg_try_advisory_lock(hashtext(?))', [$nome]) === true) {
                return true;
            }
            usleep(100_000);
        } while (microtime(true) < $limite);

        return false;
    }

    public function soltarTrava(Connection $conexao, string $nome): void
    {
        $conexao->fetchOne('SELECT pg_advisory_unlock(hashtext(?))', [$nome]);
    }

    protected function classificarDriver(?string $estado, ?int $codigo, string $texto): ?string
    {
        if (str_contains($texto, 'ssl') || str_contains($texto, 'certificate')) {
            return 'tls';
        }

        return match (true) {
            // senha errada na conexão pode vir como 08006 (o PDO embrulha o FATAL): o texto decide antes do estado
            $estado === '28P01', $estado === '28000', str_contains($texto, 'password authentication failed'), str_contains($texto, 'authentication failed'), str_contains($texto, 'no pg_hba.conf entry') => 'acesso',
            $estado === '42501' => 'permissao',
            $estado === '3D000' => 'banco',
            $estado === '42P01', $estado === '42703' => 'tabela',
            $estado === '23505' => 'duplicado',
            $estado === '40P01', $estado === '40001' => 'deadlock',
            $estado === '57014', $estado === '55P03' => 'timeout',
            // 08006 também vem de "connection refused": o texto distingue servidor inalcançável de conexão perdida.
            $estado === '08001', str_contains($texto, 'could not connect'), str_contains($texto, 'connection refused'), str_contains($texto, 'could not translate host') => 'host',
            $estado === '57P01', $estado === '08006', $estado === '08003' => 'conexao',
            default => null,
        };
    }
}
