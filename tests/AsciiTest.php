<?php

declare(strict_types=1);

namespace Pbl\Testes;

use PHPUnit\Framework\TestCase;
use Pbl\Conteudo\Ascii;

final class AsciiTest extends TestCase
{
    public function testAcentosDoPortuguesViramAscii(): void
    {
        self::assertSame('Pousada Sao Joao e coracao', Ascii::de('Pousada São João é coração'));
        self::assertSame('Acao, informacao e Orgao', Ascii::de('Ação, informação e Órgão'));
    }

    public function testNuncaEmiteAvisoNemDevolveNaoAscii(): void
    {
        set_error_handler(static function (int $n, string $m): never { throw new \ErrorException($m, 0, $n); });
        try {
            $r = Ascii::de("Cerrado \u{1F33F} — \u{00A0}“ok”");
        } finally {
            restore_error_handler();
        }
        self::assertMatchesRegularExpression('/^[\x00-\x7F]*$/', $r);
        self::assertStringContainsString('Cerrado', $r);
    }
}
