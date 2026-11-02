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

#[AsCommand(name: 'empacotar', description: 'Mantenedor: monta o pacote .zip e o latest.json de uma versão (assina só se receber a chave; senão use `assinar` depois)')]
final class EmpacotarComando extends Command
{
    protected function configure(): void
    {
        $this->addArgument('origem', InputArgument::REQUIRED, 'Pasta do projeto já preparada (vendor/ sem dependências de desenvolvimento)');
        $this->addArgument('saida', InputArgument::REQUIRED, 'Pasta onde gravar o pacote, o manifesto e a assinatura');
        $this->addOption('chave-privada', null, InputOption::VALUE_REQUIRED, 'Arquivo com a chave privada (hex). Nunca na linha de comando. Só para o canal teste (CI); a de produção fica com o dono (bin/pbl assinar).');
        $this->addOption('sem-assinar', null, InputOption::VALUE_NONE, 'Não assina: o dono assina depois com `bin/pbl assinar` (é o que o CI faz nos canais de produção)');
        $this->addOption('nova-chave-de-uso', null, InputOption::VALUE_REQUIRED, 'Chave pública (hex) que passa a ser a de uso; o manifesto precisa ser assinado pela chave de RECUPERAÇÃO');
        $this->addOption('revogar', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Chave pública (hex) a revogar; o manifesto precisa ser assinado pela chave de RECUPERAÇÃO');
        $this->addOption('canal', null, InputOption::VALUE_REQUIRED, 'estavel, beta ou teste', 'estavel');
        $this->addOption('a-partir-de', null, InputOption::VALUE_REQUIRED, 'Versão mínima que pode atualizar direto', '0.0.0');
        $this->addOption('seguranca', null, InputOption::VALUE_NONE, 'Marca a versão como correção de segurança');
        $this->addOption('resumo', null, InputOption::VALUE_REQUIRED, 'Resumo de uma linha', '');
        $this->addOption('notas', null, InputOption::VALUE_REQUIRED, 'Endereço https das notas da versão', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $arquivo = (string) $input->getOption('chave-privada');
        $semAssinar = (bool) $input->getOption('sem-assinar');
        if (!$semAssinar && ($arquivo === '' || !is_file($arquivo))) {
            $output->writeln('<error>Informe --chave-privada=arquivo (a chave nunca vai na linha de comando) ou --sem-assinar.</error>');

            return Command::FAILURE;
        }
        if ($semAssinar && $arquivo !== '') {
            $output->writeln('<error>--sem-assinar e --chave-privada não combinam.</error>');

            return Command::FAILURE;
        }
        $extra = [];
        $nova = $input->getOption('nova-chave-de-uso');
        if (is_string($nova) && $nova !== '') {
            $extra['nova_chave_de_uso'] = $nova;
        }
        $revogar = $input->getOption('revogar');
        if (is_array($revogar) && $revogar !== []) {
            $extra['chaves_revogadas'] = array_values(array_map('strval', $revogar));
        }
        try {
            $r = (new Empacotador())->empacotar(
                (string) $input->getArgument('origem'),
                (string) $input->getArgument('saida'),
                $semAssinar ? null : trim((string) file_get_contents($arquivo)),
                (string) $input->getOption('canal'),
                [
                    'atualizar_a_partir_de' => (string) $input->getOption('a-partir-de'),
                    'seguranca' => (bool) $input->getOption('seguranca'),
                    'resumo' => (string) $input->getOption('resumo'),
                    'notas' => (string) $input->getOption('notas'),
                ] + $extra,
            );
        } catch (ErroDeAtualizacao $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln("Versão {$r['versao']}: {$r['zip']}, {$r['manifesto']}" . ($r['assinatura'] === null ? ' (SEM assinatura: assine com `bin/pbl assinar` antes de publicar)' : ", {$r['assinatura']}"));

        return Command::SUCCESS;
    }
}
