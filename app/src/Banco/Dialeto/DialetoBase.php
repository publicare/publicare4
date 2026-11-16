<?php

declare(strict_types=1);

namespace Pbl\Banco\Dialeto;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Pbl\Banco\ConfigBanco;

/** Parte comum: classificação do erro e mensagens fixas em português. */
abstract class DialetoBase implements Dialeto
{
    private const MENSAGENS = [
        'acesso' => 'Usuário ou senha recusados pelo servidor de banco de dados.',
        'permissao' => 'O usuário do banco não tem permissão para esta operação.',
        'banco' => 'O banco de dados informado não existe no servidor.',
        'host' => 'Não foi possível alcançar o servidor de banco de dados (confira host, porta e firewall).',
        'tls' => 'A conexão segura (TLS) com o servidor falhou ou foi recusada.',
        'timeout' => 'O servidor de banco de dados demorou demais para responder.',
        'tabela' => 'Tabela ou coluna inexistente no banco de dados.',
        'duplicado' => 'Já existe um registro com este valor único.',
        'deadlock' => 'Conflito de concorrência no banco de dados (deadlock); tente de novo.',
        'conexao' => 'A conexão com o banco de dados foi perdida.',
        'desconhecido' => 'Erro no banco de dados.',
    ];

    public function aposConectar(Connection $conexao, ConfigBanco $config): void
    {
    }

    public function traduzirErro(\Throwable $erro): string
    {
        return self::MENSAGENS[$this->classificar($erro)];
    }

    public function ehDeadlock(\Throwable $erro): bool
    {
        return $this->classificar($erro) === 'deadlock';
    }

    /**
     * Classe do erro (chave de MENSAGENS). O texto do driver só é lido para classificar; nunca é devolvido.
     */
    protected function classificar(\Throwable $erro): string
    {
        [$estado, $codigo, $texto] = $this->dadosDoDriver($erro);
        $porDriver = $this->classificarDriver($estado, $codigo, strtolower($texto));
        if ($porDriver !== null) {
            return $porDriver;
        }

        return match (true) {
            $erro instanceof DeadlockException => 'deadlock',
            $erro instanceof LockWaitTimeoutException => 'timeout',
            $erro instanceof TableNotFoundException => 'tabela',
            $erro instanceof UniqueConstraintViolationException => 'duplicado',
            $erro instanceof ConnectionException => 'host',
            default => 'desconhecido',
        };
    }

    /** @return string|null chave de MENSAGENS, ou null se o dialeto não reconhece */
    abstract protected function classificarDriver(?string $estado, ?int $codigo, string $texto): ?string;

    /** @return array{0: ?string, 1: ?int, 2: string} SQLSTATE, código do driver, texto (só para classificar) */
    private function dadosDoDriver(\Throwable $erro): array
    {
        $estado = null;
        $codigo = null;
        $texto = '';
        for ($e = $erro; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof DriverException && $estado === null) {
                $estado = $e->getSQLState();
            }
            if ($e instanceof \PDOException && is_array($e->errorInfo)) {
                $estado ??= is_string($e->errorInfo[0] ?? null) ? $e->errorInfo[0] : null;
                $codigo ??= is_int($e->errorInfo[1] ?? null) ? $e->errorInfo[1] : null;
            }
            if ($e instanceof \PDOException && $codigo === null && is_int($e->getCode())) {
                $codigo = $e->getCode();
            }
            $texto .= ' ' . $e->getMessage();
        }
        if ($estado === null && $erro instanceof \PDOException && is_string($erro->getCode())) {
            $estado = $erro->getCode();
        }

        return [$estado, $codigo, $texto];
    }
}
