<?php

declare(strict_types=1);

namespace Pbl\Banco\Dialeto;

use Doctrine\DBAL\Connection;
use Pbl\Banco\ConfigBanco;
use Pbl\Banco\ConfigDeBancoInvalida;

/** MySQL 8 (MariaDB fora do alvo, ADR 0028 D1). */
final class DialetoMysql extends DialetoBase
{
    private const LIMITE_TRAVA = 64;

    public function nome(): string
    {
        return 'mysql';
    }

    public function opcoesDeConexao(ConfigBanco $config): array
    {
        $opcoes = [
            'driver' => 'pdo_mysql', 'host' => $config->host, 'port' => $config->porta, 'dbname' => $config->nome,
            'user' => $config->usuario, 'password' => $config->segredo(), 'charset' => 'utf8mb4',
        ];
        if ($config->tls !== 'desligado') {
            $opcoes['driverOptions'] = $this->opcoesDeTls($config->tls);
        }

        return $opcoes;
    }

    public function aposConectar(Connection $conexao, ConfigBanco $config): void
    {
        $conexao->executeStatement(
            "SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION', time_zone = '+00:00'",
        );
        if ($config->tls !== 'desligado') {
            $cifra = $conexao->fetchAssociative("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
            if (!is_array($cifra) || ($cifra['Value'] ?? '') === '') {
                $conexao->close();
                throw new ConfigDeBancoInvalida('tls: o servidor não negociou conexão segura, e a configuração a exige.');
            }
        }
    }

    public function opcoesDeTabela(): array
    {
        return ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin', 'engine' => 'InnoDB'];
    }

    public function ddlTransacional(): bool
    {
        return false;
    }

    public function limiteDeIdentificador(): int
    {
        return 64;
    }

    public function obterTrava(Connection $conexao, string $nome, int $esperaSeg = 0): bool
    {
        if ($nome === '' || strlen($nome) > self::LIMITE_TRAVA) {
            throw new \InvalidArgumentException('Nome de trava inválido: use de 1 a 64 caracteres.');
        }

        return (int) $conexao->fetchOne('SELECT GET_LOCK(?, ?)', [$nome, max(0, $esperaSeg)]) === 1;
    }

    public function soltarTrava(Connection $conexao, string $nome): void
    {
        $conexao->fetchOne('SELECT RELEASE_LOCK(?)', [$nome]);
    }

    protected function classificarDriver(?string $estado, ?int $codigo, string $texto): ?string
    {
        if (str_contains($texto, 'ssl') || str_contains($texto, 'tls') || str_contains($texto, 'certificate')) {
            return 'tls';
        }

        return match ($codigo) {
            1045 => 'acesso',
            1044, 1142, 1143, 1227 => 'permissao',
            1049 => 'banco',
            2002, 2003, 2005 => 'host',
            2006, 2013 => 'conexao',
            1146, 1054 => 'tabela',
            1062 => 'duplicado',
            1213 => 'deadlock',
            1205 => 'timeout',
            default => $estado === '40001' ? 'deadlock' : null,
        };
    }

    /** @return array<int, mixed> */
    private function opcoesDeTls(string $modo): array
    {
        $verificar = $modo === 'verificar';
        $opcoes = [
            self::atributo('ATTR_SSL_VERIFY_SERVER_CERT') => $verificar,
            self::atributo('ATTR_SSL_CIPHER') => 'DEFAULT',
        ];
        $ca = $verificar ? self::cadeiaDoSistema() : null;
        if ($ca !== null) {
            $opcoes[self::atributo('ATTR_SSL_CA')] = $ca;
        }

        return $opcoes;
    }

    /** `Pdo\Mysql::ATTR_*` (PHP 8.4+) ou `PDO::MYSQL_ATTR_*`, sem deprecação. */
    private static function atributo(string $nome): int
    {
        foreach (['Pdo\\Mysql::' . $nome, 'PDO::MYSQL_' . $nome] as $constante) {
            if (defined($constante)) {
                $valor = constant($constante);

                return is_int($valor) ? $valor : 0;
            }
        }
        throw new \RuntimeException('A extensão pdo_mysql não está carregada.');
    }

    private static function cadeiaDoSistema(): ?string
    {
        $padrao = openssl_get_cert_locations()['default_cert_file'] ?? '';
        foreach ([$padrao, '/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt'] as $arquivo) {
            if ($arquivo !== '' && is_file($arquivo)) {
                return $arquivo;
            }
        }

        return null;
    }
}
