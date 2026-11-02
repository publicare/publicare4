<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Caminhos;
use Pbl\Conteudo\Dominio;
use Pbl\Servicos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'dominio', description: 'Gerencia os domínios de um site: listar, adicionar, principal, remover')]
final class DominioComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('acao', InputArgument::REQUIRED, 'listar | adicionar | principal | remover');
        $this->addArgument('host', InputArgument::OPTIONAL, 'Domínio (para adicionar, principal e remover)');
        $this->addOption('site', null, InputOption::VALUE_REQUIRED, 'Número do site (padrão: o primeiro)');
        $this->addOption('modo', null, InputOption::VALUE_REQUIRED, 'servir (mesmo conteúdo, com canônico) ou redirecionar (301 para o principal)', Dominio::SERVIR);
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
            $host = (string) $input->getArgument('host');
            switch ($input->getArgument('acao')) {
                case 'listar':
                    foreach ($servicos->dominios->doSite($site->id) as $d) {
                        $output->writeln(str_pad($d->host, 40) . $d->modo);
                    }
                    break;
                case 'adicionar':
                    $servicos->dominios->adicionar($site->id, $host, (string) $input->getOption('modo'));
                    $output->writeln("Domínio {$host} adicionado ao site {$site->nome}.");
                    break;
                case 'principal':
                    $servicos->dominios->definirPrincipal($site->id, $host);
                    $servicos->enderecos->esquecer($site->id);
                    $servicos->publicador->hostMudou($site);
                    $output->writeln("{$host} agora é o domínio principal; o anterior passou a redirecionar para ele.");
                    break;
                case 'remover':
                    $servicos->dominios->remover($host);
                    $output->writeln("Domínio {$host} removido.");
                    break;
                default:
                    throw new \InvalidArgumentException('Ação desconhecida (use listar, adicionar, principal ou remover)');
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
