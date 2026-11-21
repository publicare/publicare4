<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

use Pbl\Caminhos;
use Pbl\Cron\EstadoDoCron;

/**
 * O que a página "Atualização" e o endpoint `progresso.json` mostram: junta o pedido agendado, o andamento gravado por quem
 * atualiza (terminal, cron ou processo do painel) e o batimento do cron. Só devolve uma lista fixa de campos simples: nunca
 * caminhos do servidor, trechos de log, o nonce nem a senha de ninguém.
 *
 * Estados: `ocioso`, `agendada` (pedido esperando o cron), `em_andamento`, `concluido` e `falhou`.
 *
 * @phpstan-type Situacao array{estado: string, tipo: string, etapa: string, etapa_nome: string, percentual: int, mensagem: string, inicio: int|null, fim: int|null, decorrido_s: int, agendada_em: int|null, valida_ate: int|null, agendada_ha_s: int|null, falha: string, cron_ativo: bool, cron_sem_iniciar: bool}
 */
final class SituacaoDaAtualizacao
{
    /** Passado este tempo sem o cron pegar o pedido, a página avisa que o cron pode não estar configurado. */
    public const CRON_SEM_INICIAR = 300;
    /** Andamento sem nenhuma atualização por tanto tempo: o processo provavelmente morreu. */
    public const SEM_SINAL = 3600;

    /** @var \Closure(): int */
    private readonly \Closure $relogio;
    private readonly PedidoDeAtualizacao $pedidos;
    private readonly EstadoDoCron $cron;

    /** @param (\Closure(): int)|null $relogio só para os testes */
    public function __construct(private readonly Caminhos $caminhos, ?\Closure $relogio = null, ?EstadoDoCron $cron = null)
    {
        $this->relogio = $relogio ?? static fn (): int => time();
        $this->pedidos = new PedidoDeAtualizacao($caminhos, $this->relogio);
        $this->cron = $cron ?? new EstadoDoCron($caminhos, $this->relogio);
    }

    /** @return Situacao */
    public function ler(): array
    {
        $agora = ($this->relogio)();
        $pedido = $this->pedidos->ler();
        $progresso = ProgressoEmArquivo::ler($this->caminhos);
        $raiz = $this->caminhos->raiz;
        $base = [
            'estado' => 'ocioso', 'tipo' => 'atualizar', 'etapa' => 'ocioso', 'etapa_nome' => 'Nada em andamento', 'percentual' => 0,
            'mensagem' => 'Nenhuma atualização em andamento.', 'inicio' => null, 'fim' => null, 'decorrido_s' => 0, 'agendada_em' => null,
            'valida_ate' => null, 'agendada_ha_s' => null, 'falha' => '', 'cron_ativo' => !$this->cron->cronAtrasado(), 'cron_sem_iniciar' => false,
        ];
        if ($pedido !== null) {
            $base['tipo'] = $pedido->tipo;
            $base['agendada_em'] = $pedido->em;
            $base['valida_ate'] = $pedido->validoAte;
            $base['agendada_ha_s'] = max(0, $agora - $pedido->em);
            if (!$pedido->emAndamento() && !$pedido->vencido($agora)) {
                $base['cron_sem_iniciar'] = $base['agendada_ha_s'] >= self::CRON_SEM_INICIAR;

                return ['estado' => 'agendada', 'etapa' => 'agendada', 'etapa_nome' => 'Agendada', 'mensagem' => 'Agendada: aguardando o próximo ciclo do cron (até 1 minuto)'] + $base;
            }
            if ($pedido->emAndamento()) {
                if ($progresso !== null && $progresso->inicio >= $pedido->iniciadaEm) {
                    return $this->doProgresso($progresso, $agora, $raiz, $base);
                }

                return ['estado' => 'em_andamento', 'etapa' => Etapa::Preparando->value, 'etapa_nome' => Etapa::Preparando->nome(), 'percentual' => Etapa::Preparando->percentual(),
                    'mensagem' => Etapa::Preparando->mensagem(), 'inicio' => $pedido->iniciadaEm, 'decorrido_s' => max(0, $agora - $pedido->iniciadaEm)] + $base;
            }

            return ['estado' => 'falhou', 'etapa' => Etapa::Falhou->value, 'etapa_nome' => Etapa::Falhou->nome(), 'mensagem' => 'O pedido venceu sem ser executado',
                'falha' => 'O pedido agendado venceu sem que o cron o executasse. Confira se o cron está configurado (uma tarefa por minuto) e peça de novo.'] + $base;
        }

        return $progresso === null ? $base : $this->doProgresso($progresso, $agora, $raiz, $base);
    }

    /**
     * @param Situacao $base
     * @return Situacao
     */
    private function doProgresso(Progresso $p, int $agora, string $raiz, array $base): array
    {
        $r = ['tipo' => $p->tipo, 'etapa' => $p->etapa->value, 'etapa_nome' => $p->etapa->nome(), 'percentual' => $p->percentual,
            'mensagem' => SaneadorDeMensagem::sanear($p->mensagem, $raiz), 'inicio' => $p->inicio, 'fim' => $p->fim,
            'decorrido_s' => max(0, ($p->fim ?? $agora) - $p->inicio), 'falha' => SaneadorDeMensagem::sanear($p->falha, $raiz)];
        if (!$p->terminou() && $agora - $p->atualizado > self::SEM_SINAL) {
            return ['estado' => 'falhou', 'etapa' => Etapa::Falhou->value, 'etapa_nome' => Etapa::Falhou->nome(), 'mensagem' => 'Sem sinal de andamento',
                'falha' => 'Sem sinal de andamento há mais de uma hora: o processo pode ter sido interrompido. Confira o site e tente de novo, se for o caso.'] + $r + $base;
        }
        $estado = !$p->terminou() ? 'em_andamento' : ($p->etapa === Etapa::Concluido ? 'concluido' : 'falhou');

        return ['estado' => $estado] + $r + $base;
    }
}
