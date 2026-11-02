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
                    $this->republicar($servicos, $novo);
                } catch (\Throwable $e) {
                    $erros++;
                    $output->writeln("<error>objeto {$objeto->id} (site {$objeto->siteId}): {$e->getMessage()}</error>");
                }
            }
        }

        return [$varridos, $alterados, $erros];
    }

    /**
     * Mesmo mecanismo da edição pelo painel (`TelasDeObjetos::sincronizar`): regrava a página no cache se o objeto está
     * visível, ou tira de lá se não está; o pai também é refeito (pode listar o conteúdo). Falhar aqui não desfaz a
     * gravação: a próxima publicação do site (`bin/pbl publicar`) recupera a página.
     */
    private function republicar(Servicos $servicos, Objeto $objeto): void
    {
        $site = $servicos->sites->porId($objeto->siteId);
        if ($site === null) {
            return;
        }
        $agora = gmdate('c');
        $pai = $objeto->paiId === null ? null : $servicos->objetos->porId($objeto->paiId);
        foreach ([$objeto, $pai] as $alvo) {
            if ($alvo === null) {
                continue;
            }
            try {
                if ($alvo->visivel($agora)) {
                    $servicos->publicador->publicar($site, $alvo);
                } else {
                    $servicos->publicador->despublicar($site, $alvo);
                }
            } catch (\Throwable) {
                continue;
            }
        }
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
