<?php

declare(strict_types=1);

namespace Pbl\Cron;

/** O que uma tarefa conta de volta: uma linha curta (vai para o log e para a tela) e se parou no meio por falta de tempo. */
final class Resultado
{
    public function __construct(
        public readonly string $resumo,
        public readonly bool $parcial = false,
    ) {
    }
}
