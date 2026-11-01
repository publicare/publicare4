<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

final class NoIncluir implements No
{
    public function __construct(public readonly Expr $arquivo)
    {
    }
}
