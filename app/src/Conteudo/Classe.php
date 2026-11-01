<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

/** Definição de um tipo de conteúdo criado pelo admin (sem código). */
final class Classe
{
    /**
     * Tipos: `texto`, `numero`, `html`, `data` (aaaa-mm-dd[ hh:mm:ss]), `booleano` e `ref` (aponta para outro objeto do
     * mesmo site; `classe` opcional restringe a classe do alvo).
     *
     * @param list<array{nome: string, tipo: string, indexavel: bool, classe?: string}> $propriedades
     */
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly array $propriedades,
    ) {
    }
}
