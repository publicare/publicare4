<?php

declare(strict_types=1);

namespace Pbl\Conversao;

/**
 * Converte uma pasta de moldes PBL3 (arquivos `.php`, `.pbl`, `.html` que contenham tags `<@…@>`) em moldes
 * PBL4 numa pasta de destino, com um relatório em Markdown. Nada é apagado nem sobrescrito fora do destino.
 */
final class ConversorDeDiretorio
{
    private const EXTENSOES = ['php', 'pbl', 'html', 'htm'];
    private const MAX_BYTES = 2_000_000;
    private const IGNORAR = ['.git', 'vendor', 'node_modules', 'adodb', 'FCKeditor', 'tinymce', 'mpdf', 'phpmailer'];

    public function __construct(private readonly bool $modoSeguro = false)
    {
    }

    /**
     * @return array{arquivos: list<array{origem: string, molde: string, resultado: Resultado}>, ignorados: int, faltando: list<string>, relatorio: string}
     */
    public function converter(string $origem, string $destino): array
    {
        $origem = rtrim($origem, '/');
        if (!is_dir($origem)) {
            throw new \InvalidArgumentException("Pasta de origem não encontrada: {$origem}");
        }
        if (!is_dir($destino) && !mkdir($destino, 0775, true) && !is_dir($destino)) {
            throw new \RuntimeException("Não foi possível criar {$destino}");
        }
        $destino = rtrim((string) realpath($destino), '/');

        $conversor = new Conversor($this->modoSeguro);
        $arquivos = [];
        $usados = [];
        /** @var array<string, string> $semTags arquivos sem tags PBL, por nome de molde; só entram se algum molde os incluir */
        $semTags = [];
        foreach ($this->arquivos($origem) as $caminho) {
            if (str_contains((string) file_get_contents($caminho), '<@')) {
                $arquivos[] = $this->converterArquivo($conversor, $origem, $destino, $caminho, $usados);
            } else {
                $semTags[$this->nomeLivre(NomesDeMolde::de(substr($caminho, strlen($origem))), $semTags)] = $caminho;
            }
        }

        // Moldes puxados por `incluir` entram mesmo sem tags (HTML puro ou só PHP); repete até não haver novos.
        do {
            $novos = false;
            foreach ($arquivos as $a) {
                foreach ($a['resultado']->incluidos as $inc) {
                    if (isset($semTags[$inc]) && !isset($usados[$inc])) {
                        $arquivos[] = $this->converterArquivo($conversor, $origem, $destino, $semTags[$inc], $usados);
                        unset($semTags[$inc]);
                        $novos = true;
                    }
                }
            }
        } while ($novos);
        $ignorados = count($semTags);

        $existentes = array_flip(array_column($arquivos, 'molde'));
        $faltando = [];
        foreach ($arquivos as $a) {
            foreach ($a['resultado']->incluidos as $inc) {
                if (!isset($existentes[$inc])) {
                    $faltando[$inc] = true;
                }
            }
        }
        $faltando = array_keys($faltando);
        sort($faltando);

        $relatorio = $this->relatorio($arquivos, $ignorados, $faltando);
        $this->gravarComSeguranca($destino, "{$destino}/RELATORIO.md", $relatorio);

        return ['arquivos' => $arquivos, 'ignorados' => $ignorados, 'faltando' => $faltando, 'relatorio' => $relatorio];
    }

    /**
     * @param array<string, true> $usados
     * @return array{origem: string, molde: string, resultado: Resultado}
     */
    private function converterArquivo(Conversor $conversor, string $origem, string $destino, string $caminho, array &$usados): array
    {
        $molde = $this->nomeLivre(NomesDeMolde::de(substr($caminho, strlen($origem))), $usados);
        $usados[$molde] = true;
        $resultado = $conversor->converter((string) file_get_contents($caminho));
        $arquivo = "{$destino}/{$molde}.pbl";
        if (!is_dir(dirname($arquivo)) && !mkdir(dirname($arquivo), 0775, true) && !is_dir(dirname($arquivo))) {
            throw new \RuntimeException('Não foi possível criar ' . dirname($arquivo));
        }
        $this->gravarComSeguranca($destino, $arquivo, $resultado->saida);

        return ['origem' => substr($caminho, strlen($origem) + 1), 'molde' => $molde, 'resultado' => $resultado];
    }

    /**
     * Grava só dentro de `$destino`: a pasta real do arquivo precisa ser o destino ou estar dentro dele (comparando
     * com a barra final, para `/saida2` não passar por `/saida`), um link simbólico no lugar do arquivo é recusado e a
     * escrita é por arquivo temporário + `rename`, que substitui o nome em vez de seguir links.
     */
    private function gravarComSeguranca(string $destino, string $arquivo, string $conteudo): void
    {
        $pasta = realpath(dirname($arquivo));
        if ($pasta === false || ($pasta !== $destino && !str_starts_with($pasta, $destino . '/'))) {
            throw new \RuntimeException('Destino fora da pasta de saída: ' . basename($arquivo));
        }
        if (is_link($arquivo)) {
            throw new \RuntimeException('O destino é um link simbólico, recusado: ' . basename($arquivo));
        }
        $temporario = $pasta . '/.' . bin2hex(random_bytes(6)) . '.tmp';
        file_put_contents($temporario, $conteudo);
        rename($temporario, $arquivo);
    }

    /** @return list<string> */
    private function arquivos(string $pasta): array
    {
        $achados = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($pasta, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $f): bool => !in_array($f->getFilename(), self::IGNORAR, true),
        ));
        foreach ($it as $f) {
            if ($f->isFile() && !$f->isLink() && in_array(strtolower($f->getExtension()), self::EXTENSOES, true) && $f->getSize() <= self::MAX_BYTES) {
                $achados[] = $f->getPathname();
            }
        }
        sort($achados);

        return $achados;
    }

    /** @param array<array-key, mixed> $usados */
    private function nomeLivre(string $nome, array $usados): string
    {
        $candidato = $nome;
        for ($i = 2; isset($usados[$candidato]); $i++) {
            $candidato = "{$nome}_{$i}";
        }

        return $candidato;
    }

    /**
     * @param list<array{origem: string, molde: string, resultado: Resultado}> $arquivos
     * @param list<string> $faltando
     */
    private function relatorio(array $arquivos, int $ignorados, array $faltando): string
    {
        $total = count($arquivos);
        $limpos = count(array_filter($arquivos, static fn (array $a): bool => $a['resultado']->valido && $a['resultado']->contar(Aviso::REVISAR) === 0));
        $invalidos = count(array_filter($arquivos, static fn (array $a): bool => !$a['resultado']->valido));
        $campos = [];
        foreach ($arquivos as $a) {
            foreach ($a['resultado']->camposIndexaveis as $c) {
                $campos[$c] = true;
            }
        }
        $linhas = ["# Relatório de conversão PBL3 → PBL4\n", "- Moldes convertidos: **{$total}** (arquivos sem tags PBL ignorados: {$ignorados})",
            "- Prontos sem revisão: **{$limpos}**; com itens a revisar: **" . ($total - $limpos - $invalidos) . "**; inválidos no PBL4: **{$invalidos}**\n"];
        if ($campos !== []) {
            $nomes = array_keys($campos);
            sort($nomes);
            $linhas[] = '## Campos que precisam ser indexáveis nas classes' . "\n\n" . 'Usados em filtros ou ordenação: `' . implode('`, `', $nomes) . "`\n";
        }
        if ($faltando !== []) {
            $linhas[] = "## Moldes incluídos que não foram encontrados\n\n- `" . implode("`\n- `", $faltando) . "`\n";
        }
        $linhas[] = '## Por molde';
        foreach ($arquivos as $a) {
            $r = $a['resultado'];
            $selo = !$r->valido ? '❌ inválido' : ($r->contar(Aviso::REVISAR) > 0 ? '⚠️ revisar' : '✅ ok');
            $linhas[] = "\n### `{$a['molde']}` ({$a['origem']}) {$selo}";
            foreach ($r->avisos as $av) {
                if ($av->nivel === Aviso::INFO && $r->valido && $r->contar(Aviso::REVISAR) === 0) {
                    continue;
                }
                $linhas[] = "- [{$av->nivel}] linha {$av->linha}: {$av->mensagem}";
            }
        }

        return implode("\n", $linhas) . "\n";
    }
}
