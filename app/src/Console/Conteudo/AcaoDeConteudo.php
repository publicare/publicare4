<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;

interface AcaoDeConteudo
{
    /** Nome do subcomando (`editar`, `restaurar-versao`...). */
    public function nome(): string;

    public function executar(Contexto $c): Resposta;
}
