<?php

declare(strict_types=1);

namespace Pbl\Busca;

/**
 * Normalização de texto para busca: minúsculas, sem acentos, espaços reduzidos. `Presidência`, `PRESIDENCIA` e
 * `presidência` viram `presidencia`.
 *
 * Feita em PHP, **na gravação** e na consulta, com um mapa próprio: o resultado é idêntico em SQLite, MySQL e
 * PostgreSQL e não depende de extensão (`intl`, `unaccent`) que a hospedagem comum nem sempre tem.
 */
final class Texto
{
    /** @var array<string, string>|null */
    private static ?array $mapa = null;

    public static function normalizar(string $texto): string
    {
        $texto = strtr(mb_strtolower($texto, 'UTF-8'), self::mapa());
        // Letras já decompostas (base + acento combinante), comuns em texto vindo de Mac e de PDF.
        $texto = (string) preg_replace('/\p{Mn}+/u', '', $texto);

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    /** @return array<string, string> */
    private static function mapa(): array
    {
        if (self::$mapa !== null) {
            return self::$mapa;
        }
        $grupos = [
            'a' => 'àáâãäåāăąǎ', 'c' => 'çćĉċč', 'd' => 'ďđ', 'e' => 'èéêëēĕėęěǝ', 'g' => 'ĝğġģ', 'h' => 'ĥħ',
            'i' => 'ìíîïĩīĭįıǐ', 'j' => 'ĵ', 'k' => 'ķ', 'l' => 'ĺļľŀł', 'n' => 'ñńņňŉ', 'o' => 'òóôõöøōŏőǒ',
            'r' => 'ŕŗř', 's' => 'śŝşš', 't' => 'ţťŧ', 'u' => 'ùúûüũūŭůűųǔ', 'w' => 'ŵ', 'y' => 'ýÿŷ', 'z' => 'źżž',
        ];
        $mapa = ['ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ǆ' => 'dz', 'ĳ' => 'ij'];
        foreach ($grupos as $base => $letras) {
            foreach (mb_str_split($letras) as $letra) {
                $mapa[$letra] = $base;
            }
        }

        return self::$mapa = $mapa;
    }
}
