<?php

declare(strict_types=1);

namespace Pbl\Banco;

/**
 * Configuração do banco (ADR 0028 §4): objeto de valor imutável e já validado. Guarda só o usuário de OPERAÇÃO;
 * o usuário de instalação/DDL nunca entra aqui. A senha fica em campo privado e não aparece em var_dump, serialização
 * nem na exportação para array: só o dialeto a lê, por {@see segredo()}.
 */
final class ConfigBanco
{
    public const DRIVERS = ['sqlite', 'mysql', 'pgsql'];
    public const TLS = ['desligado', 'exigir', 'verificar'];
    public const PORTA_PADRAO = ['mysql' => 3306, 'pgsql' => 5432];

    public readonly int $porta;

    private readonly string $senha;

    /**
     * @param string $nome SQLite: caminho do arquivo; MySQL/PostgreSQL: nome do banco
     * @throws ConfigDeBancoInvalida
     */
    public function __construct(
        public readonly string $driver,
        public readonly string $nome,
        public readonly string $host = '',
        ?int $porta = null,
        public readonly string $usuario = '',
        string $senha = '',
        public readonly ?string $schema = null,
        public readonly string $tls = 'desligado',
        public readonly bool $modoSimples = false,
    ) {
        if (!in_array($driver, self::DRIVERS, true)) {
            throw new ConfigDeBancoInvalida('driver inválido: use ' . implode(', ', self::DRIVERS) . '.');
        }
        if (!in_array($tls, self::TLS, true)) {
            throw new ConfigDeBancoInvalida('tls inválido: use ' . implode(', ', self::TLS) . '.');
        }
        if (str_contains($senha, "\0") || strlen($senha) > 1024) {
            throw new ConfigDeBancoInvalida('senha inválida (caractere nulo ou mais de 1024 bytes).');
        }
        $this->senha = $senha;
        if ($driver === 'sqlite') {
            $this->validarSqlite($porta);
            $this->porta = 0;

            return;
        }
        $this->porta = $this->validarRemoto($porta);
    }

    public static function sqlite(string $arquivo): self
    {
        return new self('sqlite', $arquivo);
    }

    /** A senha em claro, só para o dialeto montar a conexão. Nunca registre nem exiba. */
    public function segredo(): string
    {
        return $this->senha;
    }

    public function ehSqlite(): bool
    {
        return $this->driver === 'sqlite';
    }

    /** @return array<string, string|int|bool|null> Sem a senha. */
    public function paraArray(): array
    {
        return [
            'driver' => $this->driver, 'host' => $this->host, 'porta' => $this->porta, 'nome' => $this->nome,
            'usuario' => $this->usuario, 'schema' => $this->schema, 'tls' => $this->tls, 'modo_simples' => $this->modoSimples,
        ];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->paraArray() + ['senha' => $this->senha === '' ? '' : '***'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return $this->__debugInfo();
    }

    private function validarSqlite(?int $porta): void
    {
        if ($this->nome === '' || str_contains($this->nome, "\0")) {
            throw new ConfigDeBancoInvalida('nome: informe o caminho do arquivo SQLite.');
        }
        if ($this->host !== '' || $porta !== null || $this->usuario !== '' || $this->senha !== '' || $this->schema !== null) {
            throw new ConfigDeBancoInvalida('SQLite não usa host, porta, usuário, senha nem schema.');
        }
        if ($this->tls !== 'desligado') {
            throw new ConfigDeBancoInvalida('SQLite não usa TLS.');
        }
    }

    private function validarRemoto(?int $porta): int
    {
        if ($this->host === '' || strlen($this->host) > 253 || preg_match('/^[A-Za-z0-9._:\[\]-]+$/D', $this->host) !== 1) {
            throw new ConfigDeBancoInvalida('host inválido: use nome de máquina ou endereço IP, sem espaços.');
        }
        $porta ??= self::PORTA_PADRAO[$this->driver];
        if ($porta < 1 || $porta > 65535) {
            throw new ConfigDeBancoInvalida('porta inválida: use um número de 1 a 65535.');
        }
        $limite = $this->driver === 'mysql' ? 64 : 63;
        if (preg_match('/^[A-Za-z0-9_$-]+$/D', $this->nome) !== 1 || strlen($this->nome) > $limite) {
            throw new ConfigDeBancoInvalida("nome do banco inválido: use letras, números, _ , \$ ou -, até {$limite} caracteres.");
        }
        if ($this->usuario === '' || strlen($this->usuario) > 128 || preg_match('/[\x00-\x1f\x7f]/', $this->usuario) === 1) {
            throw new ConfigDeBancoInvalida('usuário inválido: informe o usuário de operação (até 128 caracteres, sem controle).');
        }
        if ($this->schema !== null) {
            if ($this->driver !== 'pgsql') {
                throw new ConfigDeBancoInvalida('schema só vale para o driver pgsql.');
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $this->schema) !== 1) {
                throw new ConfigDeBancoInvalida('schema inválido: use letras, números e _, começando por letra ou _, até 63 caracteres.');
            }
        }

        return $porta;
    }
}
