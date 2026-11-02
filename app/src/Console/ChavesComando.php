<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Atualizacao\Assinatura;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'gerar-chaves', description: 'Dono: gera um par de chaves de assinatura, OFFLINE (a privada vai para um arquivo seu, nunca para a tela)', aliases: ['chaves:gerar'])]
final class ChavesComando extends Command
{
    protected function configure(): void
    {
        $this->addOption('saida', null, InputOption::VALUE_REQUIRED, 'Pasta onde gravar NOME.privada (0600) e NOME.publica; fora do repositório, de preferência em volume cifrado');
        $this->addOption('nome', null, InputOption::VALUE_REQUIRED, 'Nome do par (ex.: uso, recuperacao, teste)', 'uso');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $saida = (string) $input->getOption('saida');
        $nome = (string) $input->getOption('nome');
        if ($saida === '' || preg_match('/^[a-z0-9_-]{1,40}$/D', $nome) !== 1) {
            $output->writeln('<error>Informe --saida=PASTA (fora do repositório) e, se quiser, --nome=uso|recuperacao|teste.</error>');

            return Command::FAILURE;
        }
        if (!is_dir($saida) && !@mkdir($saida, 0700, true) && !is_dir($saida)) {
            $output->writeln("<error>Não foi possível criar {$saida}.</error>");

            return Command::FAILURE;
        }
        $privada = rtrim($saida, '/') . "/{$nome}.privada";
        $publica = rtrim($saida, '/') . "/{$nome}.publica";
        if (file_exists($privada) || file_exists($publica)) {
            $output->writeln("<error>{$nome}.privada ou {$nome}.publica já existe em {$saida}: não sobrescrevo chave.</error>");

            return Command::FAILURE;
        }
        $par = Assinatura::gerarPar();
        $antes = umask(0177);
        $ok = file_put_contents($privada, $par['privada'] . "\n") !== false && file_put_contents($publica, $par['publica'] . "\n") !== false;
        umask($antes);
        if (!$ok) {
            $output->writeln('<error>Não foi possível gravar as chaves.</error>');

            return Command::FAILURE;
        }
        chmod($privada, 0600);
        $output->writeln("Privada: {$privada} (guarde com senha e faça 2 cópias; nunca no servidor, no CI ou em nuvem)");
        $output->writeln("Pública: {$par['publica']}");
        $output->writeln('A pública vai em app/src/Atualizacao/Chaves.php (USO, RECUPERACAO ou TESTE).');

        return Command::SUCCESS;
    }
}
