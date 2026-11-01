<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

final class NoTexto implements No
{
    public function __construct(public readonly string $valor)
    {
    }
}
