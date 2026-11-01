<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** Funções que o código compilado pode chamar. Contexto explícito, sem globais. */
final class Runtime
{
    /** @param array<string, mixed> $contexto */
    public function campo(array $contexto, string $nome): mixed
    {
        return $contexto[$nome] ?? '';
    }

    /** Escape por padrão (fluxo 2, item 5). */
    public function esc(mixed $valor): string
    {
        return htmlspecialchars($this->texto($valor), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Saída sem escape: só para HTML confiável, escolhido de forma explícita no molde. */
    public function cru(mixed $valor): string
    {
        return $this->texto($valor);
    }

    public function verdade(mixed $valor): bool
    {
        return !($valor === '' || $valor === null || $valor === false || $valor === 0 || $valor === 0.0 || $valor === [] || $valor === '0');
    }

    /**
     * @param array<string, mixed> $contexto
     * @return list<array<string, mixed>>
     */
    public function lista(array $contexto, string $nome): array
    {
        $valor = $contexto[$nome] ?? [];

        return is_array($valor) ? array_values(array_filter($valor, 'is_array')) : [];
    }

    private function texto(mixed $valor): string
    {
        return is_scalar($valor) ? (string) $valor : '';
    }
}
