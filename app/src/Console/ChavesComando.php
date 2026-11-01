<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Atualizacao\Assinatura;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'chaves:gerar', description: 'Mantenedor: gera um par de chaves de assinatura (a privada fica só com você)')]
final class ChavesComando extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $par = Assinatura::gerarPar();
        $output->writeln('PÚBLICA (vai em app/src/Atualizacao/Chaves.php): ' . $par['publica']);
        $output->writeln('PRIVADA (guarde fora do repositório, nunca envie a ninguém): ' . $par['privada']);

        return Command::SUCCESS;
    }
}
