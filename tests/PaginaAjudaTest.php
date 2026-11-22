<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Admin\Pagina;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** O "?" de ajuda (`Pagina::ajuda`, um `<details>`), os campos e blocos que o usam e as abas reaproveitáveis: estrutura acessível, sem JavaScript inline. */
final class PaginaAjudaTest extends TestCase
{
    public function testAjudaTemBotaoAcessivelEPainelLigados(): void
    {
        $html = Pagina::ajuda('ajuda-x', 'Nome do site', 'Texto <em>da</em> ajuda.');
        // <details>/<summary>: abre e fecha sem JavaScript, com teclado (Enter/Espaço) e com estado anunciado pelo leitor de tela.
        self::assertStringStartsWith('<details class="pbl-ajuda">', $html, 'começa recolhida');
        self::assertStringContainsString('<summary aria-label="Ajuda: Nome do site"><span aria-hidden="true">?</span></summary>', $html);
        self::assertStringContainsString('<div id="ajuda-x" class="pbl-ajuda-texto" role="note">Texto <em>da</em> ajuda.</div>', $html);
        self::assertStringNotContainsString('data-bs-toggle', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertSame('', Pagina::ajuda('ajuda-y', 'Vazia', ''));
    }

    public function testAssuntoEIdSaemEscapados(): void
    {
        $html = Pagina::ajuda('a"b', 'x" onfocus="y', 'ok');
        self::assertStringNotContainsString('onfocus="y', $html);
        self::assertStringContainsString('&quot;', $html);
    }

    public function testCampoAssociaRotuloAjudaEDescricao(): void
    {
        $html = Pagina::campo('f-nome', 'nome', 'Nome', 'v', ['ajuda' => 'Explica.']);
        self::assertStringContainsString('<label for="f-nome"', $html);
        self::assertStringContainsString('id="f-nome"', $html);
        self::assertStringContainsString('aria-describedby="f-nome-ajuda"', $html);
        self::assertStringContainsString('id="f-nome-ajuda"', $html);
        self::assertStringContainsString('<details class="pbl-ajuda"><summary aria-label="Ajuda: Nome">', $html);
        self::assertStringNotContainsString('aria-describedby', Pagina::campo('f-b', 'b', 'B'));
    }

    public function testCardEChecklistTambemUsamOBotao(): void
    {
        $card = Pagina::card('Bloco', '<p>x</p>', 'Ajuda do bloco');
        self::assertStringContainsString('<legend>Bloco</legend><details class="pbl-ajuda">', $card);
        self::assertStringContainsString('role="note"', $card);
        $cb = Pagina::checkbox('c1', 'c', 'Marcar', true, 'Por quê');
        self::assertStringContainsString('aria-describedby="c1-ajuda"', $cb);
        self::assertStringContainsString('id="c1-ajuda"', $cb);
    }

    public function testRespostaPoeAjudaDaTelaAoLadoDoTitulo(): void
    {
        $html = (string) Pagina::resposta('Título', '<p>corpo</p>', 200, false, '', [], '', 'Explicação longa.')->getContent();
        self::assertStringContainsString('<div class="pbl-rotulo"><h1 class="h3 mb-2">Título</h1>', $html);
        self::assertStringContainsString('id="ajuda-tela"', $html);
        self::assertSame(0, preg_match('/<script(?![^>]*\ssrc=)/', $html), 'nenhum script inline');
        self::assertStringContainsString('<details class="pbl-ajuda"><summary aria-label="Ajuda: Título">', $html, 'ajuda de tela recolhida');
    }

    public function testAbasReaproveitavelEAbaDe(): void
    {
        $html = Pagina::abas(['a' => ['Aba A', '<p>1</p>'], 'b' => ['Aba B', '<p>2</p>']], 'b', '<i>aviso</i>', 'Grupos');
        self::assertStringContainsString('role="tablist" aria-label="Grupos"', $html);
        self::assertStringContainsString('id="painel-b" class="pbl-aba-painel mb-4 active show"', $html);
        self::assertStringContainsString('aria-selected="true"', $html);
        self::assertSame(1, substr_count($html, '<i>aviso</i>'), 'aviso só no painel ativo');

        self::assertSame('a', Pagina::abaDe(Request::create('/x'), 'a', ['a', 'b']));
        self::assertSame('b', Pagina::abaDe(Request::create('/x?aba=b'), 'a', ['a', 'b']));
        self::assertSame('a', Pagina::abaDe(Request::create('/x?aba=zzz'), 'a', ['a', 'b']));
        self::assertSame('a', Pagina::abaDe(Request::create('/x?aba[]=b'), 'a', ['a', 'b']));
    }

    // ------------------------------------------------------------ o "?" pequeno (ADR 0032)

    private static function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/public/_pbl/assets/pbl-formulario.css');
    }

    /** @return array<int, string> */
    private static function achar(string $regex, string $texto, string $mensagem): array
    {
        self::assertSame(1, preg_match($regex, $texto, $m), $mensagem);

        return $m;
    }

    private static function regra(string $css, string $seletor): string
    {
        return self::achar('/^' . preg_quote($seletor, '/') . ' \{([^}]*)\}/m', $css, "regra ausente: $seletor")[1];
    }

    public function testIconeVisualPequenoEAreaDeToqueDe44PxPorPseudoElemento(): void
    {
        $css = self::css();
        $icone = self::regra($css, 'details.pbl-ajuda > summary');
        $m = self::achar('/width: ([0-9.]+)rem; height: ([0-9.]+)rem/', $icone, 'tamanho do ícone em rem');
        self::assertGreaterThanOrEqual(18, (float) $m[1] * 16, 'ícone ≥ 18 px');
        self::assertLessThanOrEqual(20, (float) $m[1] * 16, 'ícone ≤ 20 px');
        self::assertSame($m[1], $m[2], 'ícone quadrado');
        self::assertStringContainsString('position: relative', $icone);
        self::assertStringNotContainsString('min-width', $icone, 'o tamanho do ícone não pode crescer no celular');

        $toque = self::regra($css, 'details.pbl-ajuda > summary::after');
        $t = self::achar('/position: absolute; inset: -([0-9.]+)px/', $toque, 'área de toque por pseudo-elemento');
        self::assertGreaterThanOrEqual(44, (float) $m[1] * 16 + 2 * (float) $t[1], 'área de toque ≥ 44 px');
    }

    public function testAjudaAbreNaLinhaDeBaixoSemCobrirOCampo(): void
    {
        $css = self::css();
        self::assertStringNotContainsString('position: absolute', self::regra($css, 'details.pbl-ajuda > .pbl-ajuda-texto'));
        self::assertStringContainsString('flex: 0 0 100%', self::regra($css, '.pbl-rotulo > details.pbl-ajuda[open]'));
        self::assertStringContainsString('outline: var(--pbl-foco)', self::regra($css, 'details.pbl-ajuda > summary:focus-visible'));
        self::assertStringNotContainsString('pbl-ajuda-botao', $css, 'CSS legado removido');
        self::assertStringNotContainsString('style=', Pagina::ajuda('a', 'B', 'c'), 'sem estilo inline (CSP)');
    }

    public function testEscFechaAAjudaEDevolveOFoco(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/_pbl/assets/pbl-js.js');
        self::assertStringContainsString("e.key !== 'Escape'", $js);
        self::assertStringContainsString("closest('details.pbl-ajuda[open]')", $js);
        self::assertStringContainsString('botao.focus()', $js);
    }
}
