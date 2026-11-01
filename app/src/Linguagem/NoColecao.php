<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/**
 * Laço sobre uma coleção de objetos: `filhos` ou `localizar`, na ordem do banco ou `aleatorio`.
 * Dentro, `#campo` é o item corrente.
 */
final class NoColecao implements No
{
    /**
     * @param 'filhos'|'localizar' $tipo
     * @param array<string, Expr> $params
     * @param list<No> $corpo
     */
    public function __construct(
        public readonly string $tipo,
        public readonly array $params,
        public readonly bool $aleatorio = false,
        public array $corpo = [],
    ) {
    }
}
