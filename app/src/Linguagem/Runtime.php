<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/**
 * O que o código compilado pode chamar. Contexto explícito, variáveis isoladas, laços limitados:
 * um molde nunca alcança superglobais, arquivos nem funções arbitrárias do PHP (sandbox).
 */
final class Runtime
{
    public const MACROS = ['DIA', 'MES', 'ANO', 'DATA', 'INDICE', 'FIM', 'QUANTIDADE'];
    public const LIMITE_LACO = 10000;
    public const PROFUNDIDADE_INCLUIR = 8;

    /** @var array<string, mixed> */
    private array $variaveis = [];
    /** @var list<array{indice: int, total: int}> */
    private array $colecoes = [];
    private bool $semResultado = false;
    private int $profundidade = 0;

    /** @param (\Closure(string, array<string, mixed>, Runtime): string)|null $incluidor */
    public function __construct(
        private readonly ?FonteDeDados $fonte = null,
        private readonly ?\Closure $incluidor = null,
        private readonly ?\DateTimeImmutable $agora = null,
    ) {
    }

    /** @param array<string, mixed> $objeto */
    public function campo(array $objeto, string $nome): mixed
    {
        return $objeto[$nome] ?? '';
    }

    public function variavel(string $nome): mixed
    {
        return $this->variaveis[$nome] ?? '';
    }

    public function definir(string $nome, mixed $valor): void
    {
        $this->variaveis[$nome] = $valor;
    }

    public function macro(string $nome): mixed
    {
        $agora = $this->agora ?? new \DateTimeImmutable();
        $laco = $this->colecoes[count($this->colecoes) - 1] ?? ['indice' => 0, 'total' => 0];

        return match ($nome) {
            'DIA' => $agora->format('d'),
            'MES' => $agora->format('m'),
            'ANO' => $agora->format('Y'),
            'DATA' => $agora->format('d/m/Y'),
            'INDICE' => $laco['indice'],
            'FIM', 'QUANTIDADE' => $laco['total'],
            default => '',
        };
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
        return !($valor === '' || $valor === null || $valor === false || $valor === 0 || $valor === 0.0 || $valor === '0' || $valor === []);
    }

    public function cat(mixed $a, mixed $b): string
    {
        return $this->texto($a) . $this->texto($b);
    }

    public function aritmetica(string $op, mixed $a, mixed $b): int|float|string
    {
        $x = is_numeric($a) ? $a + 0 : 0;
        $y = is_numeric($b) ? $b + 0 : 0;

        return match ($op) {
            '+' => $x + $y,
            '-' => $x - $y,
            '*' => $x * $y,
            '/' => $y == 0 ? '' : $x / $y,
            default => throw new \LogicException("Operador inválido: {$op}"),
        };
    }

    /** Números comparam como números; qualquer outra combinação compara como texto. */
    public function compara(mixed $a, string $op, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            $c = ($a + 0) <=> ($b + 0);
        } else {
            $c = strcmp($this->texto($a), $this->texto($b));
        }

        return match ($op) {
            '==' => $c === 0,
            '!=' => $c !== 0,
            '<' => $c < 0,
            '>' => $c > 0,
            '<=' => $c <= 0,
            '>=' => $c >= 0,
            default => throw new \LogicException("Comparador inválido: {$op}"),
        };
    }

    public function cortar(mixed $texto, mixed $limite, mixed $inicio): string
    {
        $t = $this->texto($texto);
        $l = is_numeric($limite) ? (int) $limite : -1;
        $i = is_numeric($inicio) ? max(0, (int) $inicio) : 0;

        return $l < 0 ? mb_substr($t, $i) : mb_substr($t, $i, $l);
    }

    public function data(mixed $valor, mixed $formato): string
    {
        $v = $this->texto($valor);
        if ($v === '') {
            return '';
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d', 'd/m/Y H:i:s', 'd/m/Y'] as $entrada) {
            $d = \DateTimeImmutable::createFromFormat('!' . $entrada, $v);
            if ($d !== false) {
                return $d->format($this->texto($formato));
            }
        }

        return '';
    }

    /**
     * Faixa numérica do `repetir`, com teto para um molde não travar o servidor.
     *
     * @return \Generator<int, int>
     */
    public function faixa(mixed $inicio, mixed $fim): \Generator
    {
        $a = is_numeric($inicio) ? (int) $inicio : 0;
        $b = is_numeric($fim) ? (int) $fim : 0;
        if ($b - $a >= self::LIMITE_LACO) {
            throw new \RuntimeException('repetir excede o limite de ' . self::LIMITE_LACO . ' iterações');
        }
        for ($n = $a; $n <= $b; $n++) {
            yield $n;
        }
    }

    /**
     * @param array<string, mixed> $objeto
     * @param array<string, mixed> $p
     * @return list<array<string, mixed>>
     */
    public function filhos(array $objeto, array $p): array
    {
        return $this->fonte?->filhos($objeto, $this->filtros($p)) ?? [];
    }

    /**
     * @param array<string, mixed> $objeto
     * @param array<string, mixed> $p
     * @return list<array<string, mixed>>
     */
    public function localizar(array $objeto, array $p): array
    {
        return $this->fonte?->localizar($objeto, $this->filtros($p)) ?? [];
    }

    /**
     * @param array<string, mixed> $objeto
     * @param array<string, mixed> $p
     * @return array<string, mixed>|null
     */
    public function objeto(array $objeto, array $p): ?array
    {
        return $this->fonte?->objeto($objeto, $this->texto($p['id'] ?? ''), $this->texto($p['url'] ?? ''), $this->texto($p['titulo'] ?? ''));
    }

    /**
     * @param array<string, mixed> $objeto
     * @param array<string, mixed> $p
     */
    public function temFilhos(array $objeto, array $p): bool
    {
        $id = $p['id'] ?? null;

        return $this->fonte?->temFilhos($objeto, is_numeric($id) ? (int) $id : null) ?? false;
    }

    /**
     * @param list<array<string, mixed>> $lista
     * @return list<array<string, mixed>>
     */
    public function embaralhar(array $lista): array
    {
        shuffle($lista);

        return $lista;
    }

    /**
     * Normaliza e limita os parâmetros do molde antes de chegarem à fonte de dados.
     *
     * @param array<string, mixed> $p
     * @return array<string, int|string>
     */
    private function filtros(array $p): array
    {
        $f = [];
        foreach (['classes', 'ordem', 'campo', 'valor', 'busca'] as $chave) {
            if (isset($p[$chave]) && $this->texto($p[$chave]) !== '') {
                $f[$chave] = $this->texto($p[$chave]);
            }
        }
        foreach (['inicio', 'pai', 'niveis'] as $chave) {
            if (isset($p[$chave]) && is_numeric($p[$chave])) {
                $f[$chave] = (int) $p[$chave];
            }
        }
        $limite = isset($p['limite']) && is_numeric($p['limite']) ? (int) $p['limite'] : -1;
        $f['limite'] = $limite < 0 || $limite > self::LIMITE_LACO ? self::LIMITE_LACO : $limite;
        if (isset($f['inicio'])) {
            $f['inicio'] = max(0, (int) $f['inicio']);
        }

        return $f;
    }

    public function abrirColecao(int $total): void
    {
        $this->semResultado = $total === 0;
        $this->colecoes[] = ['indice' => 0, 'total' => $total];
    }

    public function itemDaColecao(int $indice): void
    {
        $this->colecoes[count($this->colecoes) - 1]['indice'] = $indice;
    }

    public function fecharColecao(): void
    {
        array_pop($this->colecoes);
    }

    public function semResultado(): bool
    {
        return $this->semResultado;
    }

    /** @param array<string, mixed> $objeto */
    public function incluir(mixed $nome, array $objeto): string
    {
        if ($this->incluidor === null) {
            throw new \RuntimeException('incluir não está disponível neste contexto');
        }
        if ($this->profundidade >= self::PROFUNDIDADE_INCLUIR) {
            throw new \RuntimeException('incluir aninhado demais (ciclo?)');
        }
        $this->profundidade++;
        try {
            return ($this->incluidor)($this->texto($nome), $objeto, $this);
        } finally {
            $this->profundidade--;
        }
    }

    private function texto(mixed $valor): string
    {
        return is_scalar($valor) ? (string) $valor : '';
    }
}
