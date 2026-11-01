<?php

declare(strict_types=1);

namespace Pbl\Conversao;

/** Algo que o conversor fez ou não conseguiu fazer. `revisar` e `erro` pedem atenção humana. */
final class Aviso
{
    public const INFO = 'info';
    public const REVISAR = 'revisar';
    public const ERRO = 'erro';

    public function __construct(
        public readonly string $nivel,
        public readonly int $linha,
        public readonly string $mensagem,
    ) {
    }
}
