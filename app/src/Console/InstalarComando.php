<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Caminhos;
use Pbl\Instalacao\Instalador;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'instalar', description: 'Instala o PBL4 (SQLite, pele básica e site de exemplo)')]
final class InstalarComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('nome', null, InputOption::VALUE_REQUIRED, 'Nome do site', 'Meu site PBL4');
        $this->addOption('host', null, InputOption::VALUE_REQUIRED, 'Domínio principal', 'localhost');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            (new Instalador($this->caminhos))->instalar((string) $input->getOption('nome'), (string) $input->getOption('host'));
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln('PBL4 instalado. Publique as páginas com: bin/pbl publicar');

        return Command::SUCCESS;
    }
}
