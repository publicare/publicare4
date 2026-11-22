<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;

/** Entrada fixa, para testes. */
final class EntradaEmTexto implements Entrada
{
    public function __construct(private readonly string $texto = '')
    {
    }

    public function ler(int $limiteEmBytes): string
    {
        if (strlen($this->texto) > $limiteEmBytes) {
            throw new EntradaGrandeDemais('A entrada passa de ' . intdiv($limiteEmBytes, 1048576) . ' MiB.');
        }

        return $this->texto;
    }
}
