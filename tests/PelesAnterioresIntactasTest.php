<?php

declare(strict_types=1);

namespace Pbl\Testes;

use PHPUnit\Framework\TestCase;

/**
 * A pele `publicare` é nova: a `basica`, a `onepage` e a `portal` ficam byte a byte como estavam (sites existentes não mudam, e a
 * migração não gera avisos de "personalizado"). `tests/fixtures/peles-anteriores.sha256` guarda o SHA-256 de cada arquivo (formato
 * `sha256sum`). Se mudar uma dessas peles de propósito, atualize a lista e registre o hash antigo em `anteriores.json`.
 */
final class PelesAnterioresIntactasTest extends TestCase
{
    public function testArquivosDasPelesAnterioresNaoMudaram(): void
    {
        $base = dirname(__DIR__) . '/app/recursos/peles';
        $esperado = [];
        foreach (file(__DIR__ . '/fixtures/peles-anteriores.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
            [$hash, $arquivo] = explode('  ', $linha, 2);
            $esperado[$arquivo] = $hash;
        }
        self::assertNotSame([], $esperado);
        $atual = [];
        foreach (['basica', 'onepage', 'portal'] as $pele) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$base}/{$pele}", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $atual[$pele . substr($f->getPathname(), strlen("{$base}/{$pele}"))] = hash_file('sha256', $f->getPathname());
                }
            }
        }
        ksort($atual);
        ksort($esperado);
        self::assertSame($esperado, $atual);
    }
}
