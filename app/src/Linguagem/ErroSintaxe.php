<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** Erro de molde PBL4, sempre com a linha. Nunca encerra o processo (ao contrário do PBL3). */
final class ErroSintaxe extends \RuntimeException
{
    public function __construct(string $mensagem, public readonly int $linha)
    {
        parent::__construct("{$mensagem} (linha {$linha})");
    }
}
