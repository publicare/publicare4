<?php

declare(strict_types=1);

namespace Pbl\Conversao;

final class Resultado
{
    /**
     * @param list<Aviso> $avisos
     * @param list<string> $incluidos nomes de molde PBL4 referenciados por `incluir`
     * @param list<string> $camposIndexaveis campos de classe usados em filtros (`campo=`/ordem) que precisam ser indexáveis
     */
    public function __construct(
        public readonly string $saida,
        public readonly array $avisos,
        public readonly array $incluidos,
        public readonly array $camposIndexaveis,
        public readonly bool $valido,
    ) {
    }

    public function contar(string $nivel): int
    {
        return count(array_filter($this->avisos, static fn (Aviso $a): bool => $a->nivel === $nivel));
    }
}
