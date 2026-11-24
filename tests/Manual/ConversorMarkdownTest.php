<?php

declare(strict_types=1);

namespace Pbl\Testes\Manual;

use Pbl\Manual\ConversorMarkdown;
use Pbl\Manual\ErroDeManual;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConversorMarkdownTest extends TestCase
{
    private function conversor(): ConversorMarkdown
    {
        return new ConversorMarkdown(
            static fn (string $d, string $a, int $l): string => str_starts_with($d, 'quebrado') ? throw ErroDeManual::em($a, $l, 'link quebrado') : ($d === 'outro.md' ? '~ed~c/outro/' : $d),
            static fn (string $alt, string $d, string $a, int $l): string => '<figure><img src="' . $d . '" alt="' . htmlspecialchars($alt) . '"></figure>',
        );
    }

    private function html(string $md): string
    {
        return $this->conversor()->converter($md, 'a.md')['html'];
    }

    public function testBlocosDoSubconjunto(): void
    {
        $md = <<<'MD'
# Título (descartado)

Parágrafo com **negrito**, *itálico* e `código <b>`.

## Seção

1. um
2. dois
   - filho a
   - filho b
3. três

| Coluna | Outra |
|---|---|
| a | `x \| y` |

> citação

```sh
bin/pbl <x> && y
```

Veja [outro](outro.md) e <https://exemplo.org/a>.
MD;
        $h = $this->html($md);
        self::assertStringNotContainsString('Título (descartado)', $h);
        self::assertStringContainsString('<p>Parágrafo com <strong>negrito</strong>, <em>itálico</em> e <code>código &lt;b&gt;</code>.</p>', $h);
        self::assertStringContainsString('<h2>Seção</h2>', $h);
        self::assertStringContainsString('<ol><li>um</li><li>dois<ul><li>filho a</li><li>filho b</li></ul></li><li>três</li></ol>', $h);
        self::assertStringContainsString('<th scope="col">Coluna</th>', $h);
        self::assertStringContainsString('<td><code>x | y</code></td>', $h);
        self::assertStringContainsString('<blockquote><p>citação</p></blockquote>', $h);
        self::assertStringContainsString('<pre><code>bin/pbl &lt;x&gt; &amp;&amp; y</code></pre>', $h);
        self::assertStringContainsString('<a href="~ed~c/outro/">outro</a>', $h);
        self::assertStringContainsString('<a href="https://exemplo.org/a">https://exemplo.org/a</a>', $h);
    }

    public function testTitulosGanhamIdsUnicosEEntramNoSumario(): void
    {
        $r = $this->conversor()->converter("## Cópia de segurança\n\ntexto\n\n## Cópia de segurança\n\n### Sub *item*\n\n#### Fundo\n", 'a.md');
        self::assertSame([
            ['nivel' => 2, 'texto' => 'Cópia de segurança', 'id' => 'copia-de-seguranca'],
            ['nivel' => 2, 'texto' => 'Cópia de segurança', 'id' => 'copia-de-seguranca-2'],
            ['nivel' => 3, 'texto' => 'Sub item', 'id' => 'sub-item'],
            ['nivel' => 4, 'texto' => 'Fundo', 'id' => 'fundo'],
        ], $r['titulos']);
    }

    public function testImagemSozinhaNaoFicaDentroDeParagrafo(): void
    {
        $h = $this->html("![Uma tela](captura:x)\n");
        self::assertSame('<figure><img src="captura:x" alt="Uma tela"></figure>', $h);
    }

    /** @return array<string, array{string, int}> */
    public static function foraDoSubconjunto(): array
    {
        return [
            'html cru' => ["texto\n\n<div>x</div>\n", 3],
            'script' => ["Olá <script>alert(1)</script>\n", 1],
            'html inline' => ["linha 1\nlinha <b>2</b>\n", 2],
            'segundo h1' => ["# a\n\ntexto\n\n# b\n", 5],
            'título fundo demais' => ["##### x\n", 1],
            'código aberto' => ["```\nx\n", 1],
            'tabela sem separador' => ["| a | b |\n| c | d |\n", 1],
            'tabela torta' => ["| a | b |\n|---|---|\n| c |\n", 3],
            'link quebrado' => ["[x](quebrado.md)\n", 1],
        ];
    }

    #[DataProvider('foraDoSubconjunto')]
    public function testConstrucaoForaDoSubconjuntoEhErroComLinha(string $md, int $linha): void
    {
        try {
            $this->html($md);
            self::fail('devia falhar');
        } catch (ErroDeManual $e) {
            self::assertStringStartsWith('a.md:' . $linha . ':', $e->getMessage());
        }
    }

    public function testNadaPerigosoPassaSemEscape(): void
    {
        $h = $this->html("Texto & \"aspas\" e 3 > 2 e `<img onerror=x>`\n\n[js](javascript:alert(1))\n");
        self::assertStringContainsString('3 &gt; 2', $h);
        self::assertStringContainsString('<code>&lt;img onerror=x&gt;</code>', $h);
        self::assertStringNotContainsString('<img', $h);
    }
}
