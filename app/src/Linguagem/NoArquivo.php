<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** `<@arquivo valor=… info="url|nome|tamanho|tipo"@>`: imprime, sempre escapado, um dado de uma propriedade `arquivo`/`imagem`. */
final class NoArquivo implements No
{
    /** @param array<string, Expr> $params */
    public function __construct(public readonly array $params)
    {
    }
}
