<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

/**
 * Pacote de atualização: um `.zip` com o código (`app/`, `vendor/`, `bin/`, `public/`…) e um `PACOTE.json` com o hash de cada
 * arquivo. A extração é defensiva: nada fora das pastas permitidas, sem `..`, caminho absoluto nem link simbólico, com teto de
 * arquivos e de bytes (contados na leitura, sem confiar no cabeçalho), e tudo conferido contra o `PACOTE.json`. `dados/` nunca
 * faz parte do pacote.
 */
final class Pacote
{
    /** Itens da raiz do projeto que o pacote traz e a atualização troca. */
    public const ITENS = ['app', 'vendor', 'bin', 'public', 'composer.json', 'composer.lock', '.htaccess', 'LICENSE', 'NOTICE'];
    public const ARQUIVO_DE_CONTROLE = 'PACOTE.json';
    public const MAX_ARQUIVOS = 30_000;
    public const MAX_BYTES = 400_000_000;

    /**
     * @return array{versao: string, arquivos: array<string, string>} o que foi extraído (caminho → sha256)
     * @throws ErroDeAtualizacao
     */
    public static function extrair(string $zip, string $destino, string $versaoEsperada): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new ErroDeAtualizacao('A extensão zip do PHP não está ativa neste servidor');
        }
        $z = new \ZipArchive();
        if ($z->open($zip, \ZipArchive::RDONLY) !== true) {
            throw new ErroDeAtualizacao('O pacote não é um arquivo zip válido');
        }
        try {
            if ($z->numFiles > self::MAX_ARQUIVOS || $z->numFiles < 2) {
                throw new ErroDeAtualizacao('O pacote tem uma quantidade de arquivos inválida');
            }
            $controle = $z->getFromName(self::ARQUIVO_DE_CONTROLE);
            $dados = $controle === false ? null : json_decode($controle, true);
            if (!is_array($dados) || ($dados['versao'] ?? null) !== $versaoEsperada || !is_array($dados['arquivos'] ?? null)) {
                throw new ErroDeAtualizacao('O pacote não traz o PACOTE.json esperado para a versão ' . $versaoEsperada);
            }
            /** @var array<string, string> $esperados */
            $esperados = $dados['arquivos'];
            if (!is_dir($destino) && !@mkdir($destino, 0775, true) && !is_dir($destino)) {
                throw new ErroDeAtualizacao('Não foi possível preparar a pasta de extração');
            }
            $obtidos = [];
            $total = 0;
            for ($i = 0; $i < $z->numFiles; $i++) {
                $nome = (string) $z->getNameIndex($i);
                if ($nome === self::ARQUIVO_DE_CONTROLE) {
                    continue;
                }
                $ehPasta = str_ends_with($nome, '/');
                $limpo = self::validarNome(rtrim($nome, '/'));
                self::recusarLinkSimbolico($z, $i, $nome);
                $alvo = $destino . '/' . $limpo;
                if ($ehPasta) {
                    if (!is_dir($alvo) && !@mkdir($alvo, 0775, true) && !is_dir($alvo)) {
                        throw new ErroDeAtualizacao("Não foi possível criar a pasta {$limpo}");
                    }
                    continue;
                }
                if (!isset($esperados[$limpo])) {
                    throw new ErroDeAtualizacao("Arquivo fora da lista do pacote: {$limpo}");
                }
                $pasta = dirname($alvo);
                if (!is_dir($pasta) && !@mkdir($pasta, 0775, true) && !is_dir($pasta)) {
                    throw new ErroDeAtualizacao("Não foi possível criar a pasta de {$limpo}");
                }
                $total += self::copiarEntrada($z, $i, $alvo, self::MAX_BYTES - $total);
                $hash = hash_file('sha256', $alvo);
                if ($hash === false || !hash_equals((string) $esperados[$limpo], $hash)) {
                    throw new ErroDeAtualizacao("O arquivo {$limpo} não confere com o PACOTE.json");
                }
                $obtidos[$limpo] = $hash;
                @chmod($alvo, str_starts_with($limpo, 'bin/') ? 0755 : 0644); // o lançador bin/pbl precisa continuar executável
            }
            $faltando = array_diff_key($esperados, $obtidos);
            if ($faltando !== []) {
                throw new ErroDeAtualizacao('O pacote está incompleto: falta ' . array_key_first($faltando));
            }

            return ['versao' => $versaoEsperada, 'arquivos' => $obtidos];
        } finally {
            $z->close();
        }
    }

    /** Nome seguro de um arquivo do pacote: dentro das pastas permitidas, sem truques de caminho. */
    public static function validarNome(string $nome): string
    {
        if ($nome === '' || strlen($nome) > 300 || preg_match('/[\x00-\x1F\x7F\\\\:]/', $nome) === 1 || str_starts_with($nome, '/')) {
            throw new ErroDeAtualizacao('Nome de arquivo inválido no pacote');
        }
        $partes = explode('/', $nome);
        foreach ($partes as $parte) {
            if ($parte === '' || $parte === '.' || $parte === '..') {
                throw new ErroDeAtualizacao("Caminho inválido no pacote: {$nome}");
            }
        }
        if (!in_array($partes[0], self::ITENS, true)) {
            throw new ErroDeAtualizacao("O pacote traz algo fora do permitido: {$partes[0]}");
        }

        return $nome;
    }

    private static function recusarLinkSimbolico(\ZipArchive $z, int $indice, string $nome): void
    {
        $sistema = 0;
        $atributos = 0;
        if ($z->getExternalAttributesIndex($indice, $sistema, $atributos) && $sistema === \ZipArchive::OPSYS_UNIX && (($atributos >> 16) & 0170000) === 0120000) {
            throw new ErroDeAtualizacao("O pacote traz um link simbólico: {$nome}");
        }
    }

    private static function copiarEntrada(\ZipArchive $z, int $indice, string $alvo, int $restante): int
    {
        $entrada = $z->getStreamIndex($indice);
        $saida = @fopen($alvo, 'wb');
        if ($entrada === false || $saida === false) {
            throw new ErroDeAtualizacao('Não foi possível extrair um arquivo do pacote');
        }
        $lidos = 0;
        while (!feof($entrada)) {
            $trecho = fread($entrada, 65536);
            if ($trecho === false) {
                break;
            }
            $lidos += strlen($trecho);
            if ($lidos > $restante) {
                fclose($entrada);
                fclose($saida);
                @unlink($alvo);
                throw new ErroDeAtualizacao('O pacote descomprimido é maior que o permitido');
            }
            fwrite($saida, $trecho);
        }
        fclose($entrada);
        fclose($saida);

        return $lidos;
    }
}
