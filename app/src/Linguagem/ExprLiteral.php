<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

final class ExprLiteral implements Expr
{
    public function __construct(public readonly string|int|float $valor)
    {
    }
}
