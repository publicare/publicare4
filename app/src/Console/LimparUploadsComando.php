<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Arquivos\ArmazenamentoDeArquivos;
use Pbl\Arquivos\LimpadorDeUploads;
use Pbl\Caminhos;
use Pbl\Servicos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Apaga arquivos órfãos (fluxo 7, ADR 0014): nenhum objeto, nem versão antiga, referencia mais o seu id numa propriedade
 * `arquivo`/`imagem`. Nunca roda na requisição (seria caro e arriscado numa corrida); pensado para o cron, de vez em quando.
 */
#[AsCommand(name: 'limpar-uploads', description: 'Apaga arquivos enviados que nenhum objeto (nem versão antiga) referencia mais')]
final class LimparUploadsComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('simular', null, InputOption::VALUE_NONE, 'só mostra o que seria apagado, sem apagar nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $simular = (bool) $input->getOption('simular');
        try {
            $servicos = Servicos::doBancoInstalado($this->caminhos);
            $limpador = new LimpadorDeUploads($servicos->conexao(), $servicos->classes, $servicos->arquivos, new ArmazenamentoDeArquivos($this->caminhos));
            $r = $limpador->executar($simular);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $mb = round($r['bytes'] / (1024 * 1024), 2);
        if ($simular) {
            $output->writeln("{$r['examinados']} arquivo(s) examinado(s); {$r['orfaos']} órfão(s) ({$mb} MB) seriam apagados.");
        } else {
            $output->writeln("{$r['examinados']} arquivo(s) examinado(s); {$r['apagados']} órfão(s) apagados ({$mb} MB liberados).");
        }
        foreach ($r['nomes'] as $nome) {
            $output->writeln('  - ' . $nome);
        }

        return Command::SUCCESS;
    }
}
