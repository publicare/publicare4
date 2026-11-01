<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Caminhos;
use Pbl\Servicos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'publicar', description: 'Renderiza e grava no cache as páginas publicadas de todos os sites')]
final class PublicarComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $servicos = Servicos::doBancoInstalado($this->caminhos);
            foreach ($servicos->sites->todos() as $site) {
                $n = $servicos->publicador->publicarSite($site);
                $output->writeln("{$site->nome}: {$n} página(s) publicada(s)");
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
