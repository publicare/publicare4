<?php

declare(strict_types=1);

namespace Pbl\Conteudo\Servico;

use Pbl\Conteudo\Objeto;

/** O que uma operação de gravação devolve: o objeto como ficou, se algo mudou de fato e se o cache público acompanhou. */
final class ResultadoDeConteudo
{
    public function __construct(
        public readonly Objeto $objeto,
        public readonly bool $alterado,
        public readonly bool $publicacaoOk = true,
        public readonly ?Objeto $antes = null,
    ) {
    }
}
