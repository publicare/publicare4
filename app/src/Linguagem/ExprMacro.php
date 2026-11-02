<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** `%MACRO`: valor calculado pelo runtime (DIA, MES, ANO, DATA, INDICE, FIM, QUANTIDADE). */
final class ExprMacro implements Expr
{
    /** Prefixo de `%SITE.config.chave` (a chave vem depois, em minúsculas). */
    public const CONFIG = 'SITE.CONFIG.';

    public function __construct(public readonly string $nome)
    {
    }
}
