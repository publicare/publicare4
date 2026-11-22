<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;

/** Fonte da entrada padrão, injetável para testes. */
interface Entrada
{
    /**
     * Lê tudo até o fim, em blocos, abortando sem carregar além do teto.
     *
     * @throws EntradaGrandeDemais
     */
    public function ler(int $limiteEmBytes): string;
}
