<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Caminhos;
use Pbl\Publicacao\FilaDeRepublicacao;
use Pbl\Servicos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Republica todas as páginas publicadas de todos os sites, em lotes pela mesma fila do painel e do cron (ADR 0023): mostra o
 * progresso, retoma uma republicação já começada e não corre junto com outra do mesmo site.
 */
#[AsCommand(name: 'publicar', description: 'Renderiza e grava no cache as páginas publicadas de todos os sites (com progresso)')]
final class PublicarComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $falhou = false;
        try {
            $servicos = Servicos::doBancoInstalado($this->caminhos);
            foreach ($servicos->sites->todos() as $site) {
                $inicio = $servicos->republicacao->iniciar($site, 'cli');
                $estado = $inicio['estado'];
                $barra = null;
                if ($output->isDecorated()) {
                    $barra = new ProgressBar($output, max(1, $estado['total']));
                    $barra->setFormat(" {$site->nome}: %current%/%max% [%bar%] %percent:3s%%");
                    $barra->start();
                    $barra->setProgress($estado['feitas']);
                }
                while (true) {
                    $r = $servicos->republicacao->processar($site, 50, 30.0);
                    if ($r['ocupado']) {
                        usleep(500000);
                        continue;
                    }
                    $estado = $r['estado'] ?? $estado;
                    if ($barra !== null) {
                        $barra->setMaxSteps(max(1, $estado['total']));
                        $barra->setProgress(min($estado['feitas'], max(1, $estado['total'])));
                    } elseif ($output->isVerbose()) {
                        $output->writeln("{$site->nome}: {$estado['feitas']} de {$estado['total']}");
                    }
                    if ($estado['status'] !== FilaDeRepublicacao::ANDANDO) {
                        break;
                    }
                }
                $barra?->clear();
                if ($estado['status'] === FilaDeRepublicacao::FALHOU) {
                    $falhou = true;
                    $output->writeln("<error>{$site->nome}: interrompido em {$estado['feitas']} de {$estado['total']} página(s): {$estado['erro']}</error>");
                    continue;
                }
                $output->writeln("{$site->nome}: {$estado['publicadas']} página(s) publicada(s)" . ($estado['falhas'] > 0 ? ", {$estado['falhas']} com falha" : '') . ' em ' . $servicos->republicacao->duracao($estado) . ' s');
                $falhou = $falhou || $estado['falhas'] > 0;
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>' . FilaDeRepublicacao::mensagemSegura($e, $this->caminhos->raiz) . '</error>');

            return Command::FAILURE;
        }

        return $falhou ? Command::FAILURE : Command::SUCCESS;
    }
}
