<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Caminhos;
use Pbl\Conteudo\Modelos;
use Pbl\Servicos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'modelos', description: 'Modelos opcionais (evento, faq, destaque, bloco): listar e ativar num site')]
final class ModelosComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('acao', InputArgument::OPTIONAL, 'listar (padrão) | ativar', 'listar');
        $this->addArgument('nome', InputArgument::OPTIONAL, 'Modelo a ativar (evento, faq, destaque, bloco ou todos)');
        $this->addOption('site', null, InputOption::VALUE_REQUIRED, 'Número do site (padrão: o primeiro)');
        $this->addOption('sem-exemplos', null, InputOption::VALUE_NONE, 'Não cria os conteúdos de exemplo');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $servicos = Servicos::doBancoInstalado($this->caminhos);
            $modelos = new Modelos($servicos->conexao(), $this->caminhos);
            $acao = $input->getArgument('acao');
            if ($acao === 'listar') {
                foreach (Modelos::catalogo() as $m) {
                    $output->writeln(str_pad($m['nome'], 10) . str_pad($modelos->ativo($m['nome']) ? 'ativo' : 'inativo', 9) . $m['titulo']);
                }

                return Command::SUCCESS;
            }
            if ($acao !== 'ativar') {
                throw new \InvalidArgumentException('Ação desconhecida (use listar ou ativar)');
            }
            $siteOpcao = $input->getOption('site');
            $site = is_numeric($siteOpcao) ? $servicos->sites->porId((int) $siteOpcao) : ($servicos->sites->todos()[0] ?? null);
            if ($site === null) {
                throw new \InvalidArgumentException('Site não encontrado');
            }
            $nome = $input->getArgument('nome');
            if (!is_string($nome) || $nome === '') {
                throw new \InvalidArgumentException('Informe o modelo: ' . implode(', ', Modelos::NOMES) . ' ou todos');
            }
            $alvo = $nome === 'todos' ? Modelos::NOMES : [$nome];
            foreach ($alvo as $m) {
                $r = $modelos->ativar($m, $site->id, !$input->getOption('sem-exemplos'));
                $output->writeln("{$m}: " . count($r['classes']) . ' tipo(s) criado(s), ' . $r['exemplos'] . ' exemplo(s), ' . count($r['moldes']) . ' molde(s).');
                foreach ($r['avisos'] as $aviso) {
                    $output->writeln('<comment>' . $aviso . '</comment>');
                }
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
