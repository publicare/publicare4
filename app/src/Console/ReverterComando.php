<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Atualizacao\ErroDeAtualizacao;
use Pbl\Atualizacao\Reversor;
use Pbl\Caminhos;
use Pbl\Versao;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Volta atrás de uma atualização concluída (ADR 0006): devolve o código de `dados/atualizacao/anterior/` e o banco da cópia em
 * `dados/backups/`. Sem `--confirmar`, só mostra se dá para voltar, para qual versão e o que se perde (dados criados depois da
 * atualização), sem alterar nada; o botão do painel (`/_pbl/atualizacao`) executa este mesmo comando, já com `--confirmar`, depois
 * de reautenticar e confirmar na tela.
 */
#[AsCommand(name: 'reverter', description: 'Desfaz a última atualização concluída, devolvendo o código e o banco de antes dela')]
final class ReverterComando extends Command
{
    /**
     * @param string $versaoAtual só para os testes; em produção vale a versão do código em execução (`Versao::ATUAL`)
     * @param (\Closure(string, string): void)|null $conferir só para os testes (padrão: processo PHP à parte)
     */
    public function __construct(private readonly Caminhos $caminhos, private readonly string $versaoAtual = Versao::ATUAL, private readonly ?\Closure $conferir = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('confirmar', null, InputOption::VALUE_NONE, 'Confirma a volta atrás: o que foi criado depois da atualização se perde');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $reversor = new Reversor($this->caminhos, $this->versaoAtual, $this->conferir);
        $a = $reversor->avaliar();
        if (!$a->possivel) {
            $output->writeln('<error>' . $a->motivo . '</error>');

            return Command::FAILURE;
        }
        $quando = gmdate('d/m/Y H:i', $a->em) . ' UTC';
        $output->writeln("Dá para voltar da {$a->para} para a {$a->de} (atualizada em {$quando}).");
        $output->writeln($a->restauraBanco
            ? "O banco também volta para a cópia de antes da atualização: o que foi criado ou alterado depois de {$quando} se perde."
            : 'Não havia banco para copiar na atualização: só o código volta.');
        if (!$input->getOption('confirmar')) {
            $output->writeln('Nada foi alterado. Para voltar atrás de verdade: bin/pbl reverter --confirmar');

            return Command::SUCCESS;
        }
        try {
            $r = $reversor->reverter('cli');
        } catch (ErroDeAtualizacao $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln("<info>Revertido de {$r->de} para {$r->para}.</info>" . ($r->copiaDoBancoAtual !== '' ? " Cópia do banco de antes de voltar: {$r->copiaDoBancoAtual}" : ''));

        return Command::SUCCESS;
    }
}
