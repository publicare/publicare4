<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** `#campo`: campo do objeto corrente. */
final class ExprCampo implements Expr
{
    public function __construct(public readonly string $nome)
    {
    }
}
