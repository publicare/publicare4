<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

/** O resultado de olhar o canal de atualizações: há versão nova (e qual) ou não (e por quê). */
final class Verificacao
{
    public function __construct(public readonly bool $disponivel, public readonly ?Manifesto $manifesto, public readonly string $motivo)
    {
    }
}
