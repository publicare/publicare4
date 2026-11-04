<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Caminhos;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\Site;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;

/** Feed RSS 2.0 por seção, gravado como arquivo estático na publicação (ADR 0009, adendo de 2026-11-04). */
final class FeedsTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;
    private Site $site;
    private Objeto $inicio;
    private Objeto $secao;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Meu Site', 'www.exemplo.org');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $this->site = $this->s->sites->porHost('www.exemplo.org') ?? self::fail();
        $this->inicio = $this->s->objetos->porId((int) $this->site->objetoRaizId) ?? self::fail();
        $pasta = $this->s->classes->porNome('pasta') ?? self::fail();
        $this->secao = $this->s->objetos->criar($this->site->id, $pasta, $this->inicio->id, 'Avisos', 'avisos', []);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    /** @param array<string, string|int> $dados */
    private function noticia(string $titulo, string $url, array $dados = [], string $status = 'publicado', ?string $publicacao = null, ?string $validade = null): Objeto
    {
        $classe = $this->s->classes->porNome('noticia') ?? self::fail();

        return $this->s->objetos->criar($this->site->id, $classe, $this->secao->id, $titulo, $url, $dados, $status, ['data_publicacao' => $publicacao, 'data_validade' => $validade]);
    }

    private function feed(string $secao = 'avisos'): string
    {
        $arquivo = $this->caminhos->feed($this->site->id, $secao);
        self::assertFileExists($arquivo);

        return (string) file_get_contents($arquivo);
    }

    private function xml(string $texto): \SimpleXMLElement
    {
        $x = simplexml_load_string($texto);
        self::assertInstanceOf(\SimpleXMLElement::class, $x, 'XML bem formado');

        return $x;
    }

    public function testFeedDaSecaoComItemCompleto(): void
    {
        $this->noticia('Primeira & "melhor" <b>', 'primeira', ['resumo' => 'Resumo <com> & marcas'], 'publicado', '2026-10-01 10:00:00');
        $this->s->publicador->publicarSite($this->site);

        $x = $this->xml($this->feed());
        self::assertSame('2.0', (string) $x['version']);
        self::assertSame('Avisos - Meu Site', (string) $x->channel->title);
        self::assertSame('https://www.exemplo.org/avisos', (string) $x->channel->link);
        self::assertSame('https://www.exemplo.org/avisos/feed.xml', (string) $x->channel->children('http://www.w3.org/2005/Atom')->link->attributes()['href']);
        self::assertCount(1, $x->channel->item);
        $item = $x->channel->item[0];
        self::assertSame('Primeira & "melhor" <b>', (string) $item->title);
        self::assertSame('https://www.exemplo.org/primeira', (string) $item->link);
        self::assertSame('https://www.exemplo.org/primeira', (string) $item->guid);
        self::assertSame('true', (string) $item->guid['isPermaLink']);
        self::assertSame('Resumo <com> & marcas', (string) $item->description);
        self::assertSame('Thu, 01 Oct 2026 10:00:00 +0000', (string) $item->pubDate);
        self::assertStringNotContainsString('<com>', $this->feed(), 'o texto do resumo sai escapado');
    }

    public function testDescricaoVemDoCorpoSemMarcacaoQuandoNaoHaResumo(): void
    {
        $this->noticia('Sem resumo', 'sem-resumo', ['corpo' => '<p>Primeiro <strong>parágrafo</strong>.</p><p>Segundo.</p><script>alert(1)</script>']);
        $this->s->publicador->publicarSite($this->site);

        $desc = (string) $this->xml($this->feed())->channel->item[0]->description;
        self::assertStringStartsWith('Primeiro parágrafo. Segundo.', $desc);
        self::assertStringNotContainsString('<', $desc);
    }

    public function testRascunhoAgendadoEVencidoNaoVazam(): void
    {
        $this->noticia('No ar', 'no-ar');
        $this->noticia('Rascunho secreto', 'rascunho', [], 'privado');
        $this->noticia('Privado secreto', 'privado', [], 'privado');
        $this->noticia('Agendada secreta', 'agendada', [], 'publicado', gmdate('c', time() + 86400));
        $this->noticia('Vencida secreta', 'vencida', [], 'publicado', null, gmdate('c', time() - 86400));
        $this->noticia('Nao indexar', 'noindex', ['seo_nao_indexar' => 1]);
        $this->s->publicador->publicarSite($this->site);

        $texto = $this->feed();
        self::assertStringContainsString('No ar', $texto);
        self::assertStringNotContainsString('secret', $texto);
        self::assertStringNotContainsString('Nao indexar', $texto);
        self::assertCount(1, $this->xml($texto)->channel->item);
    }

    public function testLimiteConfiguravelEOrdemDoMaisRecente(): void
    {
        foreach ([1, 2, 3, 4] as $i) {
            $this->noticia("Noticia {$i}", "n{$i}", [], 'publicado', "2026-10-0{$i} 08:00:00");
        }
        $this->s->configs->definir($this->site->id, ['rss_itens' => '2']);
        $this->s->publicador->publicarSite($this->site);

        $itens = $this->xml($this->feed())->channel->item;
        self::assertCount(2, $itens);
        self::assertSame('Noticia 4', (string) $itens[0]->title);
        self::assertSame('Noticia 3', (string) $itens[1]->title);
    }

    public function testFeedDoSiteTrazItensDeTodasAsSecoes(): void
    {
        $this->noticia('Dentro', 'dentro');
        $this->s->publicador->publicarSite($this->site);

        $x = $this->xml($this->feed(''));
        self::assertSame('Meu Site', (string) $x->channel->title);
        self::assertSame('https://www.exemplo.org/dentro', (string) $x->channel->item[0]->link);
    }

    public function testPublicarOuDespublicarItemAtualizaOFeedDaSecao(): void
    {
        $this->noticia('Antiga', 'antiga');
        $this->s->publicador->publicarSite($this->site);
        self::assertStringNotContainsString('Nova', $this->feed());

        $nova = $this->noticia('Nova', 'nova');
        $this->s->publicador->publicar($this->site, $nova);
        self::assertStringContainsString('Nova', $this->feed());
        self::assertStringContainsString('Nova', $this->feed(''), 'o feed do site também');

        $nova = $this->s->objetos->atualizar($nova->id, ['status' => 'privado']);
        $this->s->publicador->despublicar($this->site, $nova);
        self::assertStringNotContainsString('Nova', $this->feed());
        self::assertStringContainsString('Antiga', $this->feed());
    }

    public function testSecaoSemItensNaoTemFeedEDesligarRemove(): void
    {
        $this->s->publicador->publicarSite($this->site);
        self::assertFileDoesNotExist($this->caminhos->feed($this->site->id, 'avisos'));

        $this->noticia('Uma', 'uma');
        $this->s->publicador->publicarSite($this->site);
        self::assertFileExists($this->caminhos->feed($this->site->id, 'avisos'));

        $this->s->configs->definir($this->site->id, ['rss_ligado' => '0']);
        $this->s->publicador->publicarSite($this->site);
        self::assertFileDoesNotExist($this->caminhos->feed($this->site->id, 'avisos'));
        self::assertFileDoesNotExist($this->caminhos->feed($this->site->id, ''));
    }

    public function testSecaoDespublicadaPerdeOFeed(): void
    {
        $this->noticia('Uma', 'uma');
        $this->s->publicador->publicarSite($this->site);
        self::assertFileExists($this->caminhos->feed($this->site->id, 'avisos'));

        $this->s->objetos->atualizar($this->secao->id, ['status' => 'privado']);
        $this->s->publicador->publicarSite($this->site);
        self::assertFileDoesNotExist($this->caminhos->feed($this->site->id, 'avisos'));
    }

    public function testPaginaTemLinkAlternateParaOFeed(): void
    {
        $this->noticia('Uma', 'uma');
        $this->s->publicador->publicarSite($this->site);

        $html = (string) file_get_contents($this->caminhos->pagina($this->site->id, 'avisos'));
        self::assertStringContainsString('<link rel="alternate" type="application/rss+xml"', $html);
        self::assertStringContainsString('href="https://www.exemplo.org/feed.xml"', $html);
        self::assertStringContainsString('href="https://www.exemplo.org/avisos/feed.xml"', $html);

        $this->s->configs->definir($this->site->id, ['rss_ligado' => '0']);
        $this->s->publicador->publicarSite($this->site);
        self::assertStringNotContainsString('rel="alternate"', (string) file_get_contents($this->caminhos->pagina($this->site->id, 'avisos')));
    }

    public function testServidoComContentTypeDeRssESemFeedEhNaoEncontrado(): void
    {
        $this->noticia('Uma', 'uma');
        $this->s->publicador->publicarSite($this->site);
        $app = new \Pbl\Aplicacao($this->caminhos);
        $pedir = static fn (string $caminho): \Symfony\Component\HttpFoundation\Response => $app->tratar(\Symfony\Component\HttpFoundation\Request::create('https://www.exemplo.org' . $caminho, 'GET'));

        $r = $pedir('/avisos/feed.xml');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringStartsWith('application/rss+xml', (string) $r->headers->get('Content-Type'));
        self::assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('<rss', (string) $r->getContent());
        self::assertSame(200, $pedir('/feed.xml')->getStatusCode());
        self::assertSame(404, $pedir('/uma/feed.xml')->getStatusCode(), 'seção sem itens não tem feed');
        self::assertSame(404, $pedir('/nada/feed.xml')->getStatusCode());
        self::assertSame(404, $pedir('/..%2Fx/feed.xml')->getStatusCode());
    }

    public function testItensOcultosNaoEsgotamABusca(): void
    {
        $this->s->configs->definir($this->site->id, ['rss_itens' => '1']);
        $this->noticia('Elegivel', 'elegivel', [], 'publicado', '2026-09-01 08:00:00');
        foreach ([1, 2, 3, 4] as $i) {
            $this->noticia("Oculta {$i}", "oculta{$i}", ['seo_nao_indexar' => 1], 'publicado', "2026-10-0{$i} 08:00:00");
        }
        $this->s->publicador->publicarSite($this->site);

        self::assertStringContainsString('Elegivel', $this->feed());
    }

    public function testPaginaDaSecaoGanhaOLinkQuandoOFeedPassaAExistir(): void
    {
        $pasta = $this->s->classes->porNome('pasta') ?? self::fail();
        $extras = $this->s->objetos->criar($this->site->id, $pasta, $this->inicio->id, 'Extras', 'extras', []);
        $this->s->publicador->publicarSite($this->site);
        $pagina = $this->caminhos->pagina($this->site->id, 'extras');
        self::assertStringNotContainsString('extras/feed.xml', (string) file_get_contents($pagina));

        $classe = $this->s->classes->porNome('noticia') ?? self::fail();
        $nova = $this->s->objetos->criar($this->site->id, $classe, $extras->id, 'Primeira', 'primeira-extra', []);
        $this->s->publicador->publicar($this->site, $nova);
        self::assertStringContainsString('extras/feed.xml', (string) file_get_contents($pagina));
    }

    public function testCaminhoDoFeedValidaAUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->caminhos->feed($this->site->id, '../x');
    }

    public function testConfiguracoesTemPadraoELimites(): void
    {
        self::assertTrue(\Pbl\Conteudo\ConfigDoSite::padrao('rss_ligado'));
        self::assertSame(20, \Pbl\Conteudo\ConfigDoSite::padrao('rss_itens'));
        $this->expectException(\InvalidArgumentException::class);
        \Pbl\Conteudo\ConfigDoSite::normalizar('rss_itens', '101');
    }
}
