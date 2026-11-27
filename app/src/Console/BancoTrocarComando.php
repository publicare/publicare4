<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Banco\Copia\TrocaDeBanco;
use Pbl\Banco\ConfigBanco;
use Pbl\Banco\ConfigDeBancoInvalida;
use Pbl\Caminhos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Helper\QuestionHelper;

#[AsCommand(name: 'banco-trocar', description: 'Passa o site para outro banco (SQLite, MySQL/MariaDB ou PostgreSQL), com conteúdo, conferindo tudo antes de trocar')]
final class BancoTrocarComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('para', null, InputOption::VALUE_REQUIRED, 'Banco de destino: sqlite, mysql (também MariaDB) ou pgsql');
        $this->addOption('host', null, InputOption::VALUE_REQUIRED, 'Servidor do banco de destino');
        $this->addOption('porta', null, InputOption::VALUE_REQUIRED, 'Porta (padrão do banco)');
        $this->addOption('nome', null, InputOption::VALUE_REQUIRED, 'Nome do banco (sqlite: caminho absoluto do arquivo)');
        $this->addOption('usuario', null, InputOption::VALUE_REQUIRED, 'Usuário do banco de destino');
        $this->addOption('senha-env', null, InputOption::VALUE_REQUIRED, 'Variável de ambiente com a senha (sem ela, o comando pergunta)', 'PBL_DESTINO_SENHA');
        $this->addOption('schema', null, InputOption::VALUE_REQUIRED, 'PostgreSQL: schema');
        $this->addOption('tls', null, InputOption::VALUE_REQUIRED, 'desligado | preferir | exigir', 'desligado');
        $this->addOption('simular', null, InputOption::VALUE_NONE, 'Só confere e mostra o que seria copiado; não grava nada');
        $this->addOption('voltar', null, InputOption::VALUE_NONE, 'Desfaz a última troca: devolve a configuração de banco anterior');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $troca = new TrocaDeBanco($this->caminhos);
        try {
            if ($input->getOption('voltar')) {
                $troca->voltar();
                $output->writeln('<info>Configuração de banco anterior devolvida. O que foi gravado no banco novo depois da troca não volta para o antigo.</info>');

                return Command::SUCCESS;
            }
            $destino = $this->destino($input, $output);
            if ($input->getOption('simular')) {
                $r = $troca->simular($destino);
                foreach ($r['tabelas'] as $t => $n) {
                    $output->writeln(sprintf('  %-28s %8d linhas', $t, $n));
                }
                $output->writeln('Destino: ' . $r['destino'] . '. Nada foi gravado.');

                return Command::SUCCESS;
            }
            $copiadas = $troca->trocar($destino, static function (string $tabela, int $n) use ($output): void {
                if ($output->isVerbose()) {
                    $output->writeln("  {$tabela}: {$n}");
                }
            });
            $output->writeln('<info>Conteúdo copiado e conferido (' . array_sum($copiadas) . ' linhas em ' . count($copiadas) . ' tabelas). O site já usa o banco novo.</info>');
            $output->writeln('O banco antigo ficou como está, sem alteração: guarde-o por alguns dias e apague quando tiver certeza. Para voltar: bin/pbl banco-trocar --voltar.');

            return Command::SUCCESS;
        } catch (ConfigDeBancoInvalida | \RuntimeException | \InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }

    private function destino(InputInterface $input, OutputInterface $output): ConfigBanco
    {
        $driver = (string) $input->getOption('para');
        if ($driver === '') {
            throw new \InvalidArgumentException('Informe o banco de destino com --para=sqlite|mysql|pgsql.');
        }
        $nome = (string) $input->getOption('nome');
        if ($driver === 'sqlite') {
            return new ConfigBanco('sqlite', $nome);
        }
        $senha = getenv((string) $input->getOption('senha-env'));
        if ($senha === false || $senha === '') {
            $senha = (string) (new QuestionHelper())->ask($input, $output, (new Question('Senha do banco de destino: '))->setHidden(true)->setHiddenFallback(true));
        }
        $porta = (string) $input->getOption('porta');

        return new ConfigBanco(
            $driver,
            $nome,
            (string) $input->getOption('host'),
            $porta === '' ? null : (int) $porta,
            (string) $input->getOption('usuario'),
            $senha,
            ($s = (string) $input->getOption('schema')) === '' ? null : $s,
            (string) $input->getOption('tls'),
        );
    }
}
