<?php

declare(strict_types=1);

namespace Pbl\Arquivos;

/** Tamanho de arquivo em texto legível (`B`, `KB`, `MB`), com vírgula decimal (pt-BR). Usado na administração e no molde (`%arquivo info="tamanho"`). */
final class TamanhoLegivel
{
    public static function formatar(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return self::casaDecimal($bytes / 1024) . ' KB';
        }

        return self::casaDecimal($bytes / (1024 * 1024)) . ' MB';
    }

    private static function casaDecimal(float $valor): string
    {
        return number_format($valor, 1, ',', '.');
    }
}
