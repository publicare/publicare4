<?php

declare(strict_types=1);

namespace Pbl\Demo;

use Pbl\Caminhos;

/**
 * Senha do administrador da demo: aleatória a cada reinício (32 hexadecimais de `random_bytes`), guardada em
 * `dados/demo/senha-admin` com modo 0600, gravada de forma atômica (temporário com umask 0177 + `rename`, como `Segredo`).
 * Nunca vai para log, exceção, saída do reinício nem página: só o comando `demo-senha` a mostra, na linha de comando.
 */
final class SenhaDaDemo
{
    public static function gerar(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function arquivo(Caminhos $caminhos): string
    {
        return $caminhos->dados() . '/demo/senha-admin';
    }

    public static function ler(Caminhos $caminhos): ?string
    {
        $arquivo = self::arquivo($caminhos);
        if (!is_file($arquivo) || is_link($arquivo)) {
            return null;
        }
        $valor = trim((string) file_get_contents($arquivo));

        return $valor === '' ? null : $valor;
    }

    public static function gravar(Caminhos $caminhos, string $senha): void
    {
        $arquivo = self::arquivo($caminhos);
        $pasta = dirname($arquivo);
        if (!is_dir($pasta) && !@mkdir($pasta, 0750, true) && !is_dir($pasta)) {
            throw new \RuntimeException('Não foi possível criar dados/demo/');
        }
        $temporario = $arquivo . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $antes = umask(0177); // o temporário nasce 0600, sem janela legível por outros usuários
        try {
            $gravou = @file_put_contents($temporario, $senha . "\n");
        } finally {
            umask($antes);
        }
        if ($gravou === false) {
            @unlink($temporario);
            throw new \RuntimeException('Não foi possível gravar a senha da demo em dados/demo/');
        }
        chmod($temporario, 0600);
        if (!@rename($temporario, $arquivo)) {
            @unlink($temporario);
            throw new \RuntimeException('Não foi possível gravar a senha da demo em dados/demo/');
        }
    }
}
