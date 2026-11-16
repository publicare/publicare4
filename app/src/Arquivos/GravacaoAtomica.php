<?php

declare(strict_types=1);

namespace Pbl\Arquivos;

/**
 * Grava um arquivo privado de forma atômica: temporário vizinho nascido 0600 (umask restritivo só em volta da gravação),
 * `chmod` e `rename`. Quem lê nunca vê o arquivo pela metade nem com permissão frouxa. Usada por `Segredo` e `banco.json`.
 */
final class GravacaoAtomica
{
    /** @throws \RuntimeException sem repetir o conteúdo na mensagem */
    public static function gravar(string $arquivo, string $conteudo, int $modoDaPasta = 0750): void
    {
        $pasta = dirname($arquivo);
        if (!is_dir($pasta) && !mkdir($pasta, $modoDaPasta, true) && !is_dir($pasta)) {
            throw new \RuntimeException('Não foi possível criar ' . $pasta);
        }
        $temporario = $arquivo . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $antes = umask(0177);
        try {
            $gravou = file_put_contents($temporario, $conteudo);
        } finally {
            umask($antes);
        }
        if ($gravou === false) {
            @unlink($temporario);
            throw new \RuntimeException('Não foi possível gravar ' . $arquivo);
        }
        chmod($temporario, 0600);
        if (!rename($temporario, $arquivo)) {
            @unlink($temporario);
            throw new \RuntimeException('Não foi possível gravar ' . $arquivo);
        }
    }
}
