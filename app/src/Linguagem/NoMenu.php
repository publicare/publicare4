<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** `menu`: imprime o menu principal do site a partir do snapshot (ADR 0016). Sem corpo; `profundidade=` e `inicio=` opcionais. */
final class NoMenu implements No
{
    /** @param array<string, Expr> $params profundidade, inicio */
    public function __construct(public readonly array $params)
    {
    }
}
