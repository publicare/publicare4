<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Atualizacao\Atualizador;
use Pbl\Atualizacao\BaixadorHttps;
use Pbl\Atualizacao\BaixadorLocal;
use Pbl\Atualizacao\Configuracao;
use Pbl\Atualizacao\ErroDeAtualizacao;
use Pbl\Caminhos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'atualizar', description: 'Verifica e aplica atualizações assinadas do PBL4')]
final class AtualizarComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('verificar', null, InputOption::VALUE_NONE, 'Só olha se há versão nova; não altera nada');
        $this->addOption('manifesto', null, InputOption::VALUE_REQUIRED, 'Arquivo latest.json local (atualização offline); o .sig precisa estar ao lado');
        $this->addOption('canal', null, InputOption::VALUE_REQUIRED, 'estavel ou beta (padrão: o da configuração)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = Configuracao::ler($this->caminhos);
        $local = $input->getOption('manifesto');
        $origem = is_string($local) && $local !== '' ? $local : $config->url;
        $canal = is_string($input->getOption('canal')) ? $input->getOption('canal') : $config->canal;
        if ($origem === '') {
            $output->writeln('<error>Nenhum endereço de atualização. Crie dados/config/atualizacao.json com {"url": "https://…/latest.json"} ou use --manifesto=arquivo.</error>');

            return Command::FAILURE;
        }
        if (!is_string($local) && !$config->verificar) {
            $output->writeln('A verificação de atualizações está desligada em dados/config/atualizacao.json.');

            return Command::SUCCESS;
        }
        $atualizador = new Atualizador($this->caminhos, is_string($local) && $local !== '' ? new BaixadorLocal() : new BaixadorHttps());
        try {
            $v = $atualizador->verificar($origem, (string) $canal);
            $output->writeln($v->motivo);
            if (!$v->disponivel || $v->manifesto === null) {
                return Command::SUCCESS;
            }
            $m = $v->manifesto;
            $output->writeln("  {$m->resumo}" . ($m->seguranca ? ' (inclui correção de segurança)' : ''));
            if ($input->getOption('verificar')) {
                $output->writeln('Para aplicar: bin/pbl atualizar');

                return Command::SUCCESS;
            }
            $r = $atualizador->aplicar($v, $origem);
            $output->writeln("<info>Atualizado de {$r->de} para {$r->para}.</info>" . ($r->bancoCopiadoPara !== '' ? " Cópia do banco: {$r->bancoCopiadoPara}" : ''));
            $output->writeln('Código anterior guardado em dados/atualizacao/anterior/ (pode apagar quando confirmar que está tudo certo).');

            return Command::SUCCESS;
        } catch (ErroDeAtualizacao $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }
}
