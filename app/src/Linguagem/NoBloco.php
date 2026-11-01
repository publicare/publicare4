<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** `bloco nome`: ponto que um molde filho pode sobrescrever (herança de layout). */
final class NoBloco implements No
{
    /** @param list<No> $corpo */
    public function __construct(public readonly string $nome, public array $corpo = [], public readonly int $linha = 0)
    {
    }
}
