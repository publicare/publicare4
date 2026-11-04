<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Caminhos;
use Pbl\Cron\Catalogo;
use Pbl\Cron\EstadoDoCron;
use Pbl\Cron\Executor;
use Pbl\Servicos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * O cron central (ADR 0023): UMA linha de cron por minuto roda todas as tarefas do catálogo que venceram e têm trabalho.
 * Silencioso quando não há nada a fazer (não enche o e-mail do cron); não sobrepõe outra rodada; respeita um orçamento de tempo.
 */
#[AsCommand(name: 'cron', description: 'Roda as tarefas agendadas que venceram (uma linha de cron por minuto); silencioso se nada a fazer')]
final class CronComando extends Command
{
    public const ORCAMENTO_PADRAO = 50;

    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tarefa', 't', InputOption::VALUE_REQUIRED, 'roda só esta tarefa (id), ignorando a frequência');
        $this->addOption('listar', 'l', InputOption::VALUE_NONE, 'lista as tarefas e o estado da última execução, sem rodar nada');
        $this->addOption('orcamento', null, InputOption::VALUE_REQUIRED, 'segundos máximos da rodada', (string) self::ORCAMENTO_PADRAO);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $servicos = Servicos::doBancoInstalado($this->caminhos);
            $estado = new EstadoDoCron($this->caminhos);
            $executor = new Executor($this->caminhos, $servicos, $estado, Catalogo::padrao($this->caminhos));
            if ((bool) $input->getOption('listar')) {
                foreach ($executor->tarefas() as $t) {
                    $e = $estado->tarefa($t->id());
                    $quando = $e['executada_em'] > 0 ? date('Y-m-d H:i:s', $e['executada_em']) : 'nunca';
                    $output->writeln(sprintf('%-24s a cada %-6s última: %s (%s) %s', $t->id(), self::intervalo($t->intervalo()), $quando, $e['resultado'] === '' ? '-' : $e['resultado'], $e['resumo']));
                }

                return Command::SUCCESS;
            }
            $id = $input->getOption('tarefa');
            if (is_string($id) && $id !== '' && \Pbl\Cron\Catalogo::porId($executor->tarefas(), $id) === null) {
                $output->writeln('<error>Tarefa desconhecida: ' . $id . '. Use --listar.</error>');

                return Command::INVALID;
            }
            $orcamento = max(1, (int) $input->getOption('orcamento'));
            $r = $executor->rodar(is_string($id) && $id !== '' ? 'manual' : 'cron', (float) $orcamento, is_string($id) && $id !== '' ? [$id] : null, is_string($id) && $id !== '');
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        if ($r['ocupado']) {
            return Command::SUCCESS; // outra rodada em andamento: a do próximo minuto pega o que sobrar
        }
        $falhou = false;
        foreach ($r['linhas'] as $l) {
            $falhou = $falhou || $l['resultado'] === EstadoDoCron::ERRO;
            $output->writeln(sprintf('%s: %s%s (%d ms)', $l['nome'], $l['resultado'] === EstadoDoCron::ERRO ? 'ERRO - ' : '', $l['resumo'], $l['duracao_ms']));
        }

        return $falhou ? Command::FAILURE : Command::SUCCESS;
    }

    private static function intervalo(int $s): string
    {
        return $s >= 86400 ? ($s / 86400) . ' d' : ($s >= 3600 ? ($s / 3600) . ' h' : ($s / 60) . ' min');
    }
}
