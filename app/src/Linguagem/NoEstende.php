<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** `estende "base"`: precisa ser o primeiro comando do molde. */
final class NoEstende implements No
{
    public function __construct(public readonly string $layout, public readonly int $linha)
    {
    }
}
