<?php

declare(strict_types=1);

namespace Pbl\Conversao;

/**
 * Nome PBL4 de um arquivo de molde do PBL3: `/html/include/inc_menu.php` → `include/inc_menu`.
 * O mesmo cálculo vale para o arquivo de saída e para o argumento de `incluir`, então os dois sempre batem.
 */
final class NomesDeMolde
{
    public static function de(string $caminho): string
    {
        $caminho = str_replace('\\', '/', $caminho);
        $partes = array_values(array_filter(explode('/', $caminho), static fn (string $p): bool => $p !== '' && $p !== '.' && $p !== '..'));
        $posicao = array_search('html', $partes, true);
        if ($posicao !== false) {
            $partes = array_slice($partes, $posicao + 1);
        }
        $ultimo = count($partes) - 1;
        if ($ultimo >= 0) {
            $partes[$ultimo] = preg_replace('/\.(php|pbl|html?|txt)$/i', '', $partes[$ultimo]) ?? $partes[$ultimo];
        }
        $limpas = [];
        foreach ($partes as $p) {
            $p = strtolower((string) preg_replace('/[^a-z0-9_]+/i', '_', $p));
            $p = trim($p, '_');
            if ($p !== '') {
                $limpas[] = $p;
            }
        }

        return $limpas === [] ? 'molde' : implode('/', $limpas);
    }
}
