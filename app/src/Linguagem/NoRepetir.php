<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

final class NoRepetir implements No
{
    /** @param list<No> $corpo */
    public function __construct(public readonly string $fonte, public array $corpo = [])
    {
    }
}
