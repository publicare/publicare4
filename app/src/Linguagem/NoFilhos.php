<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** `filhos`: laço sobre os filhos publicados do objeto corrente; dentro, `#campo` é o filho. */
final class NoFilhos implements No
{
    /**
     * @param array<string, Expr> $params classes, ordem, inicio, limite
     * @param list<No> $corpo
     */
    public function __construct(public readonly array $params, public array $corpo = [])
    {
    }
}
