<?php

declare(strict_types=1);

namespace Pbl\Cron\Tarefas;

use Pbl\Cron\Resultado;
use Pbl\Cron\Tarefa;
use Pbl\Publicacao\Publicador;
use Pbl\Servicos;

/**
 * Publicação e saída do ar por data (o antigo `bin/pbl agendados`, ADR 0012 §10). "Tem trabalho?": algum objeto já devia estar
 * no ar (ou já devia ter saído) e o cache ainda não reflete, ou o menu mudou pela passagem do tempo. Site pequeno é republicado
 * aqui mesmo; site grande (acima de `Publicador::LIMITE_SINCRONO`) só é marcado como pendente, e a tarefa
 * "republicacao" refaz em lotes, sem estourar o orçamento da rodada.
 */
final class AgendadosTarefa implements Tarefa
{
    public function id(): string
    {
        return 'agendados';
    }

    public function nome(): string
    {
        return 'Publicação e saída por data';
    }

    public function descricao(): string
    {
        return 'Coloca no ar o conteúdo cuja data de publicação chegou e tira do ar o que venceu.';
    }

    public function intervalo(): int
    {
        return 60;
    }

    public function leve(): bool
    {
        return true;
    }

    public function temTrabalho(Servicos $servicos): bool
    {
        foreach ($servicos->sites->todos() as $site) {
            $servicos->menu->verificar($site, 'agendados'); // a passagem do tempo também muda o menu
            $p = $servicos->publicador->pendentes($site);
            if ($p['entram'] !== [] || $p['saem'] !== [] || $servicos->publicador->menuPendente($site)) {
                return true;
            }
        }

        return false;
    }

    public function executar(Servicos $servicos, float $prazo): Resultado
    {
        $entraram = 0;
        $sairam = 0;
        $adiados = 0;
        foreach ($servicos->sites->todos() as $site) {
            if ($servicos->objetos->contarDoSite($site->id) > Publicador::LIMITE_SINCRONO) {
                $p = $servicos->publicador->pendentes($site);
                if ($p['entram'] !== [] || $p['saem'] !== []) {
                    $servicos->menu->marcarPendente($site->id); // a tarefa "republicacao" refaz em lotes
                    $entraram += count($p['entram']);
                    $sairam += count($p['saem']);
                    $adiados++;
                }
                continue;
            }
            $r = $servicos->publicador->aplicarAgendados($site);
            $entraram += $r['entraram'];
            $sairam += $r['sairam'];
        }

        return new Resultado("{$entraram} entrou(aram) no ar, {$sairam} saiu(saíram) do ar" . ($adiados > 0 ? "; {$adiados} site(s) grande(s) republicado(s) em lotes" : ''));
    }
}
