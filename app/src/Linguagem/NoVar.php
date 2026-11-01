<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

final class NoVar implements No
{
    public function __construct(public readonly string $nome, public readonly Expr $valor)
    {
    }
}
