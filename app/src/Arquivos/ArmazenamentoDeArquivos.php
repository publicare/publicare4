<?php

declare(strict_types=1);

namespace Pbl\Arquivos;

use Pbl\Caminhos;

/**
 * Onde o conteúdo dos arquivos mora em disco: `dados/uploads/`, fora do `docroot` (ADR 0006). O caminho é sempre montado a
 * partir da `chave` (gerada pelo servidor, nunca do nome enviado) e da extensão (da whitelist, nunca a do cliente), então
 * nunca sai da pasta de uploads. Gravação atômica: arquivo temporário na mesma pasta + `rename`.
 */
final class ArmazenamentoDeArquivos
{
    public function __construct(private readonly Caminhos $caminhos)
    {
    }

    /** Caminho em disco de um arquivo guardado (exista ou não ainda). */
    public function caminho(string $chave, string $extensao): string
    {
        self::validarChave($chave);
        $prefixo = substr($chave, 0, 2);

        return $this->caminhos->uploads() . "/{$prefixo}/{$chave}.{$extensao}";
    }

    /** Grava o conteúdo de forma atômica (arquivo temporário + rename) e retorna o caminho final. Não sobrescreve se já existir. */
    public function gravar(string $chave, string $extensao, string $conteudo): string
    {
        $destino = $this->caminho($chave, $extensao);
        if (is_file($destino)) {
            return $destino; // deduplicação: o conteúdo (pelo sha256) já está em disco
        }
        $pasta = dirname($destino);
        if (!is_dir($pasta) && !mkdir($pasta, 0775, true) && !is_dir($pasta)) {
            throw new \RuntimeException("Não foi possível criar a pasta {$pasta}");
        }
        $temporario = $pasta . '/.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporario, $conteudo) === false) {
            throw new \RuntimeException('Não foi possível gravar o arquivo enviado');
        }
        chmod($temporario, 0644);
        if (!rename($temporario, $destino)) {
            @unlink($temporario);

            throw new \RuntimeException('Não foi possível gravar o arquivo enviado');
        }

        return $destino;
    }

    public function existe(string $chave, string $extensao): bool
    {
        return is_file($this->caminho($chave, $extensao));
    }

    public function tamanho(string $chave, string $extensao): int
    {
        return (int) filesize($this->caminho($chave, $extensao));
    }

    /** Remove o arquivo físico (usado só pelo `bin/pbl limpar-uploads`). Não é erro se já não existir. */
    public function remover(string $chave, string $extensao): void
    {
        $caminho = $this->caminho($chave, $extensao);
        if (is_file($caminho)) {
            @unlink($caminho);
        }
    }

    /** Nova chave aleatória (16 bytes em hexadecimal): não é derivada de nada que o cliente mandou. */
    public static function novaChave(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function validarChave(string $chave): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $chave) !== 1) {
            throw new \InvalidArgumentException('Chave de arquivo inválida');
        }
    }
}
