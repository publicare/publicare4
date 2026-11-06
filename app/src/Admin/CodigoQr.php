<?php

declare(strict_types=1);

namespace Pbl\Admin;

/**
 * Gerador de QR Code em PHP puro (ISO/IEC 18004, modo byte, versões 1 a 10, correção M com queda para L), escrito para o PBL4 (ADR 0026):
 * sem biblioteca, sem CDN e sem GD. Serve para endereços públicos de conteúdo (até 271 bytes). A saída é um SVG de um só `<path>`.
 */
final class CodigoQr
{
    /** versão => [palavras de correção por bloco, blocos do grupo 1, dados do grupo 1, blocos do grupo 2, dados do grupo 2] */
    private const BLOCOS_M = [
        1 => [10, 1, 16, 0, 0], 2 => [16, 1, 28, 0, 0], 3 => [26, 1, 44, 0, 0], 4 => [18, 2, 32, 0, 0], 5 => [24, 2, 43, 0, 0],
        6 => [16, 4, 27, 0, 0], 7 => [18, 4, 31, 0, 0], 8 => [22, 2, 38, 2, 39], 9 => [22, 3, 36, 2, 37], 10 => [26, 4, 43, 1, 44],
    ];

    private const BLOCOS_L = [
        1 => [7, 1, 19, 0, 0], 2 => [10, 1, 34, 0, 0], 3 => [15, 1, 55, 0, 0], 4 => [20, 1, 80, 0, 0], 5 => [26, 1, 108, 0, 0],
        6 => [18, 2, 68, 0, 0], 7 => [20, 2, 78, 0, 0], 8 => [24, 2, 97, 0, 0], 9 => [30, 2, 116, 0, 0], 10 => [18, 2, 68, 2, 69],
    ];

    private const ALINHAMENTO = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    private int $tamanho = 0;

    /** @var array<int, array<int, bool>> */
    private array $modulos = [];

    /** @var array<int, array<int, bool>> */
    private array $funcao = [];

    /**
     * A matriz de módulos (true = escuro), sem a margem.
     *
     * @param int|null $mascara 0 a 7 para forçar a máscara (testes); null escolhe a de menor penalidade
     * @return array<int, array<int, bool>>
     * @throws \InvalidArgumentException se o texto não cabe na versão 10
     */
    public static function matriz(string $texto, ?int $mascara = null): array
    {
        $qr = new self();
        $qr->montar($texto, $mascara);

        return $qr->modulos;
    }

    /** O QR como SVG (preto sobre branco, com a margem de 4 módulos exigida pela norma). */
    public static function svg(string $texto, int $pixelsPorModulo = 6): string
    {
        $m = self::matriz($texto);
        $n = count($m);
        $total = $n + 8;
        $d = '';
        for ($y = 0; $y < $n; ++$y) {
            $x = 0;
            while ($x < $n) {
                if (!$m[$y][$x]) {
                    ++$x;
                    continue;
                }
                $ini = $x;
                while ($x < $n && $m[$y][$x]) {
                    ++$x;
                }
                $d .= 'M' . ($ini + 4) . ' ' . ($y + 4) . 'h' . ($x - $ini) . 'v1h-' . ($x - $ini) . 'z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '" width="' . ($total * $pixelsPorModulo) . '" height="' . ($total * $pixelsPorModulo) . '" role="img" aria-label="QR code" shape-rendering="crispEdges">'
            . '<rect width="' . $total . '" height="' . $total . '" fill="#fff"/><path d="' . $d . '" fill="#000"/></svg>';
    }

    private function montar(string $texto, ?int $mascara): void
    {
        [$versao, $nivelM, $dados] = $this->codificar($texto);
        $codewords = $this->comCorrecao($versao, $nivelM ? self::BLOCOS_M[$versao] : self::BLOCOS_L[$versao], $dados);
        $this->tamanho = $versao * 4 + 17;
        $this->modulos = array_fill(0, $this->tamanho, array_fill(0, $this->tamanho, false));
        $this->funcao = $this->modulos;
        $this->desenharFuncoes($versao);
        $this->posicionar($codewords);
        if ($mascara === null) {
            $melhor = PHP_INT_MAX;
            for ($k = 0; $k < 8; ++$k) {
                $this->aplicarMascara($k);
                $this->desenharFormato($nivelM, $k);
                $p = $this->penalidade();
                if ($p < $melhor) {
                    $melhor = $p;
                    $mascara = $k;
                }
                $this->aplicarMascara($k);
            }
        }
        $mascara ??= 0;
        $this->aplicarMascara($mascara);
        $this->desenharFormato($nivelM, $mascara);
    }

    /** @return array{0: int, 1: bool, 2: list<int>} versão, se é nível M, palavras de dados */
    private function codificar(string $texto): array
    {
        $bytes = array_values(unpack('C*', $texto) ?: []);
        $n = count($bytes);
        foreach ([true, false] as $nivelM) {
            $tabela = $nivelM ? self::BLOCOS_M : self::BLOCOS_L;
            for ($v = 1; $v <= 10; ++$v) {
                [, $b1, $d1, $b2, $d2] = $tabela[$v];
                $capacidade = $b1 * $d1 + $b2 * $d2;
                $bitsContagem = $v <= 9 ? 8 : 16;
                if (4 + $bitsContagem + 8 * $n > $capacidade * 8) {
                    continue;
                }
                $bits = [];
                $this->empurrar($bits, 0b0100, 4);
                $this->empurrar($bits, $n, $bitsContagem);
                foreach ($bytes as $byte) {
                    $this->empurrar($bits, $byte, 8);
                }
                $this->empurrar($bits, 0, min(4, $capacidade * 8 - count($bits)));
                while (count($bits) % 8 !== 0) {
                    $bits[] = 0;
                }
                $palavras = [];
                for ($i = 0, $t = count($bits); $i < $t; $i += 8) {
                    $w = 0;
                    for ($j = 0; $j < 8; ++$j) {
                        $w = ($w << 1) | $bits[$i + $j];
                    }
                    $palavras[] = $w;
                }
                for ($pad = 0xEC; count($palavras) < $capacidade; $pad ^= 0xEC ^ 0x11) {
                    $palavras[] = $pad;
                }

                return [$v, $nivelM, $palavras];
            }
        }

        throw new \InvalidArgumentException('O endereço é longo demais para o QR code (máximo de 271 bytes)');
    }

    /** @param list<int> $bits */
    private function empurrar(array &$bits, int $valor, int $quantos): void
    {
        for ($i = $quantos - 1; $i >= 0; --$i) {
            $bits[] = ($valor >> $i) & 1;
        }
    }

    /**
     * @param array{0: int, 1: int, 2: int, 3: int, 4: int} $estrutura
     * @param list<int> $dados
     * @return list<int>
     */
    private function comCorrecao(int $versao, array $estrutura, array $dados): array
    {
        [$ec, $b1, $d1, $b2, $d2] = $estrutura;
        $gerador = self::gerador($ec);
        $blocosDeDados = [];
        $blocosDeEc = [];
        $pos = 0;
        for ($i = 0; $i < $b1 + $b2; ++$i) {
            $tam = $i < $b1 ? $d1 : $d2;
            $bloco = array_slice($dados, $pos, $tam);
            $pos += $tam;
            $blocosDeDados[] = $bloco;
            $blocosDeEc[] = self::resto($bloco, $gerador);
        }
        $saida = [];
        $maior = max($d1, $d2);
        for ($i = 0; $i < $maior; ++$i) {
            foreach ($blocosDeDados as $bloco) {
                if (isset($bloco[$i])) {
                    $saida[] = $bloco[$i];
                }
            }
        }
        for ($i = 0; $i < $ec; ++$i) {
            foreach ($blocosDeEc as $bloco) {
                $saida[] = $bloco[$i];
            }
        }

        return $saida;
    }

    private static function multiplicar(int $a, int $b): int
    {
        $r = 0;
        for ($i = 7; $i >= 0; --$i) {
            $r = ($r << 1) ^ (($r >> 7) * 0x11D);
            $r ^= (($b >> $i) & 1) * $a;
        }

        return $r;
    }

    /** @return array<int, int> coeficientes do polinômio gerador de grau $grau, sem o termo líder */
    private static function gerador(int $grau): array
    {
        $g = array_fill(0, $grau, 0);
        $g[$grau - 1] = 1;
        $raiz = 1;
        for ($i = 0; $i < $grau; ++$i) {
            for ($j = 0; $j < $grau; ++$j) {
                $g[$j] = self::multiplicar($g[$j], $raiz);
                if ($j + 1 < $grau) {
                    $g[$j] ^= $g[$j + 1];
                }
            }
            $raiz = self::multiplicar($raiz, 2);
        }

        return $g;
    }

    /**
     * @param array<int, int> $dados
     * @param array<int, int> $gerador
     * @return array<int, int>
     */
    private static function resto(array $dados, array $gerador): array
    {
        $r = array_fill(0, count($gerador), 0);
        foreach ($dados as $byte) {
            $fator = $byte ^ array_shift($r);
            $r[] = 0;
            foreach ($gerador as $i => $coef) {
                $r[$i] ^= self::multiplicar($coef, $fator);
            }
        }

        return $r;
    }

    private function definir(int $x, int $y, bool $escuro): void
    {
        $this->modulos[$y][$x] = $escuro;
        $this->funcao[$y][$x] = true;
    }

    private function desenharFuncoes(int $versao): void
    {
        $n = $this->tamanho;
        for ($i = 0; $i < $n; ++$i) {
            $this->definir(6, $i, $i % 2 === 0);
            $this->definir($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$n - 4, 3], [3, $n - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; ++$dy) {
                for ($dx = -4; $dx <= 4; ++$dx) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $n && $y >= 0 && $y < $n) {
                        $d = max(abs($dx), abs($dy));
                        $this->definir($x, $y, $d !== 2 && $d !== 4);
                    }
                }
            }
        }
        $pos = self::ALINHAMENTO[$versao];
        $q = count($pos);
        foreach ($pos as $i => $cy) {
            foreach ($pos as $j => $cx) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $q - 1) || ($i === $q - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; ++$dy) {
                    for ($dx = -2; $dx <= 2; ++$dx) {
                        $this->definir($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->desenharFormato(true, 0);
        if ($versao >= 7) {
            $resto = $versao;
            for ($i = 0; $i < 12; ++$i) {
                $resto = ($resto << 1) ^ (($resto >> 11) * 0x1F25);
            }
            $bits = ($versao << 12) | $resto;
            for ($i = 0; $i < 18; ++$i) {
                $b = (($bits >> $i) & 1) === 1;
                $a = $n - 11 + $i % 3;
                $c = intdiv($i, 3);
                $this->definir($a, $c, $b);
                $this->definir($c, $a, $b);
            }
        }
    }

    private function desenharFormato(bool $nivelM, int $mascara): void
    {
        $n = $this->tamanho;
        $dados = (($nivelM ? 0 : 1) << 3) | $mascara;
        $resto = $dados;
        for ($i = 0; $i < 10; ++$i) {
            $resto = ($resto << 1) ^ (($resto >> 9) * 0x537);
        }
        $bits = (($dados << 10) | $resto) ^ 0x5412;
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; ++$i) {
            $this->definir(8, $i, $bit($i));
        }
        $this->definir(8, 7, $bit(6));
        $this->definir(8, 8, $bit(7));
        $this->definir(7, 8, $bit(8));
        for ($i = 9; $i < 15; ++$i) {
            $this->definir(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; ++$i) {
            $this->definir($n - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; ++$i) {
            $this->definir(8, $n - 15 + $i, $bit($i));
        }
        $this->definir(8, $n - 8, true);
    }

    /** @param list<int> $palavras */
    private function posicionar(array $palavras): void
    {
        $n = $this->tamanho;
        $total = count($palavras) * 8;
        $i = 0;
        for ($direita = $n - 1; $direita >= 1; $direita -= 2) {
            if ($direita === 6) {
                $direita = 5;
            }
            for ($vert = 0; $vert < $n; ++$vert) {
                for ($j = 0; $j < 2; ++$j) {
                    $x = $direita - $j;
                    $y = ((($direita + 1) & 2) === 0) ? $n - 1 - $vert : $vert;
                    if (!$this->funcao[$y][$x] && $i < $total) {
                        $this->modulos[$y][$x] = (($palavras[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        ++$i;
                    }
                }
            }
        }
    }

    private function aplicarMascara(int $m): void
    {
        $n = $this->tamanho;
        for ($y = 0; $y < $n; ++$y) {
            for ($x = 0; $x < $n; ++$x) {
                if ($this->funcao[$y][$x]) {
                    continue;
                }
                $inverte = match ($m) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($inverte) {
                    $this->modulos[$y][$x] = !$this->modulos[$y][$x];
                }
            }
        }
    }

    private function penalidade(): int
    {
        $n = $this->tamanho;
        $m = $this->modulos;
        $pena = 0;
        $escuros = 0;
        for ($eixo = 0; $eixo < 2; ++$eixo) {
            for ($a = 0; $a < $n; ++$a) {
                $corrida = 1;
                $janela = 0;
                for ($b = 0; $b < $n; ++$b) {
                    $atual = $eixo === 0 ? $m[$a][$b] : $m[$b][$a];
                    if ($b > 0) {
                        $anterior = $eixo === 0 ? $m[$a][$b - 1] : $m[$b - 1][$a];
                        if ($atual === $anterior) {
                            ++$corrida;
                            if ($corrida === 5) {
                                $pena += 3;
                            } elseif ($corrida > 5) {
                                ++$pena;
                            }
                        } else {
                            $corrida = 1;
                        }
                    }
                    $janela = (($janela << 1) | ($atual ? 1 : 0)) & 0x7FF;
                    if ($b >= 10 && ($janela === 0x5D || $janela === 0x5D0)) {
                        $pena += 40;
                    }
                    if ($eixo === 0 && $atual) {
                        ++$escuros;
                    }
                }
            }
        }
        for ($y = 0; $y < $n - 1; ++$y) {
            for ($x = 0; $x < $n - 1; ++$x) {
                if ($m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                    $pena += 3;
                }
            }
        }
        $total = $n * $n;
        $pena += (int) (abs($escuros * 20 - $total * 10) / $total) * 10;

        return $pena;
    }
}
