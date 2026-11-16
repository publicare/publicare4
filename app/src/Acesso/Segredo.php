<?php

declare(strict_types=1);

namespace Pbl\Acesso;

use Pbl\Arquivos\GravacaoAtomica;
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
        $novo = bin2hex(random_bytes(32));
        try {
            GravacaoAtomica::gravar($arquivo, $novo . "\n");
        } catch (\RuntimeException) {
            throw new \RuntimeException('Não foi possível gravar o segredo em ' . $arquivo);
        }

        return $novo;
    }
}
