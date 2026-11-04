<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** `nuvem`: a nuvem de tags do site (HTML pronto): tamanho de 1 a 5 conforme o uso. `limite=` (padrão 30) opcional. */
final class NoNuvem implements No
{
    /** @param array<string, Expr> $params limite */
    public function __construct(public readonly array $params)
    {
    }
}
