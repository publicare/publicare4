<?php

declare(strict_types=1);

namespace Pbl\Acesso;

final class SessaoAtiva
{
    public function __construct(public readonly Usuario $usuario, public readonly string $idHash)
    {
    }
}
