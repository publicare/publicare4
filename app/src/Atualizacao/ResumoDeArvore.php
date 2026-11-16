<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

/**
 * SHA-256 determinístico de itens (arquivos, pastas ou links) de uma pasta base. Cada entrada entra como `caminho\0tipo\0valor`,
 * em ordem lexicográfica: valor = SHA-256 do conteúdo (arquivo) ou alvo do link (nunca seguido). Permissões e datas não entram.
 * Item inexistente é ignorado.
 */
final class ResumoDeArvore
{
    /** @param list<string> $itens */
    public static function dosItens(string $base, array $itens): string
    {
        $base = rtrim($base, '/');
        $entradas = [];
        foreach ($itens as $item) {
            self::coletar($base, $item, $entradas);
        }
        ksort($entradas, SORT_STRING);
        $ctx = hash_init('sha256');
        foreach ($entradas as $caminho => $descricao) {
            hash_update($ctx, $caminho . "\0" . $descricao . "\n");
        }

        return hash_final($ctx);
    }

    /** @param array<string, string> $entradas */
    private static function coletar(string $base, string $relativo, array &$entradas): void
    {
        $completo = "{$base}/{$relativo}";
        if (is_link($completo)) {
            $entradas[$relativo] = 'l' . "\0" . (string) readlink($completo);
        } elseif (is_dir($completo)) {
            $entradas[$relativo] = 'd' . "\0";
            foreach (scandir($completo) ?: [] as $nome) {
                if ($nome !== '.' && $nome !== '..') {
                    self::coletar($base, "{$relativo}/{$nome}", $entradas);
                }
            }
        } elseif (is_file($completo)) {
            $hash = hash_file('sha256', $completo);
            if ($hash === false) {
                throw new ErroDeAtualizacao("Não foi possível ler {$relativo} para calcular o SHA-256");
            }
            $entradas[$relativo] = 'f' . "\0" . $hash;
        }
    }
}
