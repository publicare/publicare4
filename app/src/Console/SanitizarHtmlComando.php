<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Atualizacao\ErroDeAtualizacao;
use Pbl\Atualizacao\Trava;
use Pbl\Caminhos;
use Pbl\Conteudo\HtmlSeguro;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\ViolacaoDeRegra;
use Pbl\Servicos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Varredura do HTML gravado **antes** da fase 5g (ou importado/convertido do PBL3 sem passar pela gravação normal):
 * `HtmlSeguro` só limpa na gravação, então conteúdo antigo podia guardar `script`, `on*`, `javascript:` etc. sem nunca ter
 * sido tocado desde então. Este comando passa `HtmlSeguro::limpar` em toda propriedade `html` de todo objeto, de **qualquer
 * site e qualquer estado** (inclusive a lixeira e os não publicados: o valor fica gravado mesmo sem aparecer ao público, e um
 * dia pode ser restaurado ou publicado) e, quando o resultado é diferente do gravado, grava uma **nova versão** pelo caminho
 * normal do repositório (`ObjetoRepositorio::atualizar`), nunca sobrescrevendo sem histórico.
 *
 * Lê o banco em lotes (`ObjetoRepositorio::loteOrdenadoPorId`), sem carregar todos os objetos na memória de uma vez.
 * Autoria "sistema" (sem usuário) e auditoria só com nomes de campo e ids — o conteúdo HTML nunca é auditado nem aparece na
 * saída, mesmo em `--simular` (que mostra um resumo: tags e atributos removidos, por campo).
 *
 * Idempotente: `HtmlSeguro::limpar` já é idempotente, então uma segunda passada não encontra nada para mudar.
 *
 * Exclusão mútua com `atualizar`/`reverter` pela mesma trava (`Trava`, `dados/atualizacao.lock`): não teria sentido reescrever
 * conteúdo durante uma atualização do próprio PBL4.
 */
#[AsCommand(name: 'sanitizar-html', description: 'Varre o HTML gravado antes da fase 5g e limpa pela lista de permissões (HtmlSeguro)')]
final class SanitizarHtmlComando extends Command
{
    private const TAMANHO_LOTE_PADRAO = 200;

    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('simular', null, InputOption::VALUE_NONE, 'só mostra o que mudaria, sem gravar nada')
            ->addOption('site', null, InputOption::VALUE_REQUIRED, 'restringe a varredura a um site (id)')
            ->addOption('limite', null, InputOption::VALUE_REQUIRED, 'tamanho do lote lido do banco por vez', (string) self::TAMANHO_LOTE_PADRAO);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $simular = (bool) $input->getOption('simular');
        $siteOpt = $input->getOption('site');
        $limiteOpt = $input->getOption('limite');
        $siteId = is_string($siteOpt) && $siteOpt !== '' ? (int) $siteOpt : null;
        $tamanho = is_string($limiteOpt) && $limiteOpt !== '' ? (int) $limiteOpt : self::TAMANHO_LOTE_PADRAO;
        if ($tamanho < 1) {
            $output->writeln('<error>--limite precisa ser maior que zero.</error>');

            return Command::FAILURE;
        }

        try {
            $servicos = Servicos::doBancoInstalado($this->caminhos);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($siteId !== null && $servicos->sites->porId($siteId) === null) {
            $output->writeln("<error>O site {$siteId} não existe.</error>");

            return Command::FAILURE;
        }

        $propriedades = $this->propriedadesHtmlPorClasse($servicos);
        if ($propriedades === []) {
            $output->writeln('Nenhum tipo de conteúdo tem propriedade do tipo html; nada para varrer.');

            return Command::SUCCESS;
        }

        $trava = null;
        if (!$simular) {
            try {
                $trava = Trava::obter($this->caminhos);
            } catch (ErroDeAtualizacao $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');

                return Command::FAILURE;
            }
        }
        try {
            [$varridos, $alterados, $erros] = $this->varrer($servicos, $output, $propriedades, $simular, $siteId, $tamanho);
        } finally {
            if ($trava !== null) {
                Trava::soltar($trava);
            }
        }

        $output->writeln(($simular ? '(simulação, nada foi gravado) ' : '') . "varridos: {$varridos}; alterados: {$alterados}; erros: {$erros}.");

        return $erros > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param array<int, list<string>> $propriedadesPorClasse id da classe => nomes das propriedades html
     * @return array{0: int, 1: int, 2: int} varridos, alterados, erros
     */
    private function varrer(Servicos $servicos, OutputInterface $output, array $propriedadesPorClasse, bool $simular, ?int $siteId, int $tamanho): array
    {
        $varridos = 0;
        $alterados = 0;
        $erros = 0;
        $apartirDe = 0;
        /** @var array<int, true> $sitesAlterados */
        $sitesAlterados = [];
        while (($lote = $servicos->objetos->loteOrdenadoPorId($siteId, $apartirDe, $tamanho)) !== []) {
            foreach ($lote as $objeto) {
                $apartirDe = $objeto->id;
                $nomes = $propriedadesPorClasse[$objeto->classeId] ?? null;
                if ($nomes === null) {
                    continue;
                }
                $varridos++;
                /** @var array<string, string> $mudancas */
                $mudancas = [];
                foreach ($nomes as $nome) {
                    $valor = $objeto->dados[$nome] ?? null;
                    if (!is_string($valor) || $valor === '') {
                        continue;
                    }
                    try {
                        $limpo = HtmlSeguro::limpar($valor);
                    } catch (ViolacaoDeRegra $e) {
                        $erros++;
                        $output->writeln("<error>objeto {$objeto->id} (site {$objeto->siteId}), campo {$nome}: {$e->getMessage()}</error>");
                        continue;
                    }
                    if ($limpo === $valor) {
                        continue;
                    }
                    $mudancas[$nome] = $limpo;
                    if ($simular) {
                        $output->writeln("objeto {$objeto->id} (site {$objeto->siteId}), campo {$nome}: " . $this->resumo($valor, $limpo));
                    }
                }
                if ($mudancas === []) {
                    continue;
                }
                if ($simular) {
                    $alterados++;
                    continue;
                }
                try {
                    $novo = $servicos->objetos->atualizar($objeto->id, ['dados' => $mudancas], 'sanitizar-html', $objeto->versao);
                    $alterados++;
                    $sitesAlterados[$novo->siteId] = true;
                } catch (\Throwable $e) {
                    $erros++;
                    $output->writeln("<error>objeto {$objeto->id} (site {$objeto->siteId}): {$e->getMessage()}</error>");
                }
            }
        }

        // Uma vez por site afetado, no fim: o objeto pode ser renderizado em qualquer página do site (listas, `localizar`),
        // então refazer só a página dele e a do pai deixaria cache velho no ar.
        foreach (array_keys($sitesAlterados) as $idDoSite) {
            $erros += $this->republicarSite($servicos, $output, $idDoSite);
        }

        return [$varridos, $alterados, $erros];
    }

    /**
     * Refaz o cache do site inteiro (`Publicador::publicarSite`, o mesmo de `bin/pbl publicar`). A gravação das versões não é
     * desfeita se isto falhar; mas o cache antigo continuaria no ar, então a falha conta como erro (saída diferente de zero).
     *
     * @return int 0 se republicou, 1 se falhou
     */
    private function republicarSite(Servicos $servicos, OutputInterface $output, int $siteId): int
    {
        $site = $servicos->sites->porId($siteId);
        if ($site === null) {
            return 0;
        }
        try {
            $servicos->publicador->publicarSite($site);
        } catch (\Throwable $e) {
            $output->writeln("<error>site {$siteId}: o conteúdo foi limpo, mas não foi possível republicar as páginas ({$e->getMessage()}); as páginas antigas continuam no ar. Rode `bin/pbl publicar` ou execute o comando de novo.</error>");

            return 1;
        }

        return 0;
    }

    /** @return array<int, list<string>> id da classe => nomes das propriedades html (inclusive arquivadas: o valor continua gravado e um molde ainda pode lê-lo) */
    private function propriedadesHtmlPorClasse(Servicos $servicos): array
    {
        $saida = [];
        foreach ($servicos->classes->todas() as $classe) {
            $nomes = [];
            foreach ($classe->propriedades as $p) {
                if ($p['tipo'] === 'html') {
                    $nomes[] = $p['nome'];
                }
            }
            if ($nomes !== []) {
                $saida[$classe->id] = $nomes;
            }
        }

        return $saida;
    }

    /**
     * Resumo do que `HtmlSeguro::limpar` tirou, sem nunca mostrar o HTML em si: tags e atributos que estavam no original e não
     * sobraram na saída, e o tamanho antes/depois em bytes.
     */
    private function resumo(string $original, string $limpo): string
    {
        $antes = $this->estrutura($original);
        $depois = $this->estrutura($limpo);
        $tagsRemovidas = [];
        foreach ($antes['tags'] as $tag => $n) {
            $sobra = $depois['tags'][$tag] ?? 0;
            if ($n > $sobra) {
                $tagsRemovidas[] = $n - $sobra === 1 ? $tag : "{$tag} (x" . ($n - $sobra) . ')';
            }
        }
        $atributosRemovidos = array_values(array_diff($antes['atributos'], $depois['atributos']));
        sort($tagsRemovidas);
        sort($atributosRemovidos);

        $partes = [];
        if ($tagsRemovidas !== []) {
            $partes[] = 'tags removidas: ' . implode(', ', $tagsRemovidas);
        }
        if ($atributosRemovidos !== []) {
            $partes[] = 'atributos removidos: ' . implode(', ', $atributosRemovidos);
        }
        if ($partes === []) {
            $partes[] = 'reescrito (mesmas tags e atributos; endereços ou texto normalizados)';
        }

        return implode('; ', $partes) . ' (' . strlen($original) . ' -> ' . strlen($limpo) . ' bytes)';
    }

    /** @return array{tags: array<string, int>, atributos: list<string>} */
    private function estrutura(string $html): array
    {
        $vazio = ['tags' => [], 'atributos' => []];
        if (trim($html) === '') {
            return $vazio;
        }
        $anterior = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($anterior);
        }
        $corpo = $doc->getElementsByTagName('body')->item(0);
        if (!$corpo instanceof \DOMElement) {
            return $vazio;
        }
        $tags = [];
        $atributos = [];
        // `getElementsByTagName('*')` só devolve elementos (nunca texto, comentário etc.).
        foreach ($corpo->getElementsByTagName('*') as $el) {
            $nome = strtolower($el->localName ?? $el->tagName);
            $tags[$nome] = ($tags[$nome] ?? 0) + 1;
            foreach (iterator_to_array($el->attributes ?? []) as $a) {
                $atributos[strtolower($a->name)] = true;
            }
        }

        return ['tags' => $tags, 'atributos' => array_keys($atributos)];
    }
}
