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

#[AsCommand(name: 'redirecionamento', description: 'Regras manuais de redirecionamento de um site: listar, adicionar, remover')]
final class RedirecionamentoComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('acao', InputArgument::REQUIRED, 'listar | adicionar | remover');
        $this->addArgument('origem', InputArgument::OPTIONAL, 'Caminho antigo (adicionar) ou número da regra (remover)');
        $this->addArgument('destino', InputArgument::OPTIONAL, 'Caminho do site (/novo) ou endereço https://');
        $this->addOption('site', null, InputOption::VALUE_REQUIRED, 'Número do site (padrão: o primeiro)');
        $this->addOption('codigo', null, InputOption::VALUE_REQUIRED, '301 (permanente, padrão) ou 302 (temporário)', '301');
        $this->addOption('prefixo', null, InputOption::VALUE_NONE, 'Vale também para tudo abaixo da origem (/antigo/x → <destino>/x)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $servicos = Servicos::doBancoInstalado($this->caminhos);
            $siteOpcao = $input->getOption('site');
            $site = is_numeric($siteOpcao) ? $servicos->sites->porId((int) $siteOpcao) : ($servicos->sites->todos()[0] ?? null);
            if ($site === null) {
                throw new \InvalidArgumentException('Site não encontrado');
            }
            switch ($input->getArgument('acao')) {
                case 'listar':
                    foreach ($servicos->redirecionamentos->doSite($site->id) as $r) {
                        $output->writeln(str_pad((string) $r['id'], 6) . $r['codigo'] . ' ' . str_pad($r['origem'] . ($r['prefixo'] ? '/*' : ''), 40) . ' → ' . $r['destino']);
                    }
                    break;
                case 'adicionar':
                    $id = $servicos->redirecionamentos->adicionar(
                        $site->id,
                        (string) $input->getArgument('origem'),
                        (string) $input->getArgument('destino'),
                        (int) $input->getOption('codigo'),
                        (bool) $input->getOption('prefixo'),
                    );
                    $output->writeln("Regra {$id} criada.");
                    break;
                case 'remover':
                    $servicos->redirecionamentos->remover($site->id, (int) $input->getArgument('origem'));
                    $output->writeln('Regra removida.');
                    break;
                default:
                    throw new \InvalidArgumentException('Ação desconhecida (use listar, adicionar ou remover)');
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
