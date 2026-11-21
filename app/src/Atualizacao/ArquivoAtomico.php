<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

/** Leitura limitada e gravação atômica (arquivo temporário na mesma pasta + `rename`, modo 0600) dos arquivos de `dados/atualizacao/`. */
final class ArquivoAtomico
{
    /** @throws ErroDeAtualizacao */
    public static function gravar(string $arquivo, string $conteudo): void
    {
        $pasta = dirname($arquivo);
        if (!is_dir($pasta) && !@mkdir($pasta, 0750, true) && !is_dir($pasta)) {
            throw new ErroDeAtualizacao('Não foi possível criar dados/atualizacao/ (falta permissão de escrita em dados/).');
        }
        $tmp = $arquivo . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $conteudo) === false) {
            @unlink($tmp);
            throw new ErroDeAtualizacao('Não foi possível gravar em dados/atualizacao/.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $arquivo)) {
            @unlink($tmp);
            throw new ErroDeAtualizacao('Não foi possível gravar em dados/atualizacao/.');
        }
    }

    /** @return string|null o texto, ou null se não existe, é link, passa do limite ou não dá para ler */
    public static function ler(string $arquivo, int $maximoBytes): ?string
    {
        clearstatcache(true, $arquivo); // o processo do cron é longo: o tamanho em cache pode estar velho
        if (!is_file($arquivo) || is_link($arquivo)) {
            return null;
        }
        $tamanho = @filesize($arquivo);
        if ($tamanho === false || $tamanho > $maximoBytes) {
            return null;
        }
        $t = @file_get_contents($arquivo);

        return $t === false ? null : $t;
    }

    /** Remove o arquivo se existir (sem falhar). */
    public static function apagar(string $arquivo): void
    {
        @unlink($arquivo);
    }
}
