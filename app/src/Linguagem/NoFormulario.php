<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** `formulario`: imprime o formulário público de envio (ADR 0013). Sem corpo; `id=` opcional (padrão: o objeto corrente). */
final class NoFormulario implements No
{
    /** @param array<string, Expr> $params id */
    public function __construct(public readonly array $params)
    {
    }
}
