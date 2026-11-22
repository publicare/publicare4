<?php

declare(strict_types=1);

namespace Pbl\Cron\Tarefas;

use Pbl\Atualizacao\ExecutorDePedido;
use Pbl\Cron\Resultado;
use Pbl\Cron\Tarefa;
use Pbl\Servicos;

/**
 * Executa a atualização (ou a volta atrás) que o administrador agendou pelo painel (`dados/atualizacao/pedido.json`), na hospedagem
 * que não deixa o painel iniciar processos. Faz o mesmo que `bin/pbl atualizar` / `bin/pbl reverter --confirmar`, no processo do
 * cron, e grava o andamento em `progresso.json` (ver {@see ExecutorDePedido}). "Tem trabalho?": existe o arquivo do pedido
 * (consulta barata, sem rede).
 *
 * Fica de fora do pseudo-cron (`leve()` falso): trocar o código não pode rodar dentro de um acesso ao painel. Enquanto roda, a
 * rodada do cron segura `dados/cron/cron.lock`; as rodadas seguintes saem como "ocupado". A atualização é indivisível: ignora o
 * `prazo` das outras tarefas. Por isso fica por último no catálogo (antes só da reinicialização da demo).
 */
final class AtualizacaoAgendadaTarefa implements Tarefa
{
    public function __construct(private readonly ?ExecutorDePedido $executor = null)
    {
    }

    public function id(): string
    {
        return 'atualizacao-agendada';
    }

    public function nome(): string
    {
        return 'Atualização agendada pelo painel';
    }

    public function descricao(): string
    {
        return 'Roda a atualização agendada em "Atualização" quando o servidor não deixa o painel iniciar processos.';
    }

    public function intervalo(): int
    {
        return 30; // confere a cada rodada do cron (uma por minuto); a margem evita pular uma rodada por causa de segundos
    }

    public function leve(): bool
    {
        return false;
    }

    public function temTrabalho(Servicos $servicos): bool
    {
        return $this->executor($servicos)->temTrabalho();
    }

    public function executar(Servicos $servicos, float $prazo): Resultado
    {
        return new Resultado($this->executor($servicos)->executar());
    }

    private function executor(Servicos $servicos): ExecutorDePedido
    {
        return $this->executor ?? ExecutorDePedido::padrao($servicos);
    }
}
