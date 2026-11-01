<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

final class Token
{
    public const STR = 'str';
    public const NUM = 'num';
    public const CAMPO = 'campo';
    public const VAR = 'var';
    public const MACRO = 'macro';
    public const ID = 'id';
    public const OP = 'op';

    public function __construct(public readonly string $tipo, public readonly string|int|float $valor)
    {
    }
}
