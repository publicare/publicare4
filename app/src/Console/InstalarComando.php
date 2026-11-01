<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Caminhos;
use Pbl\Instalacao\Instalador;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

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
        $this->addOption('admin-login', null, InputOption::VALUE_REQUIRED, 'Cria um administrador com esse login (a senha é pedida de forma oculta)');
        $this->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'E-mail do administrador');
        $this->addOption('admin-nome', null, InputOption::VALUE_REQUIRED, 'Nome do administrador');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $admin = null;
            if ($input->getOption('admin-login') !== null) {
                $ajudante = new QuestionHelper();
                $pergunta = static fn (string $texto): Question => (new Question($texto))->setHidden(true)->setHiddenFallback(true);
                $senha = (string) $ajudante->ask($input, $output, $pergunta('Senha do administrador: '));
                if (!hash_equals($senha, (string) $ajudante->ask($input, $output, $pergunta('Repita a senha: ')))) {
                    throw new \InvalidArgumentException('As senhas não conferem');
                }
                $admin = [
                    'login' => (string) $input->getOption('admin-login'),
                    'email' => (string) $input->getOption('admin-email'),
                    'nome' => (string) ($input->getOption('admin-nome') ?? $input->getOption('admin-login')),
                    'senha' => $senha,
                ];
            }
            (new Instalador($this->caminhos))->instalar((string) $input->getOption('nome'), (string) $input->getOption('host'), $admin);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln('PBL4 instalado. Publique as páginas com: bin/pbl publicar' . ($admin === null ? '. Crie uma conta com: bin/pbl usuario criar' : '. Entre em /_pbl/entrar'));

        return Command::SUCCESS;
    }
}
