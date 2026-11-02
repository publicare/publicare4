<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Conteudo\HtmlSeguro;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Limpeza de HTML por lista de permissões (ADR 0012 §9): nada executável sobrevive. */
final class HtmlSeguroTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function ataques(): array
    {
        return [
            'script' => ['<script>alert(1)</script>'],
            'script com tipo' => ['<script type="text/javascript">alert(1)</script>'],
            'script maiúsculo' => ['<SCRIPT SRC=//evil.example/x.js></SCRIPT>'],
            'script quebrado' => ['<scr<script>ipt>alert(1)</scr</script>ipt>'],
            'onerror' => ['<img src=x onerror=alert(1)>'],
            'onerror aspas' => ['<img src="x" onerror="alert(1)">'],
            'onclick' => ['<p onclick="alert(1)">oi</p>'],
            'onmouseover' => ['<a href="/x" onmouseover="alert(1)">x</a>'],
            'onload body' => ['<body onload=alert(1)>'],
            'svg onload' => ['<svg onload=alert(1)>'],
            'svg script' => ['<svg><script>alert(1)</script></svg>'],
            'math' => ['<math><mtext><script>alert(1)</script></mtext></math>'],
            'javascript href' => ['<a href="javascript:alert(1)">x</a>'],
            'javascript maiúsculo' => ['<a href="JaVaScRiPt:alert(1)">x</a>'],
            'javascript com tab' => ["<a href=\"java\tscript:alert(1)\">x</a>"],
            'javascript com quebra' => ["<a href=\"java\nscript:alert(1)\">x</a>"],
            'javascript com entidade' => ['<a href="&#106;avascript:alert(1)">x</a>'],
            'javascript com entidade hex' => ['<a href="&#x6A;avascript:alert(1)">x</a>'],
            'javascript com entidade sem ponto e vírgula' => ['<a href="&#106avascript:alert(1)">x</a>'],
            'javascript com espaço inicial' => ['<a href="   javascript:alert(1)">x</a>'],
            'javascript com controle inicial' => ["<a href=\"\x01javascript:alert(1)\">x</a>"],
            'javascript com nbsp' => ["<a href=\"\u{00A0}javascript:alert(1)\">x</a>"],
            'vbscript' => ['<a href="vbscript:msgbox(1)">x</a>'],
            'data html' => ['<a href="data:text/html,<script>alert(1)</script>">x</a>'],
            'data base64' => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>'],
            'data em imagem' => ['<img src="data:image/svg+xml;base64,PHN2Zz48c2NyaXB0PmFsZXJ0KDEpPC9zY3JpcHQ+PC9zdmc+">'],
            'javascript em imagem' => ['<img src="javascript:alert(1)">'],
            'file' => ['<a href="file:///etc/passwd">x</a>'],
            'iframe' => ['<iframe src="https://evil.example"></iframe>'],
            'iframe srcdoc' => ['<iframe srcdoc="<script>alert(1)</script>"></iframe>'],
            'object' => ['<object data="x.swf"></object>'],
            'embed' => ['<embed src="x.swf">'],
            'form' => ['<form action="https://evil.example"><input name=a></form>'],
            'button formaction' => ['<button formaction="javascript:alert(1)">x</button>'],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=javascript:alert(1)">'],
            'base' => ['<base href="https://evil.example/">'],
            'link' => ['<link rel="stylesheet" href="https://evil.example/x.css">'],
            'style' => ['<style>body{background:url(javascript:alert(1))}</style>'],
            'style atributo' => ['<p style="background:url(javascript:alert(1))">x</p>'],
            'style expression' => ['<p style="width:expression(alert(1))">x</p>'],
            'template' => ['<template><script>alert(1)</script></template>'],
            'noscript' => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>'],
            'comentário condicional' => ['<!--[if IE]><script>alert(1)</script><![endif]-->'],
            'comentário que fecha' => ['<!--><script>alert(1)</script>-->'],
            'cdata' => ['<![CDATA[<script>alert(1)</script>]]>'],
            'atributo com tag' => ['<img alt="<script>alert(1)</script>" src="/a.png">'],
            'aspas quebradas' => ['<img src="/a.png" alt="x" onerror="alert(1)">'],
            'sem aspas' => ['<img src=/a.png onerror=alert(1) alt=x>'],
            'barra no lugar do espaço' => ['<img/src=x/onerror=alert(1)>'],
            'tag sem fechar' => ['<img src=x onerror=alert(1)//'],
            'nulo no meio' => ["<scr\0ipt>alert(1)</scr\0ipt>"],
            'utf-8 inválido' => ["<p>\xC3\x28<script>alert(1)</script></p>"],
            'xmp' => ['<xmp><script>alert(1)</script></xmp>'],
            'plaintext' => ['<plaintext><script>alert(1)</script>'],
            'textarea' => ['<textarea></textarea><script>alert(1)</script>'],
            'mxss listing' => ['<listing>&lt;img src=x onerror=alert(1)&gt;</listing>'],
            'mxss noembed' => ['<noembed><img title="</noembed><img src=x onerror=alert(1)>"></noembed>'],
            'mxss style svg' => ['<svg></p><style><a id="</style><img src=1 onerror=alert(1)>">'],
            'class' => ['<p class="position-fixed top-0 start-0 w-100 h-100">x</p>'],
            'id' => ['<p id="x">x</p>'],
            'target' => ['<a href="https://exemplo.org" target="_blank">x</a>'],
            'srcset' => ['<img src="/a.png" srcset="javascript:alert(1) 1x">'],
            'formaction em link' => ['<a href="/x" formaction="javascript:alert(1)">x</a>'],
            'xlink' => ['<a xlink:href="javascript:alert(1)">x</a>'],
            'contenteditable' => ['<p contenteditable onfocus="alert(1)" autofocus>x</p>'],
        ];
    }

    #[DataProvider('ataques')]
    public function testNadaExecutavelSobrevive(string $ataque): void
    {
        $limpo = HtmlSeguro::limpar($ataque);
        self::assertDoesNotMatchRegularExpression('/<\s*(script|style|iframe|object|embed|form|input|button|svg|math|template|meta|link|base|xmp|noscript|textarea|body|html|head)\b/i', $limpo, $limpo);
        // Nenhum atributo de evento nem estilo, nenhum esquema perigoso, nenhuma entidade que forme um.
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $limpo, $limpo);
        self::assertDoesNotMatchRegularExpression('/\s(style|class|id|target|srcset|formaction|contenteditable|autofocus)\s*=/i', $limpo, $limpo);
        self::assertDoesNotMatchRegularExpression('/(javascript|vbscript|data|file)\s*:/i', $limpo, $limpo);
        // Só sobram marcas da lista: qualquer "<" restante abre um elemento permitido.
        preg_match_all('/<\/?([a-z0-9]+)/i', $limpo, $m);
        foreach ($m[1] as $nome) {
            self::assertContains(strtolower($nome), ['p', 'br', 'a', 'img', 'div', 'span', 'strong', 'em', 'b', 'i', 'ul', 'ol', 'li', 'table', 'tr', 'td', 'th', 'code', 'pre', 'blockquote', 'h1', 'h2', 'h3'], $limpo);
        }
        // Estável: limpar de novo não muda nada.
        self::assertSame($limpo, HtmlSeguro::limpar($limpo));
    }

    public function testMarcacaoComumPassa(): void
    {
        $entrada = '<h2>Título</h2><p>Um <strong>texto</strong> com <em>ênfase</em>, <a href="https://exemplo.org/a?x=1&amp;y=2" title="ir">link</a> '
            . 'e <a href="mailto:oi@exemplo.org">e-mail</a>, <a href="/interno#ancora">interno</a>.</p>'
            . '<ul><li>um</li><li>dois</li></ul><ol start="3"><li>três</li></ol>'
            . '<table><thead><tr><th scope="col" colspan="2">A</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>'
            . '<img src="/imagens/a.png" alt="Uma foto" width="120" height="80"><blockquote>citação</blockquote><pre><code>$x = 1;</code></pre><hr><p>linha<br>quebrada</p>';
        $limpo = HtmlSeguro::limpar($entrada);
        foreach (['<h2>Título</h2>', '<strong>texto</strong>', '<em>ênfase</em>', 'href="https://exemplo.org/a?x=1&amp;y=2"', 'title="ir"', 'href="mailto:oi@exemplo.org"', 'href="/interno#ancora"',
            '<ol start="3">', '<th scope="col" colspan="2">', '<img src="/imagens/a.png" alt="Uma foto" width="120" height="80">', '<blockquote>citação</blockquote>', '<code>$x = 1;</code>', '<hr>', 'linha<br>quebrada'] as $trecho) {
            self::assertStringContainsString($trecho, $limpo);
        }
        self::assertSame($limpo, HtmlSeguro::limpar($limpo));
    }

    public function testTodoLinkGanhaRelENaoTemTarget(): void
    {
        $limpo = HtmlSeguro::limpar('<a href="https://exemplo.org" target="_blank" rel="opener">x</a>');
        self::assertSame('<a href="https://exemplo.org" rel="noopener noreferrer">x</a>', $limpo);
    }

    public function testLinkSemEnderecoAceitoViraTextoEImagemSemOrigemSome(): void
    {
        self::assertSame('x', HtmlSeguro::limpar('<a href="javascript:alert(1)">x</a>'));
        self::assertSame('antes  depois', HtmlSeguro::limpar('antes <img src="javascript:alert(1)"> depois'));
        self::assertSame('<a href="https://ok.example/" rel="noopener noreferrer">ok</a>', HtmlSeguro::limpar('<a href="  https://ok.example/ ">ok</a>'));
    }

    public function testTextoNuNaoGanhaParagrafoETagsDesconhecidasViramTexto(): void
    {
        self::assertSame('Olá &amp; tchau', HtmlSeguro::limpar('Olá & tchau'));
        self::assertSame('<p>um dois</p>', HtmlSeguro::limpar('<p><blink>um</blink> <marquee>dois</marquee></p>'));
    }

    public function testTextoComMenorQueFicaEscapado(): void
    {
        $limpo = HtmlSeguro::limpar('<p>1 &lt; 2 &amp;&amp; 3 &gt; 2 &lt;script&gt;alert(1)&lt;/script&gt;</p>');
        self::assertStringNotContainsString('<script', $limpo);
        self::assertStringContainsString('&lt;script&gt;', $limpo);
        self::assertSame($limpo, HtmlSeguro::limpar($limpo));
    }

    public function testAtributosNumericosEEnumeradosSaoConferidos(): void
    {
        self::assertSame('<img src="/a.png">', HtmlSeguro::limpar('<img src="/a.png" width="100%" height="1e3">'));
        self::assertSame('<p dir="rtl" lang="pt-BR">x</p>', HtmlSeguro::limpar('<p dir="RTL" lang="pt-BR">x</p>'));
        self::assertSame('<p>x</p>', HtmlSeguro::limpar('<p dir="x" lang="pt br;evil">x</p>'));
    }

    public function testVazioEEntradaEnormeEInvalida(): void
    {
        self::assertSame('', HtmlSeguro::limpar(''));
        self::assertSame('', HtmlSeguro::limpar("  \n "));
        self::assertSame('', HtmlSeguro::limpar('<script>alert(1)</script>'));
        $this->expectException(\Pbl\Conteudo\ViolacaoDeRegra::class);
        HtmlSeguro::limpar(str_repeat('a', HtmlSeguro::MAX_BYTES + 1));
    }

    public function testAninhamentoMuitoFundoNaoEstoura(): void
    {
        $limpo = HtmlSeguro::limpar(str_repeat('<div>', 5000) . 'fim' . str_repeat('</div>', 5000));
        // O parser tem limite de profundidade e pode descartar o excesso; o que importa é não estourar e continuar estável.
        self::assertLessThan(5000, strlen($limpo));
        self::assertSame($limpo, HtmlSeguro::limpar($limpo));
    }

    public function testFechaInvolucroSemEscapar(): void
    {
        $limpo = HtmlSeguro::limpar('</div></body></html><script>alert(1)</script><p>depois</p>');
        self::assertStringNotContainsString('script', $limpo);
    }
}
