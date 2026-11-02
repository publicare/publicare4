<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

/** Resultado de olhar se dá para voltar atrás (nada é alterado): possível ou não, por quê e o que seria perdido. */
final class Avaliacao
{
    public function __construct(
        public readonly bool $possivel,
        public readonly string $motivo,
        /** versão para a qual se voltaria */
        public readonly string $de = '',
        /** versão instalada hoje (a que seria desfeita) */
        public readonly string $para = '',
        /** quando a atualização terminou (UNIX): o que foi criado no site depois disto se perde */
        public readonly int $em = 0,
        public readonly bool $restauraBanco = false,
    ) {
    }
}
