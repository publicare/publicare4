<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** O que o molde pode pedir ao sistema. O runtime não conhece banco: só esta interface. */
interface FonteDeDados
{
    /**
     * Filhos publicados do objeto, como contextos (`#campo`).
     *
     * @param array<string, mixed> $objeto contexto do objeto pai (tem `id`)
     * @return list<array<string, mixed>>
     */
    public function filhos(array $objeto, string $classes, string $ordem, int $inicio, int $limite): array;
}
