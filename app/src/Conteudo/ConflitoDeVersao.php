<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

/** Alguém salvou o objeto depois que você o abriu: a versão esperada não é mais a atual. */
final class ConflitoDeVersao extends \RuntimeException
{
    public function __construct(public readonly int $esperada, public readonly int $atual)
    {
        parent::__construct("O objeto foi alterado por outra pessoa (versão esperada {$esperada}, atual {$atual})");
    }
}
