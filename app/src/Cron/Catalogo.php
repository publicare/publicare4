<?php

declare(strict_types=1);

namespace Pbl\Cron;

use Pbl\Cron\Tarefas\AgendadosTarefa;
use Pbl\Cron\Tarefas\LimpezaTarefa;
use Pbl\Cron\Tarefas\RepublicacaoTarefa;
use Pbl\Cron\Tarefas\UploadsOrfaosTarefa;
use Pbl\Cron\Tarefas\VerificarAtualizacaoTarefa;

/** O catálogo de tarefas do cron central (ADR 0023). Para tarefa nova, implemente {@see Tarefa} e acrescente aqui. */
final class Catalogo
{
    /** @return list<Tarefa> na ordem em que rodam: primeiro o que afeta o site no ar */
    public static function padrao(): array
    {
        return [
            new AgendadosTarefa(),
            new RepublicacaoTarefa(),
            new LimpezaTarefa(),
            new VerificarAtualizacaoTarefa(),
            new UploadsOrfaosTarefa(),
        ];
    }

    /** @param list<Tarefa> $tarefas */
    public static function porId(array $tarefas, string $id): ?Tarefa
    {
        foreach ($tarefas as $t) {
            if ($t->id() === $id) {
                return $t;
            }
        }

        return null;
    }
}
