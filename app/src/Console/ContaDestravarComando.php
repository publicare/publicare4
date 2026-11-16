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
 * Libera o login travado por tentativas erradas (S-05), sem mexer na senha. Com `--ip`, libera também aquela origem. Audita
 * `conta.destravar` (ator `cli`); a auditoria não leva senha, nem o IP em claro (só o hash).
 */
#[AsCommand(name: 'conta-destravar', description: 'Libera o login de uma conta travada por tentativas erradas (opcional: --ip para liberar uma origem)')]
final class ContaDestravarComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('login', InputArgument::REQUIRED, 'Login da conta');
        $this->addOption('ip', null, InputOption::VALUE_REQUIRED, 'Libera também esta origem (endereço IP)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $s = Servicos::doBancoInstalado($this->caminhos);
            $login = (string) $input->getArgument('login');
            $usuario = $s->usuarios->porLogin($login) ?? throw new \InvalidArgumentException("Usuário não encontrado: {$login}");
            $limite = $s->limiteDeTentativas();
            $ip = $input->getOption('ip');
            if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new \InvalidArgumentException('Endereço IP inválido');
            }
            $limite->liberarLogin($usuario->login);
            if ($ip !== null) {
                $limite->liberarIp((string) $ip);
            }
            $s->auditoria->registrar('conta.destravar', 'cli', $usuario->id, null, [], $ip === null ? null : $limite->hashDoIp((string) $ip));
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln("Login de {$usuario->login} destravado" . ($ip === null ? '.' : " e a origem {$ip} liberada."));

        return Command::SUCCESS;
    }
}
