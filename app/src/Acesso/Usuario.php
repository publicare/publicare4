<?php

declare(strict_types=1);

namespace Pbl\Acesso;

final class Usuario
{
    public function __construct(
        public readonly int $id,
        public readonly string $login,
        public readonly string $email,
        public readonly string $nome,
        public readonly bool $administrador,
        public readonly bool $ativo,
    ) {
    }
}
