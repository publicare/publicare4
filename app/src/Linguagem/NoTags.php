<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** `tags`: a lista de tags do objeto corrente (HTML pronto, com link para a página de cada tag). Sem corpo nem parâmetros. */
final class NoTags implements No
{
    /** @param array<string, Expr> $params */
    public function __construct(public readonly array $params = [])
    {
    }
}
