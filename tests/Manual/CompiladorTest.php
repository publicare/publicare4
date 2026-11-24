<?php

declare(strict_types=1);

namespace Pbl\Testes\Manual;

use Pbl\Manual\Compilador;
use Pbl\Manual\ErroDeManual;
use Pbl\Testes\Ajuda;
use PHPUnit\Framework\TestCase;

/** O compilador sobre um manual pequeno (fixture): pesos, sumário, anterior/próximo, e cada regra que interrompe a geração. */
final class CompiladorTest extends TestCase
{
    private string $docs;

    protected function setUp(): void
    {
        $this->docs = Ajuda::pastaTemporaria();
        file_put_contents($this->docs . '/capitulos.md', "---\ntitulo: C\nresumo: R\n---\n\n| slug | Título | Resumo |\n|---|---|---|\n| um | Capítulo um | Primeiro |\n| dois | Capítulo dois | Segundo |\n");
        $this->pagina('a', 'um', 20, "Veja [b](b.md) e [fora](https://exemplo.org/x).\n\n![Tela do painel](captura:tela)\n");
        $this->pagina('b', 'um', 10, "## Parte dois\n\ntexto\n");
        $this->pagina('c', 'dois', 10, "Sem títulos.\n");
        file_put_contents($this->docs . '/capturas.json', json_encode(['capturas' => [['id' => 'tela', 'pagina_do_painel' => '/_pbl/', 'papel' => 'admin', 'alt_padrao' => 'x']]]));
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->docs);
    }

    private function pagina(string $nome, string $capitulo, int $ordem, string $corpo, string $extra = ''): void
    {
        file_put_contents("{$this->docs}/{$nome}.md", "---\ntitulo: Página {$nome}\nresumo: Resumo {$nome}\ncapitulo: {$capitulo}\nordem: {$ordem}\n{$extra}---\n\n# Página {$nome}\n\n{$corpo}");
    }

    /** @return array<string, mixed> */
    private function compilar(bool $sem = true, ?string $capturas = null): array
    {
        return (new Compilador($this->docs, $this->docs . '/nao-existe.json', $capturas, $sem))->compilar();
    }

    private function falha(string $trecho, bool $sem = true): void
    {
        try {
            $this->compilar($sem);
            self::fail('devia falhar: ' . $trecho);
        } catch (ErroDeManual $e) {
            self::assertStringContainsString($trecho, $e->getMessage());
        }
    }

    public function testPesosDeDezEmDezSumarioEAnteriorProximo(): void
    {
        $this->pagina('a', 'um', 20, "Veja [b](b.md#parte-dois).\n\n## Parte um\n\ntexto\n\n## Parte dois\n\n### Sub\n\n![Tela do painel](captura:tela)\n");
        $p = $this->compilar();
        self::assertSame([10, 20], array_column($p['capitulos'], 'peso'));
        $um = $p['capitulos'][0]['paginas'];
        self::assertSame(['b', 'a'], array_column($um, 'slug'), 'ordem pelo front matter');
        self::assertSame([10, 20], array_column($um, 'peso'));
        self::assertStringContainsString('<a href="~ed~um/b/#parte-dois">b</a>', $um[1]['html']);
        self::assertSame('<ul><li><a href="#parte-um">Parte um</a></li><li><a href="#parte-dois">Parte dois</a><ul><li><a href="#sub">Sub</a></li></ul></li></ul>', $um[1]['toc']);
        self::assertSame('', $p['capitulos'][1]['paginas'][0]['toc'], 'sem títulos, sem sumário');
        self::assertStringNotContainsString('Anterior', $um[0]['navegacao']);
        self::assertStringContainsString('~ed~um/a/">Próximo: Página a', $um[0]['navegacao']);
        self::assertStringContainsString('~ed~dois/c/">Próximo: Página c', $um[1]['navegacao']);
        self::assertStringContainsString('~ed~um/a/">Anterior: Página a', $p['capitulos'][1]['paginas'][0]['navegacao']);
        self::assertStringNotContainsString('Próximo', $p['capitulos'][1]['paginas'][0]['navegacao']);
        self::assertStringContainsString('<figure><figcaption>Tela do painel', $um[1]['html'], 'sem captura: quadro de texto');
    }

    public function testDuasExecucoesGeramOsMesmosBytes(): void
    {
        self::assertSame(Compilador::emJson($this->compilar()), Compilador::emJson($this->compilar()));
        self::assertDoesNotMatchRegularExpression('/\b20\d\d-\d\d-\d\d\b/', Compilador::emJson($this->compilar()), 'nenhuma data na saída');
    }

    public function testImagemComArquivoViraImgComAltETamanho(): void
    {
        $pasta = Ajuda::pastaTemporaria();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
        file_put_contents($pasta . '/tela.png', $png);
        $this->pagina('b', 'um', 10, "![Tela do painel](captura:tela)\n");
        $p = $this->compilar(false, $pasta);
        Ajuda::apagar($pasta);
        self::assertStringContainsString('<img src="~img~/tela.png" alt="Tela do painel" width="1" height="1">', $p['capitulos'][0]['paginas'][0]['html']);
        self::assertSame(['tela'], $p['capturas']);
    }

    public function testRegrasQueInterrompemAGeracao(): void
    {
        $this->pagina('b', 'um', 10, "[x](nao-existe.md)\n");
        $this->falha('b.md:10: link quebrado: nao-existe.md');

        $this->pagina('b', 'um', 10, "[x](a.md#nao-existe)\n");
        $this->falha('âncora #nao-existe não existe em a.md');

        $this->pagina('b', 'um', 10, "[x](javascript:alert(1))\n");
        $this->falha('link quebrado');

        $this->pagina('b', 'um', 10, "![sem arquivo](foto.png)\n");
        $this->falha('imagens entram só por captura');

        $this->pagina('b', 'um', 10, "![](captura:tela)\n");
        $this->falha('texto alternativo');

        $this->pagina('b', 'um', 10, "![x](captura:desconhecida)\n");
        $this->falha('não declarada em capturas.json');

        file_put_contents($this->docs . '/orfa.md', "---\ntitulo: O\nresumo: R\ncapitulo: fantasma\nordem: 1\n---\n\ncorpo\n");
        $this->falha('orfa.md:1: o capítulo "fantasma" não está em capitulos.md');
        unlink($this->docs . '/orfa.md');

        $this->pagina('b', 'um', 20, "texto\n");
        $this->falha('"ordem: 20" já é de a.md');
    }

    public function testCapturaFaltandoFalhaForaDoModoDeDesenvolvimento(): void
    {
        $this->pagina('b', 'um', 10, "![Tela do painel](captura:tela)\n");
        $this->falha('faltam as capturas de tela: tela', false);
    }

    public function testCapturaDeclaradaENaoUsadaEhErro(): void
    {
        $this->pagina('a', 'um', 20, "sem imagem\n");
        $this->falha('a captura "tela" não é usada');
    }

    public function testPaginaDeContribuicaoFicaForaEQuemLinkaParaElaErra(): void
    {
        $this->pagina('contrib', 'um', 99, "texto\n", "publico: contribuicao\n");
        $p = $this->compilar();
        self::assertNotContains('contrib', array_column($p['capitulos'][0]['paginas'], 'slug'));
        $this->pagina('b', 'um', 10, "[x](contrib.md)\n");
        $this->falha('não faz parte do manual');
    }

    public function testCapituloSemPaginaEhErro(): void
    {
        unlink($this->docs . '/c.md');
        $this->falha('o capítulo "dois" não tem nenhuma página');
    }
}
