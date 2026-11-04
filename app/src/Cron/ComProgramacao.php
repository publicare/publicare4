<?php

declare(strict_types=1);

namespace Pbl\Cron;

use Pbl\Servicos;

/** Tarefa com hora marcada (e não "a cada N segundos"): a tela "Tarefas agendadas" mostra a frequência e a próxima execução dela. */
interface ComProgramacao
{
    /** Texto curto da frequência, por exemplo "a cada 7 dias, às 04:00 (Brasília)". */
    public function frequenciaTexto(Servicos $servicos): string;

    /** Instante (unix) da próxima execução. */
    public function proximaExecucao(Servicos $servicos): int;
}
