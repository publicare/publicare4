<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** `%MACRO`: valor calculado pelo runtime (DIA, MES, ANO, DATA, INDICE, FIM, QUANTIDADE). */
final class ExprMacro implements Expr
{
    public function __construct(public readonly string $nome)
    {
    }
}
