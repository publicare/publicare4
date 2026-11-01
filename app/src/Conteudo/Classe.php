<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

/** Definição de um tipo de conteúdo criado pelo admin (sem código). */
final class Classe
{
    /** @param list<array{nome: string, tipo: string, indexavel: bool}> $propriedades */
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly array $propriedades,
    ) {
    }
}
