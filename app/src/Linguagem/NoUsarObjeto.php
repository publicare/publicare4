<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** `usarobjeto`: troca o objeto corrente (por id, url ou título) dentro do bloco; se não achar, o bloco não roda. */
final class NoUsarObjeto implements No
{
    /**
     * @param array<string, Expr> $params id, url, titulo
     * @param list<No> $corpo
     */
    public function __construct(public readonly array $params, public array $corpo = [])
    {
    }
}
