<?php

declare(strict_types=1);

namespace Pbl\Banco\Dialeto;

use Doctrine\DBAL\Connection;
use Pbl\Banco\ConfigBanco;

/** SQLite: o padrão simples (ADR 0003). Comportamento idêntico ao de antes da camada de dialeto. */
final class DialetoSqlite extends DialetoBase
{
    /** @var array<string, resource> travas `flock` em posse deste processo, por arquivo+nome */
    private array $travas = [];

    public function nome(): string
    {
        return 'sqlite';
    }

    public function opcoesDeConexao(ConfigBanco $config): array
    {
        if ($config->nome === ':memory:') {
            return ['driver' => 'pdo_sqlite', 'memory' => true];
        }

        return ['driver' => 'pdo_sqlite', 'path' => $config->nome];
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
        return 0;
    }

    /**
     * A trava que o projeto já usa é `flock` de arquivo (como `Atualizacao\Trava`): fica em `migracao.lock`, ao lado do
     * arquivo do banco. Banco em memória não tem concorrência: sempre obtém.
     */
    public function obterTrava(Connection $conexao, string $nome, int $esperaSeg = 0): bool
    {
        $arquivo = $this->arquivoDaTrava($conexao, $nome);
        if ($arquivo === null) {
            return true;
        }
        $f = @fopen($arquivo, 'c');
        if ($f === false) {
            return false;
        }
        $limite = microtime(true) + max(0, $esperaSeg);
        do {
            if (flock($f, LOCK_EX | LOCK_NB)) {
                $this->travas[$arquivo] = $f;

                return true;
            }
            usleep(50_000);
        } while (microtime(true) < $limite);
        fclose($f);

        return false;
    }

    public function soltarTrava(Connection $conexao, string $nome): void
    {
        $arquivo = $this->arquivoDaTrava($conexao, $nome);
        if ($arquivo === null || !isset($this->travas[$arquivo])) {
            return;
        }
        flock($this->travas[$arquivo], LOCK_UN);
        fclose($this->travas[$arquivo]);
        unset($this->travas[$arquivo]);
    }

    /** SQLITE_BUSY é "banco ocupado", não deadlock: o SQLite serializa a escrita. */
    public function ehDeadlock(\Throwable $erro): bool
    {
        return false;
    }

    protected function classificarDriver(?string $estado, ?int $codigo, string $texto): ?string
    {
        return match (true) {
            str_contains($texto, 'no such table'), str_contains($texto, 'no such column') => 'tabela',
            str_contains($texto, 'unique constraint failed') => 'duplicado',
            str_contains($texto, 'database is locked'), str_contains($texto, 'database table is locked') => 'timeout',
            str_contains($texto, 'unable to open database'), str_contains($texto, 'not a database') => 'host',
            default => null,
        };
    }

    private function arquivoDaTrava(Connection $conexao, string $nome): ?string
    {
        if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $nome) !== 1) {
            throw new \InvalidArgumentException('Nome de trava inválido.');
        }
        $caminho = $conexao->getParams()['path'] ?? null;
        if (!is_string($caminho) || $caminho === '') {
            return null;
        }

        return dirname($caminho) . '/' . $nome . '.lock';
    }
}
