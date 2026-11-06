<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Admin\Pagina;
use PHPUnit\Framework\TestCase;

/** Botões de ação só com ícone do painel (`Pagina::botaoIcone`): rótulo preservado em texto, título e nome acessível. */
final class BotaoIconeTest extends TestCase
{
    public function testLinkComItemTemRotuloEmTodosOsLugares(): void
    {
        $h = Pagina::botaoIcone('editar', 'Editar', 'btn-primary bg-gradient', ['href' => '/x?a=1&b=2', 'item' => 'Título <b>']);
        self::assertStringStartsWith('<a class="btn btn-sm btn-primary bg-gradient pbl-btn-icone" title="Editar"', $h);
        self::assertStringContainsString('aria-label="Editar: Título &lt;b&gt;"', $h);
        self::assertStringContainsString('href="/x?a=1&amp;b=2"', $h);
        self::assertStringContainsString('<svg ', $h);
        self::assertStringContainsString('aria-hidden="true"', $h);
        self::assertStringContainsString('<span class="visually-hidden">Editar Título &lt;b&gt;</span>', $h);
    }

    public function testSemHrefViraBotaoDeEnvioEExternoAbreEmAbaNova(): void
    {
        self::assertStringStartsWith('<button type="submit" class="btn btn-sm btn-danger bg-gradient pbl-btn-icone"', Pagina::botaoIcone('excluir', 'Apagar', 'btn-danger bg-gradient'));
        $h = Pagina::botaoIcone('externo', 'Ver página pública', 'btn-outline-secondary', ['href' => '/p', 'externo' => true, 'tamanho' => '']);
        self::assertStringContainsString('target="_blank" rel="noopener"', $h);
        self::assertStringStartsWith('<a class="btn btn-outline-secondary pbl-btn-icone"', $h);
    }

    public function testTodoIconeTemCaminhoEDesconhecidoNaoDesenhaNada(): void
    {
        foreach (array_keys(Pagina::ICONES) as $nome) {
            self::assertStringContainsString('<path d="', Pagina::icone($nome), $nome);
        }
        self::assertSame('', Pagina::icone('nao-existe'));
    }
}
