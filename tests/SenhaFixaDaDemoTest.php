<?php

declare(strict_types=1);

namespace Pbl\Testes;

use PHPUnit\Framework\TestCase;

/** Higiene (S-03): a senha fixa antiga do administrador da demo não pode voltar a nenhum arquivo versionado. */
final class SenhaFixaDaDemoTest extends TestCase
{
    public function testNenhumArquivoVersionadoTrazASenhaAntiga(): void
    {
        $valor = 'admin-demo' . '-2026'; // montado: este arquivo não contém o literal
        $raiz = dirname(__DIR__);
        $achados = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $f): bool => !in_array($f->getFilename(), ['vendor', '.git', 'dados', 'node_modules', '.vsf'], true) && !$f->isLink(),
        ));
        foreach ($it as $arquivo) {
            if ($arquivo instanceof \SplFileInfo && $arquivo->isFile() && $arquivo->getSize() < 5_000_000 && str_contains((string) file_get_contents($arquivo->getPathname()), $valor)) {
                $achados[] = substr($arquivo->getPathname(), strlen($raiz) + 1);
            }
        }
        self::assertSame([], $achados, 'a senha fixa antiga da demo voltou a estes arquivos');
    }
}
