<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

/** Versionamento Semântico 2.0 (docs/versionamento.md): validação e precedência. */
final class Semver
{
    private const PADRAO = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+[0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*)?$/D';

    public static function valida(string $versao): bool
    {
        return strlen($versao) <= 64 && preg_match(self::PADRAO, $versao) === 1;
    }

    public static function preLancamento(string $versao): bool
    {
        return preg_match(self::PADRAO, $versao, $m) === 1 && ($m[4] ?? '') !== '';
    }

    /** @return int -1, 0 ou 1 (a precedência do SemVer: pré-lançamento vem antes da versão final; metadados de build não contam) */
    public static function comparar(string $a, string $b): int
    {
        if (!self::valida($a) || !self::valida($b)) {
            throw new \InvalidArgumentException("Versão inválida: {$a} ou {$b}");
        }
        if (preg_match(self::PADRAO, $a, $ma) !== 1 || preg_match(self::PADRAO, $b, $mb) !== 1) {
            throw new \InvalidArgumentException('Versão inválida');
        }
        for ($i = 1; $i <= 3; $i++) {
            if ((int) $ma[$i] !== (int) $mb[$i]) {
                return (int) $ma[$i] <=> (int) $mb[$i];
            }
        }
        $pa = $ma[4] ?? '';
        $pb = $mb[4] ?? '';
        if ($pa === $pb) {
            return 0;
        }
        if ($pa === '') {
            return 1;
        }
        if ($pb === '') {
            return -1;
        }
        $ia = explode('.', $pa);
        $ib = explode('.', $pb);
        foreach ($ia as $k => $x) {
            if (!isset($ib[$k])) {
                return 1;
            }
            $y = $ib[$k];
            $nx = ctype_digit($x);
            $ny = ctype_digit($y);
            if ($nx && $ny) {
                if ((int) $x !== (int) $y) {
                    return (int) $x <=> (int) $y;
                }
            } elseif ($nx !== $ny) {
                return $nx ? -1 : 1; // identificador numérico tem precedência menor que o alfanumérico
            } elseif ($x !== $y) {
                return strcmp($x, $y) <=> 0;
            }
        }

        return count($ia) <=> count($ib);
    }
}
