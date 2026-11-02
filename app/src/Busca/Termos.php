<?php

declare(strict_types=1);

namespace Pbl\Busca;

/**
 * Palavras de busca (nível B, ADR 0010): quebra o texto em termos normalizados, tira palavras vazias (`de`, `da`, `para`...)
 * e reduz o plural do português à forma do singular, para `acoes` achar `ação` e `casas` achar `casa`.
 *
 * A redução é de propósito **simples e previsível** (só plural, sem radical de verbos nem feminino); erra em poucos casos
 * (`fases`→`fas`) e o erro é igual na gravação e na consulta, então o par ainda se encontra. É a mesma função nos dois lados.
 */
final class Termos
{
    /** Teto de termos de uma consulta e de tamanho do texto digitado: a consulta nunca cresce com a entrada. */
    public const MAX_TERMOS = 8;
    public const MAX_TAMANHO_CONSULTA = 200;
    public const MAX_TAMANHO_TERMO = 40;
    /** Termos guardados por objeto e texto indexado por objeto (o resto é ignorado). */
    public const MAX_TERMOS_POR_OBJETO = 1500;
    public const MAX_TEXTO_INDEXADO = 60000;

    private const VAZIAS = [
        'de', 'da', 'do', 'das', 'dos', 'em', 'no', 'na', 'nos', 'nas', 'um', 'uma', 'uns', 'umas', 'para', 'pra', 'por', 'com',
        'sem', 'sob', 'ao', 'aos', 'as', 'os', 'ou', 'que', 'se', 'ser', 'ate', 'mais', 'como', 'seu', 'sua', 'seus', 'suas',
    ];

    /** @var array<string, true>|null */
    private static ?array $vazias = null;

    /**
     * Termos de um texto, na ordem em que aparecem, já reduzidos e sem repetição.
     *
     * @return list<string>
     */
    public static function de(string $texto, int $maximo = self::MAX_TERMOS_POR_OBJETO): array
    {
        $saida = [];
        foreach (self::palavras($texto) as $palavra) {
            $termo = self::termo($palavra);
            if ($termo !== null && !isset($saida[$termo])) {
                $saida[$termo] = true;
                if (count($saida) >= $maximo) {
                    break;
                }
            }
        }

        return array_map(strval(...), array_keys($saida));
    }

    /**
     * Termos da consulta digitada (no máximo `MAX_TERMOS`, texto cortado em `MAX_TAMANHO_CONSULTA`).
     *
     * @return list<string>
     */
    public static function daConsulta(string $consulta): array
    {
        return self::de(mb_substr($consulta, 0, self::MAX_TAMANHO_CONSULTA), self::MAX_TERMOS);
    }

    /** Termo guardado/pesquisado para uma palavra, ou null se é palavra vazia ou curta demais (1 letra). */
    public static function termo(string $palavra): ?string
    {
        $palavra = mb_substr(Texto::normalizar($palavra), 0, self::MAX_TAMANHO_TERMO);
        if (mb_strlen($palavra) < 2 || isset(self::vazias()[$palavra])) {
            return null;
        }

        return self::singular($palavra);
    }

    /** Plural → singular, só o comum: `ões/ães`→`ão`, `ais/eis/ois`→`al/el/ol`, `ns`→`m`, `res/zes`→`r/z`, `s` final. */
    public static function singular(string $p): string
    {
        $n = strlen($p);
        if ($n <= 3 || !ctype_alnum($p)) {
            return $p;
        }
        foreach ([['oes', 'ao', 3], ['aes', 'ao', 3], ['ais', 'al', 4], ['eis', 'el', 4], ['ois', 'ol', 4], ['res', 'r', 4], ['zes', 'z', 4]] as [$de, $para, $minimo]) {
            if ($n > $minimo && str_ends_with($p, $de)) {
                return substr($p, 0, $n - 3) . $para;
            }
        }
        if (str_ends_with($p, 'ns')) {
            return substr($p, 0, $n - 2) . 'm';
        }
        if (str_ends_with($p, 's') && !preg_match('/(ss|us|is)$/', $p)) {
            return substr($p, 0, $n - 1);
        }

        return $p;
    }

    /** @return list<string> palavras (letras e dígitos) do texto, normalizadas */
    public static function palavras(string $texto): array
    {
        $normal = Texto::normalizar($texto);
        $partes = preg_split('/[^\p{L}\p{N}]+/u', $normal, -1, PREG_SPLIT_NO_EMPTY);

        return $partes === false ? [] : $partes;
    }

    /** @return array<string, true> */
    private static function vazias(): array
    {
        return self::$vazias ??= array_fill_keys(self::VAZIAS, true);
    }
}
