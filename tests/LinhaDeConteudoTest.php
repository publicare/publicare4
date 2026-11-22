<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Admin\AcaoDeLinha;
use Pbl\Admin\DadosDaLinha;
use Pbl\Admin\IconesDeClasse;
use Pbl\Admin\LinhaDeConteudo;
use Pbl\Conteudo\Classe;
use PHPUnit\Framework\TestCase;

/** Componente único de linha de conteúdo (ADR 0032) e resolução do ícone por classe. */
final class LinhaDeConteudoTest extends TestCase
{
    private function classe(string $nome, bool $fixa, string $icone = ''): Classe
    {
        return new Classe(1, $nome, [], fixa: $fixa, icone: $icone);
    }

    /** @return list<AcaoDeLinha> */
    private function acoes(): array
    {
        return [
            AcaoDeLinha::link('Editar', 'editar', AcaoDeLinha::PRIMARIA, '/e'),
            AcaoDeLinha::post('Publicar', 'publicar', AcaoDeLinha::ESTADO, '/acao', '<input type="hidden" name="_csrf" value="x">', [['ids[]', '7']]),
            AcaoDeLinha::link('Abrir no site', 'externo', AcaoDeLinha::EXTERNA, 'https://x.org/a', true),
            AcaoDeLinha::link('Abrir pasta', 'abrir', AcaoDeLinha::MENU, '/p'),
            AcaoDeLinha::link('Apagar', 'excluir', AcaoDeLinha::PERIGO, '/apagar'),
            AcaoDeLinha::link('Copiar', 'copiar', AcaoDeLinha::MENU, '/c'),
        ];
    }

    public function testIconePadraoPorTipoFixoEGenerico(): void
    {
        $esperado = ['pasta' => 'pasta', 'pagina' => 'documento', 'formulario' => 'formulario', 'arquivo' => 'arquivo', 'imagem' => 'imagem', 'link' => 'link'];
        foreach ($esperado as $nome => $chave) {
            self::assertSame($chave, IconesDeClasse::resolver($this->classe($nome, true)), $nome);
        }
        self::assertSame('generico', IconesDeClasse::resolver($this->classe('acomodacao', false)));
        self::assertSame('generico', IconesDeClasse::resolver(null));
        self::assertSame('calendario', IconesDeClasse::resolver($this->classe('pagina', true, 'calendario')), 'o gravado vence o padrão');
        self::assertSame('generico', IconesDeClasse::resolver($this->classe('x', false, 'inexistente')));
        self::assertStringStartsWith('<svg', IconesDeClasse::svg('generico'));
        self::assertArrayNotHasKey('generico', IconesDeClasse::opcoes());
        self::assertFalse(IconesDeClasse::valido('generico'), 'o genérico não se grava');
    }

    public function testLinhaMostraIconeNomeEstadoEMetaSemRuido(): void
    {
        $d = new DadosDaLinha(7, 'Varanda com cadeiras', '/c/7', $this->classe('acomodacao', false), 'acomodacao', '<span class="badge">publicado</span>',
            '2026-11-22T01:36:00-03:00', '22/11 01:36', '22/11/2026 01:36', 0, 3);
        $h = LinhaDeConteudo::renderizar($d, $this->acoes());

        self::assertStringContainsString('class="pbl-icone" role="img" title="acomodacao" aria-label="Tipo: acomodacao"', $h);
        self::assertStringContainsString('<span class="pbl-lc-classe">acomodacao</span>', $h);
        self::assertStringContainsString('publicado', $h);
        self::assertStringContainsString('22/11 01:36', $h);
        self::assertStringContainsString('title="Alterado em 22/11/2026 01:36"', $h);
        self::assertStringNotContainsString('Por:', $h);
        self::assertStringNotContainsString('0 filhos', $h);
        self::assertStringNotContainsString('pbl-lc-filhos', $h);
        self::assertStringContainsString('name="ids[]" value="7" form="lote"', $h);
        self::assertStringNotContainsString('style=', $h);
        self::assertStringNotContainsString('onclick', $h);
        self::assertStringContainsString('<span class="pbl-lc-filhos">1 filho</span>', LinhaDeConteudo::renderizar(new DadosDaLinha(7, 'T', '/c', null, 'x', filhos: 1), []));
        self::assertStringContainsString('3 filhos', LinhaDeConteudo::renderizar(new DadosDaLinha(7, 'T', '/c', null, 'x', filhos: 3), []));
    }

    public function testSoTresAcoesAVistaEOrestoNoMenuComApagarNoFim(): void
    {
        $h = LinhaDeConteudo::renderizar(new DadosDaLinha(7, 'Título', '/c/7', null, 'pagina'), $this->acoes());
        [$vista, $menu] = explode('<details class="pbl-mais">', $h, 2);

        self::assertSame(3, preg_match_all('/class="pbl-lc-acao pbl-lc-acao--/', $vista));
        self::assertStringNotContainsString('Copiar', $vista);
        self::assertStringNotContainsString('Apagar', $vista);
        self::assertStringContainsString('aria-label="Mais ações: Título"', $menu);
        self::assertLessThan(strpos($menu, 'Apagar'), strpos($menu, 'Copiar'), 'Apagar vai por último');
        self::assertStringContainsString('pbl-lc-item pbl-lc-item--perigo', $menu);
        self::assertStringContainsString('target="_blank" rel="noopener"', $vista);
        self::assertStringContainsString('<input type="hidden" name="_csrf" value="x"><input type="hidden" name="ids[]" value="7">', $vista);
        self::assertSame(1, substr_count($h, '<details'), 'um só menu por linha');
    }

    public function testSemAcoesDeMenuNaoDesenhaMais(): void
    {
        $h = LinhaDeConteudo::renderizar(new DadosDaLinha(1, 'T', '/c', null, 'x'), [AcaoDeLinha::link('Editar', 'editar', AcaoDeLinha::PRIMARIA, '/e')]);
        self::assertStringNotContainsString('<details', $h);
        self::assertStringNotContainsString('pbl-lc-sel', $h);
    }

    public function testTituloENomeDaClasseSaemEscapados(): void
    {
        $d = new DadosDaLinha(1, '<img src=x onerror=alert(1)>', '/c?a=1&b="2"', $this->classe('<b>x</b>', false), '<b>x</b>', extras: ['<i>site</i>']);
        $h = LinhaDeConteudo::renderizar($d, $this->acoes());

        self::assertStringNotContainsString('<img src=x', $h);
        self::assertStringNotContainsString('<b>x</b>', $h);
        self::assertStringNotContainsString('<i>site</i>', $h);
        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $h);
        self::assertStringContainsString('href="/c?a=1&amp;b=&quot;2&quot;"', $h);
    }

    public function testDatasCurtaECompleta(): void
    {
        self::assertSame(['2026-11-22T01:36:00-03:00', '22/11 01:36', '22/11/2026 01:36'], DadosDaLinha::datas('2026-11-22T04:36:00+00:00', 'America/Sao_Paulo'));
        self::assertNull(DadosDaLinha::datas('', 'America/Sao_Paulo'));
        self::assertNull(DadosDaLinha::datas('lixo', 'America/Sao_Paulo'));
    }
}
