<?php

declare(strict_types=1);

namespace Pbl\Testes;

/** Utilidades de teste: pasta temporária que se apaga sozinha. */
final class Ajuda
{
    public static function pastaTemporaria(): string
    {
        $pasta = sys_get_temp_dir() . '/pbl4-teste-' . bin2hex(random_bytes(6));
        mkdir($pasta, 0775, true);

        return $pasta;
    }

    public static function apagar(string $pasta): void
    {
        if (!is_dir($pasta)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pasta, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($pasta);
    }

    /** Raiz de projeto de teste com a pele básica disponível em app/recursos. */
    public static function raizDeProjeto(): string
    {
        $raiz = self::pastaTemporaria();
        mkdir($raiz . '/app/recursos/peles', 0775, true);
        $origem = dirname(__DIR__) . '/app/recursos/peles';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($origem, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $destino = $raiz . '/app/recursos/peles/' . substr($item->getPathname(), strlen($origem) + 1);
            $item->isDir() ? @mkdir($destino, 0775, true) : copy($item->getPathname(), $destino);
        }

        return $raiz;
    }
}
