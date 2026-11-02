<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

final class Site
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly ?int $objetoRaizId,
        public readonly string $pele,
        /** Sugestão de grafia parecida na busca sem resultado (nível C, ADR 0010). Desligado por padrão. */
        public readonly bool $buscaTolerancia = false,
    ) {
    }
}
