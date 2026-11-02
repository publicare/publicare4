<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Pbl\Caminhos;
use Pbl\Linguagem\Resolvedor;

/**
 * Moldes de uma pele em `dados/peles/<pele>/<nome>.pbl`. O nome nunca sai da pasta da pele.
 *
 * `$sobrescritas` (fase 5j, pré-visualização de rascunho): fonte de um ou mais moldes vinda da memória (o
 * rascunho ainda não gravado), no lugar do arquivo. Vazio é o uso normal (fase 5e); a herança (`estende`,
 * `incluir`) continua lendo o disco para qualquer outro nome.
 *
 * @phpstan-type Sobrescritas array<string, string>
 */
final class ResolvedorDePele implements Resolvedor
{
    /** @param Sobrescritas $sobrescritas nome do molde (sem `.pbl`) => conteúdo */
    public function __construct(private readonly Caminhos $caminhos, private readonly string $pele, private readonly array $sobrescritas = [])
    {
        if (preg_match('/^[a-z0-9_-]+$/', $pele) !== 1) {
            throw new \RuntimeException("Nome de pele inválido: {$pele}");
        }
    }

    public function existe(string $nome): bool
    {
        return $this->valido($nome) && (isset($this->sobrescritas[$nome]) || is_file($this->arquivo($nome)));
    }

    public function fonte(string $nome): string
    {
        if (!$this->valido($nome)) {
            throw new \RuntimeException("Nome de molde inválido: {$nome}");
        }
        if (isset($this->sobrescritas[$nome])) {
            return $this->sobrescritas[$nome];
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
