<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

final class Revertida
{
    public function __construct(
        /** versão que estava instalada e foi desfeita */
        public readonly string $de,
        /** versão que voltou a valer */
        public readonly string $para,
        /** cópia do banco como estava antes de voltar (nada criado depois da atualização se perde de vez) */
        public readonly string $copiaDoBancoAtual,
    ) {
    }
}
