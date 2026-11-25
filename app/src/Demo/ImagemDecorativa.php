<?php

declare(strict_types=1);

namespace Pbl\Demo;

/** Arte decorativa gerada por GD (sem texto), compartilhada pela demonstração e pela carga oficial. */
final class ImagemDecorativa
{
    /**
     * PNG decorativo, nítido e sem texto (a fonte embutida do GD não tem acentos): gradiente da cor dada para uma versão mais
     * escura, mais formas translúcidas cuja posição vem do `$texto` (mesmo título, mesma imagem). Desenhada no dobro do tamanho e
     * reduzida, para as bordas ficarem suaves.
     *
     * @param array{0: int, 1: int, 2: int} $cor
     */
    public static function png(string $texto, array $cor, int $largura, int $altura): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('A extensão GD é necessária para gerar as imagens decorativas');
        }
        $largura = max(1, $largura);
        $altura = max(1, $altura);
        $esc = 2;
        $w = $largura * $esc;
        $h = $altura * $esc;
        [$r, $g, $b] = [max(0, min(255, $cor[0])), max(0, min(255, $cor[1])), max(0, min(255, $cor[2]))];
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, true);
        for ($y = 0; $y < $h; ++$y) {
            $t = $y / max(1, $h - 1);
            $f = 1.1 - 0.5 * $t;
            $c = imagecolorallocate($im, max(0, min(255, (int) ($r * $f))), max(0, min(255, (int) ($g * $f))), max(0, min(255, (int) ($b * $f))));
            if ($c !== false) {
                imageline($im, 0, $y, $w, $y, $c);
            }
        }
        $estado = crc32($texto) ?: 1;
        $prox = static function () use (&$estado): float {
            $estado = ($estado * 1103515245 + 12345) & 0x7fffffff;

            return $estado / 0x7fffffff;
        };
        for ($i = 0; $i < 5; ++$i) {
            $diametro = (int) ($altura * $esc * (0.5 + 0.9 * $prox()));
            $alfa = max(0, min(127, 95 + (int) (20 * $prox())));
            $tom = $i % 2 === 0 ? 255 : 0;
            $forma = imagecolorallocatealpha($im, $tom, $tom, $tom, $alfa);
            if ($forma !== false) {
                imagefilledellipse($im, (int) ($w * $prox()), (int) ($h * $prox()), $diametro, $diametro, $forma);
            }
        }
        $final = imagecreatetruecolor($largura, $altura);
        imagecopyresampled($final, $im, 0, 0, 0, 0, $largura, $altura, $w, $h);
        ob_start();
        imagepng($final);

        return (string) ob_get_clean();
    }
}
