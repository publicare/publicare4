<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Caminhos;
use Pbl\Demo\ConfigDaDemo;
use Pbl\Demo\ReiniciadorDaDemo;
use Pbl\Demo\SenhaDaDemo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Mostra ao operador a senha atual do administrador da demo (S-03). Só na linha de comando e só em instalação de demo. */
#[AsCommand(name: 'demo-senha', description: 'Mostra a senha atual do administrador da demo (só em instalação de demo, só na linha de comando)', aliases: ['demo:senha'])]
final class DemoSenhaComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (PHP_SAPI !== 'cli') {
            return Command::FAILURE;
        }
        if (!ConfigDaDemo::ativa($this->caminhos)) {
            $output->writeln('<error>Esta instalação não é uma demo: nada a mostrar.</error>');

            return Command::FAILURE;
        }
        $senha = SenhaDaDemo::ler($this->caminhos);
        if ($senha === null) {
            $output->writeln('<error>Ainda não há senha registrada: rode "bin/pbl demo-resetar --agora" para gerar uma.</error>');

            return Command::FAILURE;
        }
        $output->writeln('Administrador da demo: ' . ReiniciadorDaDemo::LOGIN_ADMIN);
        $output->writeln('Senha: ' . $senha);

        return Command::SUCCESS;
    }
}
