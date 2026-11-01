<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** `semfilhos`: executa se o último laço de coleção não encontrou nada. */
final class NoSemResultado implements No
{
    /** @param list<No> $corpo */
    public function __construct(public array $corpo = [])
    {
    }
}
