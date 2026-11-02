<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

/**
 * Ferramenta do mantenedor: monta o pacote `.zip` de uma versão, o manifesto `latest.json` e a assinatura. Parte de uma pasta já
 * preparada (com `vendor/` sem as dependências de desenvolvimento) e leva só os itens permitidos; testes, docs, `dados/` e `.git`
 * ficam de fora. A versão vem de `app/src/Versao.php`, para o pacote nunca dizer uma coisa e conter outra.
 */
final class Empacotador
{
    /**
     * @param array{atualizar_a_partir_de?: string, seguranca?: bool, resumo?: string, notas?: string, data?: string, nova_chave_de_uso?: string, chaves_revogadas?: list<string>} $opcoes
     * @param string|null $chavePrivadaHex null = não assina (o CI monta o pacote; o dono assina depois com `bin/pbl assinar`, ADR 0019)
     * @return array{zip: string, manifesto: string, assinatura: string|null, versao: string}
     */
    public function empacotar(string $origem, string $saida, ?string $chavePrivadaHex, string $canal = 'estavel', array $opcoes = []): array
    {
        $origem = rtrim($origem, '/');
        $versao = $this->versaoDe($origem);
        if ($canal !== 'teste' && (str_ends_with($versao, '-dev') || str_contains($versao, '-dev.'))) {
            throw new ErroDeAtualizacao("A versão {$versao} é de desenvolvimento: ajuste Versao::ATUAL antes de empacotar (docs/releases.md)");
        }
        if ($canal === 'estavel' && Semver::preLancamento($versao)) {
            throw new ErroDeAtualizacao("A versão {$versao} é um pré-lançamento: use o canal beta");
        }
        if (!in_array($canal, ['estavel', 'beta', 'teste'], true)) {
            throw new ErroDeAtualizacao('Canal inválido (estavel, beta ou teste)');
        }
        if (!is_dir($saida) && !@mkdir($saida, 0775, true) && !is_dir($saida)) {
            throw new ErroDeAtualizacao("Não foi possível criar {$saida}");
        }

        $arquivos = $this->listar($origem);
        $hashes = [];
        foreach ($arquivos as $relativo => $caminho) {
            $hashes[$relativo] = (string) hash_file('sha256', $caminho);
        }
        ksort($hashes);

        $zipCaminho = rtrim($saida, '/') . "/pbl4-{$versao}.zip";
        @unlink($zipCaminho);
        $z = new \ZipArchive();
        if ($z->open($zipCaminho, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new ErroDeAtualizacao('Não foi possível criar o zip');
        }
        $fixo = (int) mktime(0, 0, 0, 1, 1, 2026); // mesma entrada, mesmo zip: o empacote é reproduzível
        $z->addFromString(Pacote::ARQUIVO_DE_CONTROLE, json_encode(['versao' => $versao, 'arquivos' => $hashes], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $z->setMtimeName(Pacote::ARQUIVO_DE_CONTROLE, $fixo);
        foreach (array_keys($hashes) as $relativo) {
            $z->addFile($arquivos[$relativo], $relativo);
            $z->setMtimeName($relativo, $fixo);
            $z->setExternalAttributesName($relativo, \ZipArchive::OPSYS_UNIX, (str_starts_with($relativo, 'bin/') ? 0100755 : 0100644) << 16);
        }
        $z->close();

        $chaves = [];
        if (isset($opcoes['nova_chave_de_uso'])) {
            $chaves['uso'] = $opcoes['nova_chave_de_uso'];
        }
        if (($opcoes['chaves_revogadas'] ?? []) !== []) {
            $chaves['revogadas'] = $opcoes['chaves_revogadas'];
        }
        $manifesto = json_encode([
            'formato' => Manifesto::FORMATO,
            'canal' => $canal,
            'versao' => $versao,
            'data' => $opcoes['data'] ?? gmdate('Y-m-d'),
            'php_minimo' => $this->phpMinimo($origem),
            'atualizar_a_partir_de' => $opcoes['atualizar_a_partir_de'] ?? '0.0.0',
            'seguranca' => $opcoes['seguranca'] ?? false,
            'migracoes' => true,
            'resumo' => $opcoes['resumo'] ?? '',
            'notas' => $opcoes['notas'] ?? '',
            'pacote' => ['url' => "pbl4-{$versao}.zip", 'tamanho' => (int) filesize($zipCaminho), 'sha256' => (string) hash_file('sha256', $zipCaminho)],
        ] + ($chaves === [] ? [] : ['chaves' => $chaves]), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        // Confere que o que vamos assinar passa na mesma validação de quem vai atualizar.
        Manifesto::analisar($manifesto);

        $nome = $canal === 'estavel' ? 'latest.json' : "latest-{$canal}.json";
        $manifestoCaminho = rtrim($saida, '/') . '/' . $nome;
        file_put_contents($manifestoCaminho, $manifesto);
        $assinatura = null;
        if ($chavePrivadaHex !== null) {
            $assinatura = $manifestoCaminho . '.sig';
            file_put_contents($assinatura, Assinatura::assinar($manifesto, $chavePrivadaHex) . "\n");
        }

        return ['zip' => $zipCaminho, 'manifesto' => $manifestoCaminho, 'assinatura' => $assinatura, 'versao' => $versao];
    }

    private function versaoDe(string $origem): string
    {
        $arquivo = $origem . '/app/src/Versao.php';
        if (!is_file($arquivo) || preg_match("/const ATUAL = '([^']+)'/", (string) file_get_contents($arquivo), $m) !== 1 || !Semver::valida($m[1])) {
            throw new ErroDeAtualizacao('Não foi possível ler uma versão válida de app/src/Versao.php');
        }

        return $m[1];
    }

    private function phpMinimo(string $origem): string
    {
        $json = is_file($origem . '/composer.json') ? json_decode((string) file_get_contents($origem . '/composer.json'), true) : null;
        $exigencia = is_array($json) && isset($json['require']['php']) && is_string($json['require']['php']) ? $json['require']['php'] : '>=8.2';

        return preg_match('/(\d+\.\d+(?:\.\d+)?)/', $exigencia, $m) === 1 ? $m[1] : '8.2';
    }

    /** @return array<string, string> caminho relativo → caminho real, só do que pode ir no pacote */
    private function listar(string $origem): array
    {
        $achados = [];
        foreach (Pacote::ITENS as $item) {
            $caminho = $origem . '/' . $item;
            if (is_link($caminho)) {
                throw new ErroDeAtualizacao("{$item} é um link simbólico: o pacote não aceita");
            }
            if (is_file($caminho)) {
                $achados[$item] = $caminho;
            } elseif (is_dir($caminho)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($caminho, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isLink()) {
                        throw new ErroDeAtualizacao('Link simbólico no pacote: ' . $f->getPathname());
                    }
                    if ($f->isFile()) {
                        $achados[substr($f->getPathname(), strlen($origem) + 1)] = $f->getPathname();
                    }
                }
            }
        }
        if (!isset($achados['app/src/Versao.php'])) {
            throw new ErroDeAtualizacao('A pasta de origem não parece o PBL4 (falta app/src/Versao.php)');
        }
        if (count($achados) > Pacote::MAX_ARQUIVOS) {
            throw new ErroDeAtualizacao('Arquivos demais para um pacote');
        }
        foreach (array_keys($achados) as $relativo) {
            Pacote::validarNome($relativo);
        }

        return $achados;
    }
}
