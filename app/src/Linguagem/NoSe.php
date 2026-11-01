<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

final class NoSe implements No
{
    /**
     * @param list<No> $entao
     * @param list<No> $senao
     */
    public function __construct(public readonly Expr $condicao, public array $entao = [], public array $senao = [])
    {
    }
}
