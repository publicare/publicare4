<?php

declare(strict_types=1);

namespace Pbl;

/**
 * Layout de pastas (ADR 0006): `app/` e `vendor/` são substituíveis pelo atualizador;
 * `dados/` (config, uploads, peles do site, cache, SQLite) nunca é tocado por ele.
 */
final class Caminhos
{
    /**
     * URL amigável: segmentos `a-z 0-9 -` separados por `/`, sem hífen nas pontas do segmento. Os quantificadores
     * são possessivos e as classes não se sobrepõem, então não há backtracking (uma versão anterior com
     * `[a-z0-9]+(?:[a-z0-9\-]*[a-z0-9])?` estourava o limite do PCRE com ~90 caracteres). Confira o tamanho
     * com `URL_TAMANHO_MAXIMO` antes de usar.
     */
    public const URL_VALIDA = '/^(?:|[a-z0-9]++(?:-++[a-z0-9]++)*+(?:\/[a-z0-9]++(?:-++[a-z0-9]++)*+)*+)$/D';

    public const URL_TAMANHO_MAXIMO = 300;

    public static function urlValida(string $url): bool
    {
        // `index` é reservada: a página inicial do site é gravada no cache como `index.html`.
        return $url !== 'index' && strlen($url) <= self::URL_TAMANHO_MAXIMO && preg_match(self::URL_VALIDA, $url) === 1;
    }

    public readonly string $raiz;

    public function __construct(string $raiz)
    {
        $this->raiz = rtrim($raiz, '/');
    }

    public function app(): string
    {
        return $this->raiz . '/app';
    }

    public function dados(): string
    {
        return $this->raiz . '/dados';
    }

    public function config(): string
    {
        return $this->dados() . '/config';
    }

    public function cache(): string
    {
        return $this->dados() . '/cache';
    }

    public function uploads(): string
    {
        return $this->dados() . '/uploads';
    }

    public function peles(): string
    {
        return $this->dados() . '/peles';
    }

    public function cachePbl(): string
    {
        return $this->cache() . '/pbl';
    }

    /**
     * Arquivo da página publicada no cache. A URL é validada: nada de `..`, barras duplas ou
     * caracteres fora de [a-z0-9-/], então o caminho nunca sai de `dados/cache/paginas/`.
     */
    public function pagina(int $siteId, string $urlAmigavel): string
    {
        if (!self::urlValida($urlAmigavel)) {
            throw new \InvalidArgumentException('URL inválida');
        }

        return $this->cache() . "/paginas/{$siteId}/" . ($urlAmigavel === '' ? 'index' : $urlAmigavel) . '.html';
    }

    /** Pasta das páginas publicadas de um site. */
    public function paginasDoSite(int $siteId): string
    {
        return $this->cache() . "/paginas/{$siteId}";
    }

    public function banco(): string
    {
        return $this->dados() . '/pbl4.sqlite';
    }

    /** Cria as pastas graváveis em `dados/`. Nunca grava dentro de `app/`. */
    public function garantirDados(): void
    {
        foreach ([$this->config(), $this->cache(), $this->uploads(), $this->peles()] as $pasta) {
            if (!is_dir($pasta) && !mkdir($pasta, 0775, true) && !is_dir($pasta)) {
                throw new \RuntimeException("Não foi possível criar a pasta {$pasta}");
            }
        }
    }
}
