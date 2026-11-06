<?php

declare(strict_types=1);

namespace Pbl\Importacao;

/**
 * O que a importação fez (ou faria, em `--simular`): contagens, avisos por tipo e os itens que não foram convertidos.
 * Guarda no máximo `EXEMPLOS` exemplos de cada aviso para o relatório não crescer com a base; a contagem é sempre exata.
 */
final class Relatorio
{
    public const EXEMPLOS = 5;

    /** @var array<string, int> */
    private array $contagens = [];
    /** @var array<string, array{total: int, exemplos: list<string>}> */
    private array $avisos = [];

    public function contar(string $chave, int $quanto = 1): void
    {
        $this->contagens[$chave] = ($this->contagens[$chave] ?? 0) + $quanto;
    }

    public function total(string $chave): int
    {
        return $this->contagens[$chave] ?? 0;
    }

    /** Um aviso agrupado por `tipo`; `detalhe` é o exemplo (um id de origem, um nome de classe: nunca conteúdo). */
    public function avisar(string $tipo, string $detalhe = ''): void
    {
        $a = $this->avisos[$tipo] ?? ['total' => 0, 'exemplos' => []];
        ++$a['total'];
        if ($detalhe !== '' && count($a['exemplos']) < self::EXEMPLOS && !in_array($detalhe, $a['exemplos'], true)) {
            $a['exemplos'][] = $detalhe;
        }
        $this->avisos[$tipo] = $a;
    }

    public function totalDeAvisos(): int
    {
        return array_sum(array_column($this->avisos, 'total'));
    }

    /** @return array<string, int> */
    public function contagens(): array
    {
        ksort($this->contagens);

        return $this->contagens;
    }

    /** @return array<string, array{total: int, exemplos: list<string>}> */
    public function avisos(): array
    {
        ksort($this->avisos);

        return $this->avisos;
    }

    /** @return list<string> as linhas do relatório em texto */
    public function linhas(bool $simulado): array
    {
        $l = [$simulado ? 'Relatório da SIMULAÇÃO (nada foi gravado):' : 'Relatório da importação:'];
        foreach ($this->contagens() as $chave => $n) {
            $l[] = '  ' . str_pad($chave, 42) . $n;
        }
        $l[] = $this->avisos === [] ? 'Sem avisos.' : 'Avisos (' . $this->totalDeAvisos() . '):';
        foreach ($this->avisos() as $tipo => $a) {
            $l[] = "  [{$a['total']}] {$tipo}" . ($a['exemplos'] === [] ? '' : ' (ex.: ' . implode(', ', $a['exemplos']) . ')');
        }

        return $l;
    }

    /** @return array{contagens: array<string, int>, avisos: array<string, array{total: int, exemplos: list<string>}>} */
    public function paraJson(): array
    {
        return ['contagens' => $this->contagens(), 'avisos' => $this->avisos()];
    }
}
