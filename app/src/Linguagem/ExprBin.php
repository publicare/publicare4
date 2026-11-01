<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** Operação: `.` (concatenação), `+`, `-`, `*`, `/` ou comparação `== != < > <= >=`. */
final class ExprBin implements Expr
{
    public function __construct(public readonly string $op, public readonly Expr $esq, public readonly Expr $dir)
    {
    }

    public function ehComparacao(): bool
    {
        return in_array($this->op, ['==', '!=', '<', '>', '<=', '>='], true);
    }
}
