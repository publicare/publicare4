<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Backup\CatalogoDeBackups;
use Pbl\Backup\ConfigDeBackup;
use Pbl\Backup\CriadorDeBackup;
use Pbl\Backup\ErroDeBackup;
use Pbl\Backup\RestauradorDeBackup;
use Pbl\Backup\RetencaoDeBackups;
use Pbl\Caminhos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** `bin/pbl backup criar|listar|restaurar|limpar|enviar`: o mesmo código da tela e do cron (ADR 0029). */
#[AsCommand(name: 'backup', description: 'Cópias de segurança: criar, listar, restaurar, limpar, enviar')]
final class BackupComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos, private readonly ?\Closure $relogio = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('acao', InputArgument::REQUIRED, 'criar | listar | restaurar | limpar | enviar');
        $this->addArgument('arquivo', InputArgument::OPTIONAL, 'Nome da cópia (restaurar)');
        $this->addOption('confirmo', null, InputOption::VALUE_NONE, 'Confirma a restauração: o que foi criado depois da cópia se perde');
        $this->addOption('simular', null, InputOption::VALUE_NONE, 'limpar: só mostra o que seria apagado');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = ConfigDeBackup::carregar($this->caminhos);
        $catalogo = new CatalogoDeBackups($this->caminhos, $config);
        $agora = $this->relogio === null ? time() : ($this->relogio)();
        try {
            switch ($input->getArgument('acao')) {
                case 'criar':
                    $i = (new CriadorDeBackup($this->caminhos, $config, relogio: $this->relogio))->criar('manual');
                    $output->writeln("<info>Cópia criada: {$i->arquivo} (" . $i->tamanho . ' bytes).</info>');

                    return Command::SUCCESS;
                case 'listar':
                    foreach ($catalogo->listar() as $c) {
                        $ok = $c->integro === null ? 'n/d' : ($c->integro ? 'íntegra' : 'CORROMPIDA');
                        $output->writeln(sprintf('%s  %-15s %10d  v%s  %s%s', gmdate('Y-m-d H:i', $c->criadoEm), $c->tipo, $c->tamanho, $c->versao, $ok, $c->protegida ? '  [protegida]' : '') . "  {$c->arquivo}");
                    }

                    return Command::SUCCESS;
                case 'restaurar':
                    return $this->restaurar($input, $output, $config, $catalogo);
                case 'limpar':
                    $plano = (new RetencaoDeBackups($config->reterUltimas, $config->reterDias))->planejar($catalogo->listar(), $agora);
                    foreach ($plano as $c) {
                        $output->writeln(($input->getOption('simular') ? 'Apagaria: ' : 'Apagando: ') . $c->arquivo);
                    }
                    if (!$input->getOption('simular')) {
                        RetencaoDeBackups::aplicar($catalogo, $plano);
                    }
                    $output->writeln(count($plano) . ' cópia(s).');

                    return Command::SUCCESS;
                case 'enviar':
                    $output->writeln('<error>Envio para destino remoto ainda não está disponível nesta versão (SFTP/S3 previstos no ADR 0033). A cópia local não foi alterada.</error>');

                    return Command::FAILURE;
                default:
                    $output->writeln('<error>Ação desconhecida. Use: criar, listar, restaurar, limpar ou enviar.</error>');

                    return Command::INVALID;
            }
        } catch (ErroDeBackup $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }

    private function restaurar(InputInterface $input, OutputInterface $output, ConfigDeBackup $config, CatalogoDeBackups $catalogo): int
    {
        $nome = $input->getArgument('arquivo');
        if (!is_string($nome) || $nome === '') {
            $output->writeln('<error>Informe o nome da cópia: bin/pbl backup restaurar <arquivo> [--confirmo]</error>');

            return Command::INVALID;
        }
        $restaurador = new RestauradorDeBackup($this->caminhos, $catalogo, new CriadorDeBackup($this->caminhos, $config, relogio: $this->relogio));
        $c = $restaurador->conferir($nome);
        $quando = (string) ($c['manifesto']['criado_em'] ?? '?');
        $output->writeln("Cópia íntegra, de {$quando} (PBL4 " . (string) ($c['manifesto']['versao_pbl4'] ?? '?') . ').');
        $output->writeln("Restaurar apaga o que foi criado depois de {$quando}: conteúdo, uploads, contas e configurações. Antes, o estado atual é copiado (tipo pre-restauracao).");
        if (!$input->getOption('confirmo')) {
            $output->writeln('Nada foi alterado. Para restaurar de verdade, repita com --confirmo.');

            return Command::SUCCESS;
        }
        $r = $restaurador->restaurar($nome);
        $output->writeln("<info>Restaurado. Cópia do estado anterior: {$r['copia_anterior']}.</info>");

        return Command::SUCCESS;
    }
}
