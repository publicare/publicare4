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

/**
 * Caminho de recuperação (S-05): quem perdeu a senha, inclusive a do administrador, tem alguém com acesso ao servidor que redefine
 * pela linha de comando. A senha é pedida de forma oculta, ou gerada com `--gerar` e mostrada **uma única vez**. Encerra as sessões
 * da conta, destrava o login dela e audita `senha.trocar` (ator `cli`) sem a senha.
 */
#[AsCommand(name: 'conta-senha', description: 'Redefine a senha de uma conta (recuperação): pergunta oculta ou --gerar; também destrava o login')]
final class ContaSenhaComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('login', InputArgument::REQUIRED, 'Login da conta');
        $this->addOption('gerar', null, InputOption::VALUE_NONE, 'Gera uma senha aleatória e a mostra uma única vez');
        $this->addOption('senha-stdin', null, InputOption::VALUE_NONE, 'Lê a senha da entrada padrão (para scripts). A senha nunca vai na linha de comando.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $s = Servicos::doBancoInstalado($this->caminhos);
            $login = (string) $input->getArgument('login');
            $usuario = $s->usuarios->porLogin($login) ?? throw new \InvalidArgumentException("Usuário não encontrado: {$login}");
            $gerada = (bool) $input->getOption('gerar');
            $senha = $gerada ? SenhaNoTerminal::gerar() : SenhaNoTerminal::ler($input, $output);
            $s->usuarios->definirSenha($usuario->id, $senha, 'cli');
            $s->limiteDeTentativas()->liberarLogin($usuario->login);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln("Senha de {$usuario->login} redefinida; as sessões abertas foram encerradas e o login foi destravado.");
        if ($gerada) {
            $output->writeln('Senha gerada (aparece só agora; anote em lugar seguro): ' . $senha);
        }

        return Command::SUCCESS;
    }
}
