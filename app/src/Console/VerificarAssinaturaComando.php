<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Atualizacao\Assinatura;
use Pbl\Atualizacao\Chaves;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dono: confere, ANTES de publicar, que `ARQUIVO.sig` é uma assinatura válida do arquivo por uma chave em que este build confia
 * (as mesmas regras do `Atualizador`: produção = uso + recuperação menos as revogadas; canal `teste` = só `Chaves::TESTE`).
 * Não lê chave privada nem imprime segredo.
 */
#[AsCommand(name: 'verificar-assinatura', description: 'Dono: confere a assinatura (ARQUIVO.sig) de um latest.json contra as chaves públicas embutidas; use antes de publicar a release')]
final class VerificarAssinaturaComando extends Command
{
    /**
     * @param list<string>|null $producao só para testes; em produção valem `Chaves::PUBLICAS`
     * @param list<string>|null $recuperacao só para testes; em produção vale `Chaves::RECUPERACAO`
     * @param list<string>|null $revogadas só para testes; em produção vale `Chaves::REVOGADAS`
     * @param list<string>|null $teste só para testes; em produção vale `Chaves::TESTE`
     */
    public function __construct(
        private readonly ?array $producao = null,
        private readonly ?array $recuperacao = null,
        private readonly ?array $revogadas = null,
        private readonly ?array $teste = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('arquivo', InputArgument::REQUIRED, 'O latest.json assinado');
        $this->addOption('assinatura', null, InputOption::VALUE_REQUIRED, 'Arquivo da assinatura (padrão: ARQUIVO.sig)', '');
        $this->addOption('canal', null, InputOption::VALUE_REQUIRED, 'estavel, beta ou teste', 'estavel');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $arquivo = (string) $input->getArgument('arquivo');
        $sig = (string) $input->getOption('assinatura') ?: $arquivo . '.sig';
        $canal = (string) $input->getOption('canal');
        if (!in_array($canal, ['estavel', 'beta', 'teste'], true)) {
            $output->writeln('<error>Canal inválido (estavel, beta ou teste).</error>');

            return Command::FAILURE;
        }
        if (!is_file($arquivo) || !is_file($sig)) {
            $output->writeln('<error>Faltam o arquivo ou a assinatura (' . $arquivo . ' e ' . $sig . ').</error>');

            return Command::FAILURE;
        }
        $revogadas = $this->revogadas ?? Chaves::REVOGADAS;
        $confiaveis = $canal === 'teste'
            ? ($this->teste ?? Chaves::TESTE)
            : array_values(array_diff($this->producao ?? Chaves::PUBLICAS, $revogadas));
        $assinou = Assinatura::quemAssinou((string) file_get_contents($arquivo), (string) file_get_contents($sig), $confiaveis);
        if ($assinou === null) {
            $output->writeln('<error>A assinatura NÃO confere com nenhuma chave de confiança do canal ' . $canal . ': não publique.</error>');

            return Command::FAILURE;
        }
        $papel = $canal === 'teste' ? 'teste' : (in_array($assinou, $this->recuperacao ?? Chaves::RECUPERACAO, true) ? 'recuperação' : 'uso');
        $output->writeln("Assinatura válida, feita pela chave de {$papel} ({$assinou}).");

        return Command::SUCCESS;
    }
}
