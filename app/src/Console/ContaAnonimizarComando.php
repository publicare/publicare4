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

/** LGPD: anonimiza uma conta da equipe, de forma irreversível. Sem `--confirmar`, só explica o que faria. Audita `conta.anonimizar` sem dado pessoal. */
#[AsCommand(name: 'conta-anonimizar', description: 'Anonimiza uma conta (LGPD): apaga login, e-mail e nome e desativa; mantém a trilha de auditoria')]
final class ContaAnonimizarComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('login', InputArgument::REQUIRED, 'Login da conta');
        $this->addOption('confirmar', null, InputOption::VALUE_NONE, 'Confirma a anonimização (não tem volta)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $s = Servicos::doBancoInstalado($this->caminhos);
            $login = (string) $input->getArgument('login');
            $usuario = $s->usuarios->porLogin($login) ?? throw new \InvalidArgumentException("Usuário não encontrado: {$login}");
            if (!$input->getOption('confirmar')) {
                $output->writeln("Anonimizar {$usuario->login} troca login, e-mail e nome por valores neutros, desativa a conta, encerra as sessões, remove os papéis e apaga o IP (hash) e o login da auditoria. As linhas de auditoria e a autoria do conteúdo ficam, sem identificar a pessoa. Não tem volta.");
                $output->writeln('Nada foi alterado. Para anonimizar de verdade: bin/pbl conta-anonimizar ' . $usuario->login . ' --confirmar');

                return Command::SUCCESS;
            }
            $s->dadosDeConta()->anonimizar($usuario, 'cli');
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln("Conta {$usuario->id} anonimizada.");

        return Command::SUCCESS;
    }
}
