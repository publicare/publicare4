<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

final class Aplicada
{
    public function __construct(public readonly string $de, public readonly string $para, public readonly string $bancoCopiadoPara)
    {
    }
}
