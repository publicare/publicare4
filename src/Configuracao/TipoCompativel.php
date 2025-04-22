<?php

namespace Configuracao;

use Doctrine\DBAL\Connection;

class TipoCompativel
{
    private string $driver;

    public function __construct(Connection $conexao)
    {
        $this->driver = $conexao->getDatabasePlatform()->getName();
    }

    public function texto(): string
    {
        return match ($this->driver) {
            'oci8', 'oracle' => 'CLOB',
            default          => 'TEXT',
        };
    }

    public function varchar(int $tamanho = 255): string
    {
        return "VARCHAR({$tamanho})";
    }

    public function bigint(): string
    {
        return match ($this->driver) {
            'oci8', 'oracle' => 'NUMBER(19)',
            default          => 'BIGINT',
        };
    }

    public function inteiro(): string
    {
        return match ($this->driver) {
            'oci8', 'oracle' => 'NUMBER(10)',
            default          => 'INT',
        };
    }

    public function decimal(int $precisao = 18, int $escala = 2): string
    {
        return match ($this->driver) {
            'oci8', 'oracle' => "NUMBER({$precisao}, {$escala})",
            default          => "DECIMAL({$precisao}, {$escala})",
        };
    }

    public function timestamp(): string
    {
        return match ($this->driver) {
            'oci8', 'oracle' => 'TIMESTAMP',
            default          => 'TIMESTAMP',
        };
    }

    public function booleano(): string
    {
        return match ($this->driver) {
            'oci8', 'oracle' => 'NUMBER(1)',
            default          => 'SMALLINT',
        };
    }
}
