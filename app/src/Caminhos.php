<?php

declare(strict_types=1);

namespace Pbl;

/**
 * Layout de pastas (ADR 0006): `app/` e `vendor/` são substituíveis pelo atualizador;
 * `dados/` (config, uploads, peles do site, cache, SQLite) nunca é tocado por ele.
 */
final class Caminhos
{
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
