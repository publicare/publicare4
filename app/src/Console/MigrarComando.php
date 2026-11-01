<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use Pbl\Caminhos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrar', description: 'Aplica as migrações pendentes do banco (o atualizador já faz isso)')]
final class MigrarComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!is_file($this->caminhos->banco())) {
            $output->writeln('<error>PBL4 não está instalado.</error>');

            return Command::FAILURE;
        }
        $aplicadas = (new Migrador(Conexao::sqlite($this->caminhos->banco())))->migrar();
        $output->writeln($aplicadas === [] ? 'Banco em dia.' : 'Migrações aplicadas: ' . implode(', ', $aplicadas));

        return Command::SUCCESS;
    }
}
