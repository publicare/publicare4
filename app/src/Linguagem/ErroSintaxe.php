<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** Erro de molde PBL4, sempre com a linha (e o arquivo quando conhecido). Nunca encerra o processo. */
final class ErroSintaxe extends \RuntimeException
{
    public function __construct(string $mensagem, public readonly int $linha, public readonly ?string $arquivo = null)
    {
        parent::__construct(($arquivo !== null ? "{$arquivo}: " : '') . "{$mensagem} (linha {$linha})");
    }

    public function noArquivo(string $arquivo): self
    {
        return new self(preg_replace('/ \(linha \d+\)$/', '', $this->getMessage()) ?? $this->getMessage(), $this->linha, $arquivo);
    }
}
