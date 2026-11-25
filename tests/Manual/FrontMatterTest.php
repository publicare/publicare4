<?php

declare(strict_types=1);

namespace Pbl\Testes\Manual;

use Pbl\Manual\ErroDeManual;
use Pbl\Manual\FrontMatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrontMatterTest extends TestCase
{
    public function testLeChavesSimplesEOCorpo(): void
    {
        $r = FrontMatter::ler("---\ntitulo: Olá\nresumo: Um resumo\ncapitulo: comecar\nordem: 10\n---\n\n# Olá\ntexto", 'a.md');
        self::assertSame(['titulo' => 'Olá', 'resumo' => 'Um resumo', 'capitulo' => 'comecar', 'ordem' => 10], $r['meta']);
        self::assertStringContainsString('# Olá', $r['corpo']);
        self::assertSame(7, $r['linhaDoCorpo']);
    }

    /** @return array<string, array{string, non-empty-string}> */
    public static function invalidos(): array
    {
        return [
            'sem bloco' => ["# so texto\n", 'a.md:1'],
            'não fechado' => ["---\ntitulo: x\n", 'a.md:1'],
            'chave desconhecida' => ["---\ntitulo: x\nfoo: y\n---\n", 'a.md:3'],
            'chave repetida' => ["---\ntitulo: x\ntitulo: y\n---\n", 'a.md:3'],
            'aninhamento' => ["---\ntitulo: x\n  filho: y\n---\n", 'a.md:3'],
            'ordem não inteira' => ["---\nordem: dez\n---\n", 'a.md:2'],
            'lista' => ["---\ntitulo:\n- a\n---\n", 'a.md:2'],
        ];
    }

    /** @param non-empty-string $onde */
    #[DataProvider('invalidos')]
    public function testRejeitaComArquivoELinha(string $texto, string $onde): void
    {
        try {
            FrontMatter::ler($texto, 'a.md');
            self::fail('devia rejeitar');
        } catch (ErroDeManual $e) {
            self::assertStringStartsWith($onde, $e->getMessage());
        }
    }

    public function testValidarPaginaCompletaPadroesEConfereLimites(): void
    {
        $v = FrontMatter::validarPagina(['titulo' => 'T', 'resumo' => 'R', 'capitulo' => 'c', 'ordem' => 1, 'publico' => 'editor'], 'x.md', 'x');
        self::assertSame('x', $v['slug']);
        self::assertSame(['editor'], $v['publico']);
        foreach ([['resumo' => str_repeat('a', 201)], ['slug' => 'Ruim Slug'], ['publico' => 'talvez'], ['publico' => 'manual'], ['publico' => 'editor, editor'], ['publico' => 'editor,'], ['titulo' => '']] as $troca) {
            try {
                FrontMatter::validarPagina($troca + ['titulo' => 'T', 'resumo' => 'R', 'capitulo' => 'c', 'ordem' => 1, 'publico' => 'editor'], 'x.md', 'x');
                self::fail('devia rejeitar ' . key($troca));
            } catch (ErroDeManual) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPublicoEhObrigatorio(): void
    {
        $this->expectException(ErroDeManual::class);
        $this->expectExceptionMessage('falta "publico"');
        FrontMatter::validarPagina(['titulo' => 'T', 'resumo' => 'R', 'capitulo' => 'c', 'ordem' => 1], 'x.md', 'x');
    }

    public function testPublicoAceitaListaNaOrdemDasTrilhas(): void
    {
        $v = FrontMatter::validarPagina(['titulo' => 'T', 'resumo' => 'R', 'capitulo' => 'c', 'ordem' => 1, 'publico' => 'contribuidor,  administrador, editor'], 'x.md', 'x');
        self::assertSame(['editor', 'administrador', 'contribuidor'], $v['publico']);
    }
}
