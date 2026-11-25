<?php

declare(strict_types=1);

namespace Pbl\Manual;

/** Falha ao ler ou compilar o manual. A mensagem sempre diz o arquivo (e a linha, quando há) e nunca traz conteúdo de sistema. */
final class ErroDeManual extends \RuntimeException
{
    public static function em(string $arquivo, ?int $linha, string $mensagem): self
    {
        return new self($arquivo . ($linha !== null ? ':' . $linha : '') . ': ' . $mensagem);
    }
}
