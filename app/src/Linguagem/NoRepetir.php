<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** `repetir $i=[a,b]`: laço numérico. */
final class NoRepetir implements No
{
    /** @param list<No> $corpo */
    public function __construct(public readonly string $variavel, public readonly Expr $inicio, public readonly Expr $fim, public array $corpo = [])
    {
    }
}
