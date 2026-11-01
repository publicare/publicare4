<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Versao;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'versao', description: 'Mostra a versão do PBL4')]
final class VersaoComando extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('PBL4 ' . Versao::ATUAL);

        return Command::SUCCESS;
    }
}
