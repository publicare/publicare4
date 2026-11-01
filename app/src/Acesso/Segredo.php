<?php

declare(strict_types=1);

namespace Pbl\Acesso;

use Pbl\Caminhos;

/**
 * Segredo do site: 32 bytes aleatórios em `dados/config/segredo` (permissão 0600, fora do Git). Serve para assinar o token de
 * CSRF e para guardar IP e login como hash. Se o arquivo não existe (instalação anterior à fase 3b), é criado na primeira vez.
 */
final class Segredo
{
    public static function obter(Caminhos $caminhos): string
    {
        $arquivo = $caminhos->config() . '/segredo';
        if (is_file($arquivo)) {
            $valor = trim((string) file_get_contents($arquivo));
            if (preg_match('/^[0-9a-f]{64}$/D', $valor) === 1) {
                return $valor;
            }
        }
        if (!is_dir($caminhos->config()) && !mkdir($caminhos->config(), 0775, true) && !is_dir($caminhos->config())) {
            throw new \RuntimeException('Não foi possível criar ' . $caminhos->config());
        }
        $novo = bin2hex(random_bytes(32));
        $temporario = $arquivo . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($temporario, $novo . "\n");
        chmod($temporario, 0600);
        rename($temporario, $arquivo);

        return $novo;
    }
}
