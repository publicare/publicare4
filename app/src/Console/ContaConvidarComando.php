<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Caminhos;
use Pbl\Servicos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Convida uma pessoa: cria a conta pendente e envia o link (ou, com `--sem-envio`, imprime-o uma vez). Nunca imprime senha. */
#[AsCommand(name: 'conta-convidar', description: 'Convida alguém: cria a conta pendente e envia o link de uso único (72 h) para definir a senha')]
final class ContaConvidarComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('login', InputArgument::REQUIRED, 'Login da nova conta');
        $this->addArgument('email', InputArgument::REQUIRED, 'E-mail da pessoa');
        $this->addOption('nome', null, InputOption::VALUE_REQUIRED, 'Nome da pessoa');
        $this->addOption('admin', null, InputOption::VALUE_NONE, 'Administrador global');
        $this->addOption('sem-envio', null, InputOption::VALUE_NONE, 'Não envia e-mail: imprime o link (segredo) uma vez');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $semEnvio = (bool) $input->getOption('sem-envio');
        try {
            $s = Servicos::doBancoInstalado($this->caminhos, 'cli');
            $login = (string) $input->getArgument('login');
            $link = $s->convites()->convidar(
                $login, (string) ($input->getOption('nome') ?? $login), (string) $input->getArgument('email'),
                (bool) $input->getOption('admin'), 'cli', !$semEnvio,
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        if ($semEnvio) {
            $output->writeln('Convite criado. Link de uso único (vale 72 horas; é um segredo, entregue só à pessoa convidada):');
            $output->writeln($link);
        } else {
            $output->writeln('Convite criado e enviado.');
        }

        return Command::SUCCESS;
    }
}
