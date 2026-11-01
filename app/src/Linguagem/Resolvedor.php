<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** Encontra o código-fonte de outro molde (layout de `estende` ou arquivo de `incluir`) pelo nome. */
interface Resolvedor
{
    /** @throws \RuntimeException se o nome for inválido ou o molde não existir */
    public function fonte(string $nome): string;
}
