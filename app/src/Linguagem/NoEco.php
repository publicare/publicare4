<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

final class NoEco implements No
{
    public function __construct(public readonly string $campo, public readonly bool $cru)
    {
    }
}
