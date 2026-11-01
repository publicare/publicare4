<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Conversao\Aviso;
use Pbl\Conversao\ConversorDeDiretorio;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'converter-moldes', description: 'Converte moldes PBL3 de uma pasta em moldes PBL4 (com relatório)')]
final class ConverterMoldesComando extends Command
{
    protected function configure(): void
    {
        $this->addArgument('origem', InputArgument::REQUIRED, 'Pasta com os moldes PBL3 (ex.: html/ do site)');
        $this->addArgument('destino', InputArgument::REQUIRED, 'Pasta de saída (ex.: dados/peles/minha-pele)');
        $this->addOption('seguro', null, InputOption::VALUE_NONE, 'Converte eco/= do PBL3 para eco (escapa HTML) em vez de ecocru');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $r = (new ConversorDeDiretorio((bool) $input->getOption('seguro')))->converter((string) $input->getArgument('origem'), (string) $input->getArgument('destino'));
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $total = count($r['arquivos']);
        $invalidos = count(array_filter($r['arquivos'], static fn (array $a): bool => !$a['resultado']->valido));
        $revisar = array_sum(array_map(static fn (array $a): int => $a['resultado']->contar(Aviso::REVISAR), $r['arquivos']));
        $output->writeln("{$total} molde(s) convertido(s); {$invalidos} inválido(s); {$revisar} ponto(s) a revisar; {$r['ignorados']} arquivo(s) sem tags ignorado(s).");
        if ($r['faltando'] !== []) {
            $output->writeln('<comment>Moldes incluídos não encontrados: ' . implode(', ', $r['faltando']) . '</comment>');
        }
        $output->writeln('Relatório: ' . rtrim((string) $input->getArgument('destino'), '/') . '/RELATORIO.md');

        return $invalidos > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
