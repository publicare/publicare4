<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Linguagem\Realce;
use PHPUnit\Framework\TestCase;

/** Realce de sintaxe dos moldes no servidor (fase 5j, ADR 0012 §9): sempre escapado, nunca executa nada. */
final class RealceTest extends TestCase
{
    public function testEscapaHtmlMaliciosoDentroDeUmNomeDeTag(): void
    {
        $html = (new Realce())->html('<@</script><script>alert(1)@>');
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('&lt;/script&gt;&lt;script&gt;alert(1)', $html);
    }

    public function testEscapaHtmlMaliciosoDentroDeUmaStringDeArgumento(): void
    {
        $html = (new Realce())->html('<@incluir arquivo="</textarea><script>alert(1)</script>"@>');
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('&lt;/textarea&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('pbl-rc-string', $html);
    }

    public function testEscapaHtmlMaliciosoDentroDeUmComentario(): void
    {
        $html = (new Realce())->html('<@-- </style><script>alert(1)</script> --@>');
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('pbl-rc-comentario', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testEscapaTextoLivreEntreTags(): void
    {
        $html = (new Realce())->html('<p>" onmouseover="alert(1)</p><@eco #x@>');
        self::assertStringNotContainsString('<p>', $html);
        self::assertStringContainsString('&lt;p&gt;', $html);
        self::assertStringContainsString('pbl-rc-nome">eco</span>', $html);
    }

    public function testColoreNomeDaTagEAsStringsDosArgumentos(): void
    {
        $html = (new Realce())->html('<@se #titulo = "ok"@>x<@/se@>');
        self::assertSame(2, substr_count($html, 'pbl-rc-nome">se</span>'), 'abre e fecha, cada um com o nome em realce');
        self::assertStringContainsString('pbl-rc-string">&quot;ok&quot;</span>', $html);
        self::assertStringContainsString('&lt;@/', $html);
    }

    public function testMoldeQueNemOLexerConsegueSepararVoltaComoTextoEscapadoSemQuebrar(): void
    {
        // Tag nunca fechada (falta @>): o Lexer lançaria ErroSintaxe; o realce não propaga, só escapa tudo.
        $html = (new Realce())->html('<@se #titulo <script>alert(1)</script>');
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testPreservaComentariosQueOSepararNormalDescarta(): void
    {
        // `Lexer::separar` (usado pelo parser) descarta comentários; `separarComComentarios` (só o realce) os preserva,
        // então o texto exibido continua fiel ao molde salvo.
        $html = (new Realce())->html('a<@-- nota --@>b');
        self::assertStringContainsString('a<span class="pbl-rc-comentario">', $html);
        self::assertStringContainsString('--@&gt;</span>b', $html);
    }
}
