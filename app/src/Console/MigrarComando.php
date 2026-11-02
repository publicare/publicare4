<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\ClassesPadrao;
use Pbl\Caminhos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrar', description: 'Aplica as migrações pendentes do banco (o atualizador já faz isso)')]
final class MigrarComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!is_file($this->caminhos->banco())) {
            $output->writeln('<error>PBL4 não está instalado.</error>');

            return Command::FAILURE;
        }
        $conexao = Conexao::sqlite($this->caminhos->banco());
        $aplicadas = (new Migrador($conexao))->migrar();
        $fixas = ClassesPadrao::garantirFixas(new ClasseRepositorio($conexao));
        $output->writeln($aplicadas === [] ? 'Banco em dia.' : 'Migrações aplicadas: ' . implode(', ', $aplicadas));
        $peles = (new \Pbl\Instalacao\PelesDistribuidas($this->caminhos))->sincronizar();
        if ($peles['gravados'] !== []) {
            $output->writeln('Peles atualizadas: ' . implode(', ', $peles['gravados']));
        }
        foreach ($peles['avisos'] as $aviso) {
            $output->writeln('<comment>' . $aviso . '</comment>');
        }
        if ($fixas !== []) {
            $output->writeln('Tipos fixos criados: ' . implode(', ', $fixas));
        }

        return Command::SUCCESS;
    }
}
