<?php

declare(strict_types=1);

namespace Pbl\Acesso;

final class Usuario
{
    public const PESSOA = 'pessoa';
    public const SERVICO = 'servico';
    /** Marca em `senha_hash` que nunca confere com nenhuma senha. */
    public const SENHA_DE_SERVICO = '!servico';
    /** Marca em `senha_hash` da conta convidada que ainda não escolheu a senha; nunca confere. */
    public const SENHA_DE_CONVITE = '!convite';

    public function __construct(
        public readonly int $id,
        public readonly string $login,
        public readonly string $email,
        public readonly string $nome,
        public readonly bool $administrador,
        public readonly bool $ativo,
        /** `pessoa` (entra pelo painel) ou `servico` (agente via CLI, sem senha; ADR 0031). */
        public readonly string $tipo = self::PESSOA,
    ) {
    }

    public function ehServico(): bool
    {
        return $this->tipo === self::SERVICO;
    }
}
