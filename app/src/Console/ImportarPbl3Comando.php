<?php

declare(strict_types=1);

namespace Pbl\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Pbl\Caminhos;
use Pbl\Importacao\Importador;
use Pbl\Importacao\MapaDeTabelas;
use Pbl\Importacao\Opcoes;
use Pbl\Importacao\OrigemPbl3;
use Pbl\Importacao\Relatorio;
use Pbl\Servicos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'importar-pbl3', description: 'Importa uma base do PBL3 (MySQL ou arquivo SQLite) para esta instalação; sem --gravar só simula')]
final class ImportarPbl3Comando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('origem-sqlite', null, InputOption::VALUE_REQUIRED, 'Arquivo SQLite com a base do PBL3 (só leitura)');
        $this->addOption('origem-mysql', null, InputOption::VALUE_REQUIRED, 'Base MySQL do PBL3 no formato servidor[:porta]/banco (só leitura)');
        $this->addOption('origem-usuario', null, InputOption::VALUE_REQUIRED, 'Usuário do MySQL de origem');
        $this->addOption('gravar', null, InputOption::VALUE_NONE, 'Grava de verdade. Sem esta opção nada é gravado: é uma simulação com relatório');
        $this->addOption('simular', null, InputOption::VALUE_NONE, 'Simula (é o comportamento padrão; a opção só deixa a intenção explícita)');
        $this->addOption('site', null, InputOption::VALUE_REQUIRED, 'Número do site de destino (obrigatório se houver mais de um)');
        $this->addOption('raiz', null, InputOption::VALUE_REQUIRED, 'Número (no PBL3) do objeto a importar com tudo o que há abaixo; sem ele, todos os objetos de topo');
        $this->addOption('pai-destino', null, InputOption::VALUE_REQUIRED, 'Número (aqui) do objeto sob o qual a raiz importada entra; sem ele, a raiz do site');
        $this->addOption('uploads', null, InputOption::VALUE_REQUIRED, 'Pasta de arquivos do PBL3 (o `uploadpath`); sem ela os arquivos não são importados');
        $this->addOption('mapa-tabelas', null, InputOption::VALUE_REQUIRED, 'JSON com nomes de tabelas e colunas que diferem do padrão do PBL3');
        $this->addOption('fonte', null, InputOption::VALUE_REQUIRED, 'Rótulo desta base no mapa de ids; repetir o rótulo retoma a importação', 'pbl3');
        $this->addOption('fuso', null, InputOption::VALUE_REQUIRED, 'Fuso das datas do PBL3', 'America/Sao_Paulo');
        $this->addOption('pele', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Pele do PBL3 => pele do PBL4, no formato prefixo=pele (repetível)');
        $this->addOption('sem-lixeira', null, InputOption::VALUE_NONE, 'Não importa o que está na lixeira do PBL3');
        $this->addOption('lote', null, InputOption::VALUE_REQUIRED, 'Tamanho do lote de leitura e de transação', '200');
        $this->addOption('relatorio-json', null, InputOption::VALUE_REQUIRED, 'Grava o relatório (só contagens e avisos) neste arquivo JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $mapa = $input->getOption('mapa-tabelas') === null ? MapaDeTabelas::padrao() : MapaDeTabelas::doArquivo((string) $input->getOption('mapa-tabelas'));
            $relatorio = new Relatorio();
            $servicos = Servicos::doBancoInstalado($this->caminhos);
            $origem = $this->conectar($input);
            $opcoes = new Opcoes(
                simular: !(bool) $input->getOption('gravar'),
                siteId: $this->inteiro($input, 'site'),
                raiz: $this->inteiro($input, 'raiz'),
                paiDestino: $this->inteiro($input, 'pai-destino'),
                uploads: $input->getOption('uploads') === null ? null : (string) $input->getOption('uploads'),
                fonte: (string) $input->getOption('fonte'),
                fuso: (string) $input->getOption('fuso'),
                peles: $this->peles($input),
                semLixeira: (bool) $input->getOption('sem-lixeira'),
                lote: max(10, min(2000, (int) $input->getOption('lote'))),
            );
            if ((bool) $input->getOption('gravar') && (bool) $input->getOption('simular')) {
                throw new \InvalidArgumentException('Use --gravar ou --simular, não os dois');
            }
            (new Importador($servicos, new OrigemPbl3($origem, $mapa, $relatorio), $opcoes, $relatorio))->executar();
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        foreach ($relatorio->linhas(!(bool) $input->getOption('gravar')) as $linha) {
            $output->writeln($linha);
        }
        if ((bool) $input->getOption('gravar')) {
            $output->writeln('As contas importadas não têm senha: cada pessoa precisa de uma nova (`bin/pbl usuario senha <login>`).');
        } else {
            $output->writeln('Nada foi gravado. Para importar de verdade, rode de novo com --gravar.');
        }
        $arquivo = $input->getOption('relatorio-json');
        if ($arquivo !== null) {
            file_put_contents((string) $arquivo, json_encode($relatorio->paraJson(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        }

        return Command::SUCCESS;
    }

    private function conectar(InputInterface $input): Connection
    {
        $sqlite = $input->getOption('origem-sqlite');
        $mysql = $input->getOption('origem-mysql');
        if (($sqlite === null) === ($mysql === null)) {
            throw new \InvalidArgumentException('Informe a origem: --origem-sqlite=ARQUIVO ou --origem-mysql=servidor/banco');
        }
        if ($sqlite !== null) {
            if (!is_file((string) $sqlite)) {
                throw new \InvalidArgumentException("Arquivo SQLite não encontrado: {$sqlite}");
            }

            return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => (string) $sqlite]);
        }
        if (preg_match('~^([A-Za-z0-9._-]+)(?::(\d{1,5}))?/([A-Za-z0-9_$.-]+)$~D', (string) $mysql, $m) !== 1) {
            throw new \InvalidArgumentException('Formato de --origem-mysql: servidor[:porta]/banco');
        }
        $senha = getenv('PBL3_BANCO_SENHA');

        return DriverManager::getConnection([
            'driver' => 'pdo_mysql', 'host' => $m[1], 'port' => $m[2] === '' ? 3306 : (int) $m[2], 'dbname' => $m[3],
            'user' => (string) $input->getOption('origem-usuario'), 'password' => $senha === false ? '' : $senha, 'charset' => 'utf8mb4',
        ]);
    }

    private function inteiro(InputInterface $input, string $nome): ?int
    {
        $v = $input->getOption($nome);
        if ($v === null) {
            return null;
        }
        if (!is_string($v) || !ctype_digit($v)) {
            throw new \InvalidArgumentException("--{$nome} deve ser um número");
        }

        return (int) $v;
    }

    /** @return array<string, string> */
    private function peles(InputInterface $input): array
    {
        $saida = [];
        foreach ((array) $input->getOption('pele') as $par) {
            if (!is_string($par) || substr_count($par, '=') !== 1 || str_starts_with($par, '=') || str_ends_with($par, '=')) {
                throw new \InvalidArgumentException('--pele deve ser prefixo=pele');
            }
            [$de, $para] = explode('=', $par);
            $saida[trim($de)] = trim($para);
        }

        return $saida;
    }
}
