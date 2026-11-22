<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

/**
 * Texto → ASCII sem depender de `iconv(...//TRANSLIT)`: em Alpine/musl (imagens de contêiner, prévias da VSF) o `//TRANSLIT` não existe
 * e o `iconv` lança aviso ("Wrong encoding"), derrubando a geração do pacote de prévia. Usa a extensão intl quando há; senão, uma tabela
 * para o português/latim comum. Nunca emite aviso.
 */
final class Ascii
{
    private const TABELA = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe',
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ú' => 'U',
        'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ç' => 'C', 'Ñ' => 'N', 'Ý' => 'Y', 'Æ' => 'AE', 'Œ' => 'OE',
        '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', '…' => '...', "\u{00A0}" => ' ',
    ];

    public static function de(string $texto): string
    {
        if (class_exists(\Transliterator::class)) {
            $t = \Transliterator::create('Any-Latin; Latin-ASCII');
            $r = $t?->transliterate($texto);
            if (is_string($r)) {
                return (string) preg_replace('/[^\x00-\x7F]/', '', $r); // o que o intl não converte (emoji etc.) some
            }
        }

        // O que a tabela não cobre (ideogramas, símbolos) vira nada, nunca '?' nem aviso.
        return (string) preg_replace('/[^\x00-\x7F]/', '', strtr($texto, self::TABELA));
    }
}
