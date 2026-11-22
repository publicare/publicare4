<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;

/** O resultado de uma ação: `dados` (contrato JSON) e o `texto` legível do modo humano. O texto é limpo na saída. */
final class Resposta
{
    /** @param array<string, mixed> $dados */
    public function __construct(public readonly array $dados, public readonly string $texto)
    {
    }
}
