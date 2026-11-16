<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Caminhos;
use Pbl\Servicos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** LGPD: exporta em JSON os dados que o sistema guarda de uma conta da equipe (nunca o hash da senha). Audita `conta.exportar`. */
#[AsCommand(name: 'conta-exportar', description: 'Exporta em JSON os dados guardados de uma conta (LGPD), sem a senha')]
final class ContaExportarComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('login', InputArgument::REQUIRED, 'Login da conta');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $s = Servicos::doBancoInstalado($this->caminhos);
            $login = (string) $input->getArgument('login');
            $usuario = $s->usuarios->porLogin($login) ?? throw new \InvalidArgumentException("Usuário não encontrado: {$login}");
            $dados = $s->dadosDeConta()->exportar($usuario);
            $s->auditoria->registrar('conta.exportar', 'cli', $usuario->id);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln(json_encode($dados, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
