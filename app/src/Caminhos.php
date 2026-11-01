<?php

declare(strict_types=1);

namespace Pbl;

/**
 * Layout de pastas (ADR 0006): `app/` e `vendor/` são substituíveis pelo atualizador;
 * `dados/` (config, uploads, peles do site, cache, SQLite) nunca é tocado por ele.
 */
final class Caminhos
{
    public const URL_VALIDA = '/^(?:|[a-z0-9]+(?:[a-z0-9\-]*[a-z0-9])?(?:\/[a-z0-9]+(?:[a-z0-9\-]*[a-z0-9])?)*)$/';

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
        if (preg_match(self::URL_VALIDA, $urlAmigavel) !== 1) {
            throw new \InvalidArgumentException('URL inválida');
        }

        return $this->cache() . "/paginas/{$siteId}/" . ($urlAmigavel === '' ? 'index' : $urlAmigavel) . '.html';
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
