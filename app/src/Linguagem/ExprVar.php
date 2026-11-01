<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** `$variavel`: variável do molde (isolada, não é global do PHP). */
final class ExprVar implements Expr
{
    public function __construct(public readonly string $nome)
    {
    }
}
