<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Caminhos;
use Pbl\Servicos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'agendados', description: 'Aplica a publicação e a saída do ar por data (feito para rodar no cron)')]
final class AgendadosComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('simular', null, InputOption::VALUE_NONE, 'só mostra o que mudaria, sem publicar nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $simular = (bool) $input->getOption('simular');
        try {
            $servicos = Servicos::doBancoInstalado($this->caminhos);
            foreach ($servicos->sites->todos() as $site) {
                if ($simular) {
                    $p = $servicos->publicador->pendentes($site);
                    $output->writeln("{$site->nome}: entrariam " . count($p['entram']) . ', sairiam ' . count($p['saem']));
                    continue;
                }
                $r = $servicos->publicador->aplicarAgendados($site);
                if ($r['entraram'] > 0 || $r['sairam'] > 0) {
                    $output->writeln("{$site->nome}: {$r['entraram']} entrou(aram) no ar, {$r['sairam']} saiu(saíram) do ar; site republicado");
                }
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
