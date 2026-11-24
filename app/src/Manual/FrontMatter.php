<?php

declare(strict_types=1);

namespace Pbl\Manual;

/**
 * Front matter restrito do manual (ADR 0040): bloco entre duas linhas `---` no início do arquivo, com `chave: valor` simples
 * (sem aninhamento, sem listas). Chave desconhecida, repetida ou fora do formato é erro, com arquivo:linha.
 */
final class FrontMatter
{
    /** Chaves aceitas e seu tipo: s = texto, i = inteiro. */
    private const CHAVES = ['titulo' => 's', 'resumo' => 's', 'capitulo' => 's', 'ordem' => 'i', 'slug' => 's', 'publico' => 's'];
    public const MAX_RESUMO = 200;
    public const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    /**
     * @return array{meta: array<string, string|int>, corpo: string, linhaDoCorpo: int}
     */
    public static function ler(string $texto, string $arquivo): array
    {
        $linhas = preg_split('/\R/', $texto) ?: [];
        if (($linhas[0] ?? '') !== '---') {
            throw ErroDeManual::em($arquivo, 1, 'falta o front matter (bloco "---" no início).');
        }
        $meta = [];
        $fim = null;
        for ($i = 1, $n = count($linhas); $i < $n; $i++) {
            $l = $linhas[$i];
            if ($l === '---') {
                $fim = $i;
                break;
            }
            if (trim($l) === '') {
                continue;
            }
            if (preg_match('/^([a-z_]+): (.+)$/D', $l, $m) !== 1) {
                throw ErroDeManual::em($arquivo, $i + 1, 'use "chave: valor" (sem aninhamento nem listas).');
            }
            [, $chave, $valor] = $m;
            if (!isset(self::CHAVES[$chave])) {
                throw ErroDeManual::em($arquivo, $i + 1, "chave desconhecida \"{$chave}\".");
            }
            if (array_key_exists($chave, $meta)) {
                throw ErroDeManual::em($arquivo, $i + 1, "chave repetida \"{$chave}\".");
            }
            $valor = trim($valor);
            if (self::CHAVES[$chave] === 'i') {
                if (preg_match('/^[1-9][0-9]{0,5}$/D', $valor) !== 1) {
                    throw ErroDeManual::em($arquivo, $i + 1, "\"{$chave}\" precisa ser um inteiro positivo.");
                }
                $meta[$chave] = (int) $valor;
            } else {
                $meta[$chave] = $valor;
            }
        }
        if ($fim === null) {
            throw ErroDeManual::em($arquivo, 1, 'o front matter não foi fechado com "---".');
        }

        return ['meta' => $meta, 'corpo' => implode("\n", array_slice($linhas, $fim + 1)), 'linhaDoCorpo' => $fim + 2];
    }

    /**
     * Confere os campos de uma página do manual e devolve os valores já completos (`slug` padrão = nome do arquivo, `publico` padrão = manual).
     *
     * @param array<string, string|int> $meta
     * @return array{titulo: string, resumo: string, capitulo: string, ordem: int, slug: string, publico: string}
     */
    public static function validarPagina(array $meta, string $arquivo, string $nomeDoArquivo): array
    {
        foreach (['titulo', 'resumo', 'capitulo', 'ordem'] as $obrigatoria) {
            if (!isset($meta[$obrigatoria]) || $meta[$obrigatoria] === '') {
                throw ErroDeManual::em($arquivo, 1, "falta \"{$obrigatoria}\" no front matter.");
            }
        }
        $slug = (string) ($meta['slug'] ?? $nomeDoArquivo);
        $publico = (string) ($meta['publico'] ?? 'manual');
        if (!in_array($publico, ['manual', 'contribuicao'], true)) {
            throw ErroDeManual::em($arquivo, 1, '"publico" é "manual" ou "contribuicao".');
        }
        if (preg_match(self::SLUG, $slug) !== 1 || preg_match(self::SLUG, (string) $meta['capitulo']) !== 1) {
            throw ErroDeManual::em($arquivo, 1, 'slug e capitulo usam só minúsculas sem acento, números e hífen.');
        }
        if (mb_strlen((string) $meta['resumo']) > self::MAX_RESUMO || mb_strlen((string) $meta['titulo']) > 120) {
            throw ErroDeManual::em($arquivo, 1, 'título até 120 e resumo até ' . self::MAX_RESUMO . ' caracteres.');
        }

        return ['titulo' => (string) $meta['titulo'], 'resumo' => (string) $meta['resumo'], 'capitulo' => (string) $meta['capitulo'], 'ordem' => (int) $meta['ordem'], 'slug' => $slug, 'publico' => $publico];
    }
}
