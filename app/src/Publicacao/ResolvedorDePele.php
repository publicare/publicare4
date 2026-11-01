<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Pbl\Caminhos;
use Pbl\Linguagem\Resolvedor;

/** Moldes de uma pele em `dados/peles/<pele>/<nome>.pbl`. O nome nunca sai da pasta da pele. */
final class ResolvedorDePele implements Resolvedor
{
    public function __construct(private readonly Caminhos $caminhos, private readonly string $pele)
    {
        if (preg_match('/^[a-z0-9_-]+$/', $pele) !== 1) {
            throw new \RuntimeException("Nome de pele inválido: {$pele}");
        }
    }

    public function existe(string $nome): bool
    {
        return $this->valido($nome) && is_file($this->arquivo($nome));
    }

    public function fonte(string $nome): string
    {
        if (!$this->valido($nome)) {
            throw new \RuntimeException("Nome de molde inválido: {$nome}");
        }
        $arquivo = $this->arquivo($nome);
        if (!is_file($arquivo)) {
            throw new \RuntimeException("Molde não encontrado na pele {$this->pele}: {$nome}");
        }

        return (string) file_get_contents($arquivo);
    }

    private function valido(string $nome): bool
    {
        return preg_match('/^[a-z0-9_]+(?:\/[a-z0-9_]+)*$/', $nome) === 1;
    }

    private function arquivo(string $nome): string
    {
        return $this->caminhos->peles() . "/{$this->pele}/{$nome}.pbl";
    }
}
