<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** `eco` (escapa), `ecocru` (sem escape), `eco_limite` e `formatadata` viram chamadas de runtime sobre a expressão. */
final class NoEco implements No
{
    /** @param list<Expr> $extras */
    public function __construct(public readonly Expr $expr, public readonly bool $cru, public readonly ?string $funcao = null, public readonly array $extras = [])
    {
    }
}
