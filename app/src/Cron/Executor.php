<?php

declare(strict_types=1);

namespace Pbl\Cron;

use Pbl\Caminhos;
use Pbl\Publicacao\FilaDeRepublicacao;
use Pbl\Servicos;

/**
 * Roda uma rodada do cron central (ADR 0023): para cada tarefa do catálogo cuja frequência venceu, pergunta "tem trabalho?" e,
 * se tem, executa. Uma trava `flock` (`dados/cron/cron.lock`) impede duas rodadas ao mesmo tempo (o cron a cada minuto, o
 * pseudo-cron e o botão "Executar agora" podem coincidir); quem chega depois sai sem fazer nada. O orçamento de tempo vale
 * para a rodada inteira: tarefa que não começou até ele acabar fica para a próxima rodada, e as que estão rodando recebem o
 * prazo para parar sozinhas.
 *
 * Falha de uma tarefa não impede as outras: o erro (sem caminhos do servidor) vai para o estado e para a saída.
 *
 * @phpstan-type Linha array{id: string, nome: string, resultado: string, resumo: string, duracao_ms: int}
 */
final class Executor
{
    public const SEM_TRABALHO = 'sem_trabalho';

    /** @var \Closure(): float */
    private readonly \Closure $microtime;

    /**
     * @param list<Tarefa> $tarefas
     * @param (\Closure(): float)|null $microtime só para os testes
     */
    public function __construct(
        private readonly Caminhos $caminhos,
        private readonly Servicos $servicos,
        private readonly EstadoDoCron $estado,
        private readonly array $tarefas,
        ?\Closure $microtime = null,
    ) {
        $this->microtime = $microtime ?? static fn (): float => microtime(true);
    }

    /** @return list<Tarefa> */
    public function tarefas(): array
    {
        return $this->tarefas;
    }

    /**
     * @param string $origem `cron`, `pseudo` ou `manual`
     * @param float $orcamento segundos da rodada
     * @param list<string>|null $apenas só estas tarefas (null = todas)
     * @param bool $forcar ignora a frequência (o "tem trabalho?" continua valendo)
     * @param bool $soLeves só as tarefas `leve()` (pseudo-cron)
     *
     * @return array{ocupado: bool, linhas: list<Linha>}
     */
    public function rodar(string $origem, float $orcamento, ?array $apenas = null, bool $forcar = false, bool $soLeves = false): array
    {
        $trava = $this->travar();
        if ($trava === null) {
            return ['ocupado' => true, 'linhas' => []];
        }
        $linhas = [];
        try {
            $prazo = ($this->microtime)() + max(0.5, $orcamento);
            foreach ($this->tarefas as $tarefa) {
                if (($apenas !== null && !in_array($tarefa->id(), $apenas, true)) || ($soLeves && !$tarefa->leve())) {
                    continue;
                }
                $antes = $this->estado->tarefa($tarefa->id());
                $agora = $this->estado->agora();
                if (!$forcar && $antes['verificada_em'] > 0 && $agora - $antes['verificada_em'] < $tarefa->intervalo()) {
                    continue; // ainda não venceu
                }
                if (($this->microtime)() >= $prazo) {
                    break; // acabou o orçamento: o resto fica para a próxima rodada
                }
                $linhas[] = $this->rodarUma($tarefa, $agora, $prazo);
            }
            $this->estado->registrarCiclo($origem);
        } finally {
            flock($trava, LOCK_UN);
            fclose($trava);
        }

        return ['ocupado' => false, 'linhas' => array_values(array_filter($linhas, static fn (array $l): bool => $l['resultado'] !== self::SEM_TRABALHO))];
    }

    /** @return Linha */
    private function rodarUma(Tarefa $tarefa, int $agora, float $prazo): array
    {
        $inicio = ($this->microtime)();
        $linha = ['id' => $tarefa->id(), 'nome' => $tarefa->nome(), 'resultado' => self::SEM_TRABALHO, 'resumo' => '', 'duracao_ms' => 0];
        try {
            if (!$tarefa->temTrabalho($this->servicos)) {
                $this->estado->gravarTarefa($tarefa->id(), ['verificada_em' => $agora]);

                return $linha;
            }
            $r = $tarefa->executar($this->servicos, $prazo);
            $linha['resultado'] = $r->parcial ? EstadoDoCron::PARCIAL : EstadoDoCron::OK;
            $linha['resumo'] = mb_substr($r->resumo, 0, 300);
            $erro = '';
        } catch (\Throwable $e) {
            $linha['resultado'] = EstadoDoCron::ERRO;
            $erro = FilaDeRepublicacao::mensagemSegura($e, $this->caminhos->raiz);
            $linha['resumo'] = $erro;
        }
        $linha['duracao_ms'] = (int) round((($this->microtime)() - $inicio) * 1000);
        $this->estado->gravarTarefa($tarefa->id(), [
            'verificada_em' => $agora, 'executada_em' => $this->estado->agora(), 'resultado' => $linha['resultado'],
            'resumo' => $linha['resumo'], 'duracao_ms' => $linha['duracao_ms'], 'erro' => $erro,
        ]);

        return $linha;
    }

    /** @return resource|null null se outra rodada está em andamento */
    private function travar()
    {
        $pasta = $this->estado->pasta();
        if (!is_dir($pasta) && !@mkdir($pasta, 0775, true) && !is_dir($pasta)) {
            throw new \RuntimeException('Não foi possível criar a pasta dados/cron/ (falta permissão de escrita em dados/).');
        }
        $f = @fopen($pasta . '/cron.lock', 'c');
        if ($f === false) {
            throw new \RuntimeException('Não foi possível criar dados/cron/cron.lock (falta permissão de escrita em dados/).');
        }
        if (!flock($f, LOCK_EX | LOCK_NB)) {
            fclose($f);

            return null;
        }

        return $f;
    }
}
