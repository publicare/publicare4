<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

final class Objeto
{
    /**
     * @param array<string, string|int|float> $dados
     */
    public function __construct(
        public readonly int $id,
        public readonly int $siteId,
        public readonly int $classeId,
        public readonly ?int $paiId,
        public readonly string $caminho,
        public readonly string $titulo,
        public readonly string $urlAmigavel,
        public readonly string $status,
        public readonly array $dados,
        public readonly int $peso = 0,
        public readonly string $descricao = '',
        public readonly ?string $dataPublicacao = null,
        public readonly ?string $dataValidade = null,
    ) {
    }
}
