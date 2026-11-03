<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Admin\Pagina;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** O botão "?" de ajuda (`Pagina::ajuda`), os campos e blocos que o usam e as abas reaproveitáveis: estrutura acessível, sem JavaScript inline. */
final class PaginaAjudaTest extends TestCase
{
    public function testAjudaTemBotaoAcessivelEPainelLigados(): void
    {
        $html = Pagina::ajuda('ajuda-x', 'Nome do site', 'Texto <em>da</em> ajuda.');
        self::assertStringContainsString('<button type="button"', $html);
        self::assertStringContainsString('data-bs-toggle="collapse"', $html);
        self::assertStringContainsString('data-bs-target="#ajuda-x"', $html);
        self::assertStringContainsString('aria-controls="ajuda-x"', $html);
        self::assertStringContainsString('aria-expanded="false"', $html);
        self::assertStringContainsString('aria-label="Ajuda: Nome do site"', $html);
        self::assertStringContainsString('<span aria-hidden="true">?</span>', $html);
        self::assertStringContainsString('<div id="ajuda-x" class="collapse pbl-ajuda-painel form-text" role="note">Texto <em>da</em> ajuda.</div>', $html);
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
        self::assertStringContainsString('aria-controls="f-nome-ajuda"', $html);
        self::assertStringNotContainsString('aria-describedby', Pagina::campo('f-b', 'b', 'B'));
    }

    public function testCardEChecklistTambemUsamOBotao(): void
    {
        $card = Pagina::card('Bloco', '<p>x</p>', 'Ajuda do bloco');
        self::assertStringContainsString('<legend>Bloco <button', $card);
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
        self::assertStringNotContainsString('<details class="pbl-ajuda', $html);
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
}
