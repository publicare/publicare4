<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Admin\CodigoQr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * O gerador de QR em PHP puro (ADR 0026). As impressões digitais (SHA-1 das linhas "0"/"1" unidas por quebra de linha) vêm da biblioteca de
 * referência `qrcode` (Python) com a mesma máscara forçada, sem margem: cobrem versões 2 (um bloco), 4 (blocos múltiplos), 8 e 9
 * (informação de versão, blocos de tamanhos diferentes, nível L).
 */
final class CodigoQrTest extends TestCase
{
    /** @return iterable<string, array{string, int, string}> */
    public static function referencias(): iterable
    {
        yield 'v2 M' => ['https://exemplo.org/a', 3, '0f6c7cb715329b4f91a2a901e7d20df21dd80202'];
        yield 'v4 M' => ['https://www.exemplo.org/secao/noticia-de-teste', 5, 'fc8074f2ec8b7e8948536f5fe208322dba189845'];
        yield 'v8 M' => ['https://www.exemplo.org/' . str_repeat('x', 120) . '/fim', 1, 'cd7cdca88caff25969f613d634f48c6d91a6a28d'];
        yield 'v9 L' => ['https://www.exemplo.org/' . str_repeat('y', 190) . '/fim', 6, '8bb2c5752b6408e952b048397b0f6a3485b2ee2e'];
    }

    #[DataProvider('referencias')]
    public function testIgualASaidaDaBibliotecaDeReferencia(string $texto, int $mascara, string $sha1): void
    {
        $linhas = array_map(static fn (array $l): string => implode('', array_map(static fn (bool $b): string => $b ? '1' : '0', $l)), CodigoQr::matriz($texto, $mascara));

        self::assertSame($sha1, sha1(implode("\n", $linhas)));
    }

    public function testSvgTemMargemEUmSoCaminho(): void
    {
        $svg = CodigoQr::svg('https://exemplo.org/a');

        self::assertStringStartsWith('<svg ', $svg);
        self::assertStringContainsString('viewBox="0 0 33 33"', $svg, '25 módulos + margem de 4 de cada lado');
        self::assertSame(1, substr_count($svg, '<path'));
        self::assertStringNotContainsString('<script', $svg);
        self::assertStringNotContainsString('http://www.w3.org/1999/xlink', $svg);
    }

    public function testEscolheAMascaraSozinhaEEhDeterministico(): void
    {
        self::assertSame(CodigoQr::matriz('https://exemplo.org/'), CodigoQr::matriz('https://exemplo.org/'));
    }

    public function testRecusaTextoGrandeDemais(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CodigoQr::svg(str_repeat('a', 272));
    }
}
