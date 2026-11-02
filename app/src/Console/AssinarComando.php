<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Atualizacao\Assinatura;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dono: assina localmente um arquivo (o `latest.json` baixado do rascunho da release; também serve a um pacote `.zip`) e grava
 * `ARQUIVO.sig` ao lado. Só lê a chave privada de um caminho informado: não a imprime nem a grava em lugar nenhum (ADR 0019).
 */
#[AsCommand(name: 'assinar', description: 'Dono: assina um manifesto (latest.json) ou pacote com a chave privada de um arquivo; grava ARQUIVO.sig')]
final class AssinarComando extends Command
{
    protected function configure(): void
    {
        $this->addArgument('arquivo', InputArgument::REQUIRED, 'O latest.json (ou pacote) a assinar');
        $this->addOption('chave-privada', null, InputOption::VALUE_REQUIRED, 'Arquivo com a chave privada (hex). Nunca na linha de comando.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $alvo = (string) $input->getArgument('arquivo');
        $chave = (string) $input->getOption('chave-privada');
        if (!is_file($alvo)) {
            $output->writeln("<error>{$alvo} não existe.</error>");

            return Command::FAILURE;
        }
        if ($chave === '' || !is_file($chave)) {
            $output->writeln('<error>Informe --chave-privada=arquivo (a chave nunca vai na linha de comando).</error>');

            return Command::FAILURE;
        }
        if ((fileperms($chave) & 0077) !== 0) {
            $output->writeln('<comment>Aviso: o arquivo da chave é legível por outros usuários; ajuste com chmod 600.</comment>');
        }
        $privada = trim((string) file_get_contents($chave));
        try {
            $publica = Assinatura::publicaDe($privada);
            $assinatura = Assinatura::assinar((string) file_get_contents($alvo), $privada);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        if (file_put_contents($alvo . '.sig', $assinatura . "\n") === false) {
            $output->writeln("<error>Não foi possível gravar {$alvo}.sig.</error>");

            return Command::FAILURE;
        }
        $output->writeln("Assinado: {$alvo}.sig");
        $output->writeln("Chave pública correspondente: {$publica} (confira se é a que está em Chaves.php)");

        return Command::SUCCESS;
    }
}
