<?php

declare(strict_types=1);

namespace Pbl\Cron;

use Pbl\Servicos;

/**
 * Pseudo-cron (ADR 0023) para hospedagem sem cron: nos acessos ao painel, se o cron real está atrasado (mais de 5 minutos) e o
 * pseudo-cron está ligado, roda as tarefas vencidas **leves** com um orçamento curto. Nunca roda nas páginas públicas, nunca
 * usa a rede nem varre arquivos, e uma falha aqui jamais derruba a requisição do painel. Desligado por padrão; liga e desliga na
 * tela "Tarefas agendadas" ou pela variável de ambiente `PBL_PSEUDO_CRON` (`1`/`0`, que manda sobre a tela).
 */
final class PseudoCron
{
    public const ORCAMENTO = 3.0;

    /**
     * @param list<Tarefa>|null $tarefas só para os testes
     *
     * @return bool true se tentou rodar uma rodada
     */
    public static function talvez(Servicos $servicos, ?array $tarefas = null, ?EstadoDoCron $estado = null): bool
    {
        try {
            $estado ??= new EstadoDoCron($servicos->caminhos);
            if (!$estado->pseudoLigado() || !$estado->cronAtrasado()) {
                return false;
            }
            $r = (new Executor($servicos->caminhos, $servicos, $estado, $tarefas ?? Catalogo::padrao()))->rodar('pseudo', self::ORCAMENTO, null, false, true);

            return !$r['ocupado'];
        } catch (\Throwable) {
            return false; // o painel segue; a tela "Tarefas agendadas" mostra o que a tarefa registrou
        }
    }
}
