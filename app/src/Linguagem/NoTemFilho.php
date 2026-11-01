<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** `temfilho`: executa o bloco se o objeto (corrente ou `id=`) tem filhos publicados. */
final class NoTemFilho implements No
{
    /**
     * @param array<string, Expr> $params id
     * @param list<No> $corpo
     */
    public function __construct(public readonly array $params, public array $corpo = [])
    {
    }
}
