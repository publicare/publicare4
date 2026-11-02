<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** `<@miniatura valor=… tamanho=150@>`: imprime, sempre escapada, a URL da miniatura (só imagem, tamanho da whitelist). */
final class NoMiniatura implements No
{
    /** @param array<string, Expr> $params */
    public function __construct(public readonly array $params)
    {
    }
}
