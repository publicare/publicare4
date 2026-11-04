<?php

declare(strict_types=1);

namespace Pbl\Cron;

use Pbl\Caminhos;
use Pbl\Cron\Tarefas\AgendadosTarefa;
use Pbl\Cron\Tarefas\LimpezaTarefa;
use Pbl\Cron\Tarefas\ReinicioDaDemoTarefa;
use Pbl\Cron\Tarefas\RepublicacaoTarefa;
use Pbl\Cron\Tarefas\UploadsOrfaosTarefa;
use Pbl\Cron\Tarefas\VerificarAtualizacaoTarefa;
use Pbl\Demo\ConfigDaDemo;

/** O catálogo de tarefas do cron central (ADR 0023). Para tarefa nova, implemente {@see Tarefa} e acrescente aqui. */
final class Catalogo
{
    /**
     * `$caminhos` decide se a instalação é de demonstração: só então entra o reinício agendado da demo, sempre por último (apaga e
     * recria o banco, então nenhuma outra tarefa pode rodar depois dele na mesma rodada). Sem `$caminhos`, nunca entra.
     *
     * @return list<Tarefa> na ordem em que rodam: primeiro o que afeta o site no ar
     */
    public static function padrao(?Caminhos $caminhos = null): array
    {
        return [
            new AgendadosTarefa(),
            new RepublicacaoTarefa(),
            new LimpezaTarefa(),
            new VerificarAtualizacaoTarefa(),
            new UploadsOrfaosTarefa(),
            ...($caminhos !== null && ConfigDaDemo::ativa($caminhos) ? [new ReinicioDaDemoTarefa()] : []),
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
