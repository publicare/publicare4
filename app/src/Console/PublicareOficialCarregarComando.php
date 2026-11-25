<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Caminhos;
use Pbl\Oficial\CargaOficial;
use Pbl\Servicos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Carrega (ou atualiza) o conteúdo OFICIAL do site publicare.org: só o conteúdo público do Publicare 4, sem contas nem textos de
 * demonstração. Idempotente; não toca no que o editor criou fora da carga. É só a casca de {@see CargaOficial}.
 */
#[AsCommand(name: 'publicare-oficial-carregar', description: 'Carrega ou atualiza o conteúdo oficial do site publicare.org (sem contas nem conteúdo de demonstração)')]
final class PublicareOficialCarregarComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('site', null, InputOption::VALUE_REQUIRED, 'Número do site (padrão: o primeiro)');
        $this->addOption('email-contato', null, InputOption::VALUE_REQUIRED, 'E-mail de contato (opcional). Sem ele, o contato é só pelo GitHub e a carga não cria formulário');
        $this->addOption('host', null, InputOption::VALUE_REQUIRED, 'Domínio principal do site (ex.: publicare.org); sem ele, o site já precisa ter um domínio principal real');
        $this->addOption('sem-publicar', null, InputOption::VALUE_NONE, 'Não publica o site no final');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $email = $input->getOption('email-contato');
            $email = is_string($email) && trim($email) !== '' ? $email : null;
            if ($email === null) {
                $output->writeln('Sem --email-contato: o contato é só pelo GitHub (sem formulário).');
            }
            $servicos = Servicos::doBancoInstalado($this->caminhos);
            $opcao = $input->getOption('site');
            $site = is_numeric($opcao) ? $servicos->sites->porId((int) $opcao) : ($servicos->sites->todos()[0] ?? null);
            if ($site === null) {
                throw new \InvalidArgumentException('Site não encontrado');
            }
            $host = $input->getOption('host');
            $r = (new CargaOficial($this->caminhos, $servicos))->carregar($site->id, $email, is_string($host) ? $host : null, !$input->getOption('sem-publicar'));
            $output->writeln("Carga oficial: {$r['criados']} criado(s), {$r['atualizados']} atualizado(s), {$r['inalterados']} inalterado(s), {$r['removidos']} texto(s) inicial(is) removido(s).");
            foreach ($r['avisos'] as $aviso) {
                $output->writeln('<comment>' . $aviso . '</comment>');
            }
            if (!$input->getOption('sem-publicar')) {
                $output->writeln("{$site->nome}: {$r['publicadas']} página(s) publicada(s)");
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
