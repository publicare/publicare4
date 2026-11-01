<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Atualizacao\Empacotador;
use Pbl\Atualizacao\ErroDeAtualizacao;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'empacotar', description: 'Mantenedor: monta o pacote .zip, o latest.json e a assinatura de uma versão')]
final class EmpacotarComando extends Command
{
    protected function configure(): void
    {
        $this->addArgument('origem', InputArgument::REQUIRED, 'Pasta do projeto já preparada (vendor/ sem dependências de desenvolvimento)');
        $this->addArgument('saida', InputArgument::REQUIRED, 'Pasta onde gravar o pacote, o manifesto e a assinatura');
        $this->addOption('chave-privada', null, InputOption::VALUE_REQUIRED, 'Arquivo com a chave privada (hex). Nunca na linha de comando.');
        $this->addOption('canal', null, InputOption::VALUE_REQUIRED, 'estavel ou beta', 'estavel');
        $this->addOption('a-partir-de', null, InputOption::VALUE_REQUIRED, 'Versão mínima que pode atualizar direto', '0.0.0');
        $this->addOption('seguranca', null, InputOption::VALUE_NONE, 'Marca a versão como correção de segurança');
        $this->addOption('resumo', null, InputOption::VALUE_REQUIRED, 'Resumo de uma linha', '');
        $this->addOption('notas', null, InputOption::VALUE_REQUIRED, 'Endereço https das notas da versão', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $arquivo = (string) $input->getOption('chave-privada');
        if ($arquivo === '' || !is_file($arquivo)) {
            $output->writeln('<error>Informe --chave-privada=arquivo (a chave nunca vai na linha de comando).</error>');

            return Command::FAILURE;
        }
        try {
            $r = (new Empacotador())->empacotar(
                (string) $input->getArgument('origem'),
                (string) $input->getArgument('saida'),
                trim((string) file_get_contents($arquivo)),
                (string) $input->getOption('canal'),
                [
                    'atualizar_a_partir_de' => (string) $input->getOption('a-partir-de'),
                    'seguranca' => (bool) $input->getOption('seguranca'),
                    'resumo' => (string) $input->getOption('resumo'),
                    'notas' => (string) $input->getOption('notas'),
                ],
            );
        } catch (ErroDeAtualizacao $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln("Versão {$r['versao']}: {$r['zip']}, {$r['manifesto']}, {$r['assinatura']}");

        return Command::SUCCESS;
    }
}
