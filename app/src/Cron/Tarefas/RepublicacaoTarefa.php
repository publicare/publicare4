<?php

declare(strict_types=1);

namespace Pbl\Cron\Tarefas;

use Pbl\Cron\Resultado;
use Pbl\Cron\Tarefa;
use Pbl\Servicos;

/**
 * Republicação pendente (ADR 0023): sites com uma republicação em andamento na fila ou com o menu/configuração marcado como
 * pendente (site grande, que não é refeito na requisição). Usa a MESMA fila do painel e de `bin/pbl publicar`, em lotes, e para
 * quando o prazo da rodada se aproxima; a próxima rodada continua do ponto em que parou.
 */
final class RepublicacaoTarefa implements Tarefa
{
    private const LOTE = 50;

    public function id(): string
    {
        return 'republicacao';
    }

    public function nome(): string
    {
        return 'Republicação pendente';
    }

    public function descricao(): string
    {
        return 'Refaz, em lotes, as páginas de sites grandes depois de mudar o menu, o domínio, a pele ou as configurações.';
    }

    public function intervalo(): int
    {
        return 60;
    }

    public function leve(): bool
    {
        return true; // em lotes e com prazo: no pseudo-cron o prazo é curto e a fila continua na vez seguinte
    }

    public function temTrabalho(Servicos $servicos): bool
    {
        foreach ($servicos->sites->todos() as $site) {
            if ($servicos->republicacao->ativa($site->id) || $servicos->publicador->menuPendente($site)) {
                return true;
            }
        }

        return false;
    }

    public function executar(Servicos $servicos, float $prazo): Resultado
    {
        $partes = [];
        $parcial = false;
        foreach ($servicos->sites->todos() as $site) {
            if (!$servicos->republicacao->ativa($site->id)) {
                if (!$servicos->publicador->menuPendente($site)) {
                    continue;
                }
                $servicos->republicacao->iniciar($site, 'cron');
            }
            $r = null;
            while (microtime(true) < $prazo) {
                $r = $servicos->republicacao->processar($site, self::LOTE, max(0.5, $prazo - microtime(true)));
                if ($r['terminou_agora']) {
                    $e = $r['estado'];
                    if ($e !== null) {
                        $servicos->auditoria->registrar('site.republicar', 'cron', null, null, ['site_id' => $site->id, 'objetos' => $e['feitas'], 'falhas' => $e['falhas']]);
                    }
                }
                if ($r['ocupado'] || $r['estado'] === null || $r['estado']['status'] !== 'andando') {
                    break;
                }
            }
            $e = $r['estado'] ?? $servicos->republicacao->estado($site->id);
            if ($e === null) {
                continue;
            }
            $partes[] = "{$site->nome}: {$e['feitas']} de {$e['total']}" . ($e['status'] === 'andando' ? ' (continua)' : '') . ($e['falhas'] > 0 ? ", {$e['falhas']} falha(s)" : '');
            $parcial = $parcial || $e['status'] === 'andando';
            if ($e['status'] === 'falhou') {
                throw new \RuntimeException('A republicação de "' . $site->nome . '" foi interrompida: ' . ($e['erro'] === '' ? 'erro repetido' : $e['erro']));
            }
        }

        return new Resultado($partes === [] ? 'Nada pendente.' : implode('; ', $partes), $parcial);
    }
}
