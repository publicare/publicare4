<?php

declare(strict_types=1);

namespace Pbl\Testes\Manual;

use PHPUnit\Framework\TestCase;

/** ADR 0039: tela ou comando novo sem linha no inventário reprova. */
final class InventarioTest extends TestCase
{
    private string $raiz;
    private string $inventario;

    protected function setUp(): void
    {
        $this->raiz = dirname(__DIR__, 2);
        $this->inventario = (string) file_get_contents($this->raiz . '/docs/usuario/INVENTARIO.md');
    }

    public function testTodoComandoDoBinPblEstaNoInventario(): void
    {
        $achou = 0;
        foreach (glob($this->raiz . '/app/src/Console/*.php') ?: [] as $f) {
            if (preg_match("/AsCommand\(name: '([a-z0-9-]+)'/", (string) file_get_contents($f), $m) === 1) {
                ++$achou;
                self::assertMatchesRegularExpression('/(^|[|\s,])' . preg_quote($m[1], '/') . '([,\s|]|$)/m', $this->inventario, "comando {$m[1]} fora do inventário");
            }
        }
        self::assertGreaterThan(20, $achou);
    }

    public function testTodaTelaDoPainelEstaNoInventario(): void
    {
        foreach (glob($this->raiz . '/app/src/Admin/TelasDe*.php') ?: [] as $f) {
            self::assertStringContainsString(basename($f, '.php'), $this->inventario, basename($f) . ' fora do inventário');
        }
    }

    public function testGuiasCitadosExistem(): void
    {
        preg_match_all('~(?<![/a-z0-9-])([a-z0-9-]+\.md)\b~', $this->inventario, $m);
        foreach (array_unique($m[1]) as $guia) {
            self::assertFileExists($this->raiz . '/docs/usuario/' . $guia, "guia {$guia} citado no inventário não existe");
        }
    }
}
