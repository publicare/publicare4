<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Caminhos;
use Pbl\Demo\AgendaDaDemo;
use Pbl\Demo\ConfigDaDemo;
use Pbl\Demo\ReiniciadorDaDemo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reinicia a demonstração (ADR 0023, adendo). Recusa-se fora de instalação de demo. Sem `--agora`, só reinicia se a hora marcada
 * já chegou (é o que o cron central faz); `--agora` reinicia já; `--simular` só mostra o que faria.
 */
#[AsCommand(name: 'demo-resetar', description: 'Reinicia a demonstração (só em instalação de demo): recria conteúdo, contas e arquivos de exemplo, com backup prévio')]
final class DemoResetarComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('agora', null, InputOption::VALUE_NONE, 'reinicia já, sem esperar a hora marcada');
        $this->addOption('simular', null, InputOption::VALUE_NONE, 'só mostra o que seria feito, sem alterar nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!ConfigDaDemo::ativa($this->caminhos)) {
            $output->writeln('<error>Esta instalação não é uma demo: nada foi feito. (Defina "demo": true em dados/config/demo.json ou PBL_DEMO=1 apenas num servidor de demonstração.)</error>');

            return Command::FAILURE;
        }
        $agenda = new AgendaDaDemo($this->caminhos);
        $simular = (bool) $input->getOption('simular');
        if (!$simular && !(bool) $input->getOption('agora') && !$agenda->vencido()) {
            $output->writeln('Ainda não é a hora: próximo reinício em ' . AgendaDaDemo::rotulo($agenda->proximo()) . '. Use --agora para reiniciar já.');

            return Command::SUCCESS;
        }
        try {
            foreach ((new ReiniciadorDaDemo($this->caminhos, $agenda))->reiniciar($simular) as $linha) {
                $output->writeln($linha);
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        if (!$simular) {
            $output->writeln('Demo reiniciada. Próximo reinício: ' . AgendaDaDemo::rotulo($agenda->proximo()) . '.');
        }

        return Command::SUCCESS;
    }
}
