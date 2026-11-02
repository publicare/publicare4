<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Aplicacao;
use Pbl\Acesso\Auditoria;
use Pbl\Banco\Conexao;
use Pbl\Caminhos;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\Site;
use Pbl\Instalacao\Instalador;
use Pbl\Publicacao\MenuDoSite;
use Pbl\Publicacao\MenuHtml;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Fase 5l (ADR 0016): menu por snapshot (uma consulta, invalidação por hash), busca, listagem paginada e 404 da pele. */
final class MenuEPaginasTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;
    private Site $site;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Meu Site', 'www.exemplo.org');
        $this->recarregar();
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function recarregar(): void
    {
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $this->site = $this->s->sites->porHost('www.exemplo.org') ?? self::fail();
    }

    private function obj(string $url): Objeto
    {
        return $this->s->objetos->porUrl($this->site->id, $url) ?? self::fail("sem objeto {$url}");
    }

    private function get(string $url): Response
    {
        return (new Aplicacao($this->caminhos))->tratar(Request::create("http://www.exemplo.org{$url}"));
    }

    private function publicarTudo(): void
    {
        $this->s->publicador->publicarSite($this->site);
    }

    private function arquivoDaPagina(string $url): string
    {
        return $this->caminhos->pagina($this->site->id, $url);
    }

    /** Marca o arquivo publicado para saber depois se foi regravado. */
    private function marcar(string $url): void
    {
        file_put_contents($this->arquivoDaPagina($url), '<!--marca-->', FILE_APPEND);
    }

    private function marcado(string $url): bool
    {
        return str_contains((string) file_get_contents($this->arquivoDaPagina($url)), '<!--marca-->');
    }

    private function menuHtml(string $url = ''): string
    {
        $o = $url === '' ? $this->obj('') : $this->obj($url);

        return MenuHtml::gerar($this->s->menu->arvore($this->site), 3, 'Início', $o->id, $o->caminho, $this->site->objetoRaizId);
    }

    private function auditados(): int
    {
        return (int) Conexao::sqlite($this->caminhos->banco())->fetchOne("SELECT COUNT(*) FROM auditoria WHERE acao = 'menu.invalidado'");
    }

    public function testInstaladorMarcaSobreNoticiasEContatoNoMenuPelaOrdem(): void
    {
        $html = $this->menuHtml();
        self::assertSame(1, preg_match('#Início.*Sobre.*Notícias.*Contato#s', $html));
        self::assertStringNotContainsString('Mensagens recebidas', $html, 'fora do menu e privada');
        self::assertStringNotContainsString('Bem-vindo', $html, 'no_menu desligado');
    }

    public function testArvoreDe500ObjetosSaiDeUmaConsultaEAPaginaNaoConsultaOBanco(): void
    {
        $pasta = $this->s->classes->porNome('pasta') ?? self::fail();
        $pagina = $this->s->classes->porNome('pagina') ?? self::fail();
        $raiz = (int) $this->site->objetoRaizId;
        for ($i = 1; $i <= 10; $i++) {
            $n1 = $this->s->objetos->criar($this->site->id, $pasta, $raiz, "Seção {$i}", "secao-{$i}", [], 'publicado', ['peso' => 100 + $i, 'no_menu' => true]);
            for ($j = 1; $j <= 5; $j++) {
                $n2 = $this->s->objetos->criar($this->site->id, $pasta, $n1->id, "Sub {$i}.{$j}", "sub-{$i}-{$j}", [], 'publicado', ['peso' => $j, 'no_menu' => true]);
                for ($k = 1; $k <= 9; $k++) {
                    $this->s->objetos->criar($this->site->id, $pagina, $n2->id, "Folha {$i}.{$j}.{$k}", "folha-{$i}-{$j}-{$k}", [], 'publicado', ['no_menu' => $k <= 2]);
                }
            }
        }
        self::assertGreaterThan(500, $this->s->objetos->contarDoSite($this->site->id));

        $menu = $this->s->menu;
        $antes = $menu->consultas;
        $snap = $menu->reconstruir($this->site);
        self::assertSame($antes + 1, $menu->consultas, 'a árvore inteira sai de uma consulta');
        self::assertCount(13, $snap['arvore'], 'Sobre, Notícias, Contato e as 10 seções');
        self::assertSame(0, $snap['cortes']);

        // Outra execução (outro processo): lê o snapshot do disco, sem tocar no banco.
        $this->recarregar();
        $html = MenuHtml::gerar($this->s->menu->arvore($this->site), 3, 'Início', 0, '', null);
        self::assertSame(0, $this->s->menu->consultas, 'a página só lê o snapshot');
        self::assertStringContainsString('Folha 3.2.1', $html);
        self::assertStringNotContainsString('Folha 3.2.3', $html, 'só os marcados');
    }

    public function testEditarOCorpoNaoInvalidaOMenu(): void
    {
        $this->publicarTudo();
        $this->marcar('noticias');
        $antes = $this->auditados();

        $sobre = $this->obj('sobre');
        $this->s->objetos->atualizar($sobre->id, ['dados' => ['corpo' => '<p>Outro texto</p>']]);
        $this->s->publicador->publicar($this->site, $this->obj('sobre'));

        self::assertFalse($this->s->publicador->menuPendente($this->site));
        self::assertSame($antes, $this->auditados());
        self::assertTrue($this->marcado('noticias'), 'só o objeto foi republicado');
        self::assertStringContainsString('Outro texto', (string) file_get_contents($this->arquivoDaPagina('sobre')));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function mudancasQueInvalidam(): array
    {
        return [
            'rótulo' => [['rotulo_menu' => 'Quem somos']],
            'ordem' => [['peso' => 99]],
            'tirar do menu' => [['no_menu' => false]],
            'título sem rótulo' => [['titulo' => 'Sobre nós']],
        ];
    }

    /** @param array<string, mixed> $mudanca */
    #[\PHPUnit\Framework\Attributes\DataProvider('mudancasQueInvalidam')]
    public function testMudancaNoMenuInvalidaEOPendenteEProcessado(array $mudanca): void
    {
        $this->publicarTudo();
        $this->marcar('noticias');
        $antes = $this->auditados();

        $this->s->objetos->atualizar($this->obj('sobre')->id, $mudanca);
        // O que o painel faz após salvar, mas só até marcar: a republicação do site fica para o processo de agendados.
        self::assertTrue($this->s->menu->verificar($this->site, 'teste'));
        self::assertTrue($this->s->publicador->menuPendente($this->site));
        self::assertSame($antes + 1, $this->auditados(), 'site inteiro invalidado fica na auditoria');
        self::assertTrue($this->marcado('noticias'), 'nada republicado ainda');

        $this->s->publicador->aplicarAgendados($this->site);

        self::assertFalse($this->s->publicador->menuPendente($this->site));
        self::assertFalse($this->marcado('noticias'), 'o site inteiro foi republicado');
        $home = (string) file_get_contents($this->arquivoDaPagina(''));
        if (isset($mudanca['rotulo_menu'])) {
            self::assertStringContainsString('>Quem somos</a>', $home);
        }
        if (($mudanca['no_menu'] ?? true) === false) {
            self::assertStringNotContainsString('href="/sobre"', preg_replace('#<main.*#s', '', $home) ?? '');
        }
        if (isset($mudanca['titulo'])) {
            self::assertStringContainsString('>Sobre nós</a>', $home);
        }
    }

    public function testMoverInvalidaEPublicarRepublicaOSiteSePequeno(): void
    {
        $this->publicarTudo();
        $this->marcar('contato');
        $noticias = $this->obj('noticias');
        $this->s->objetos->mover($this->obj('sobre')->id, $noticias->id);

        $this->s->publicador->publicar($this->site, $this->obj('sobre'));

        self::assertFalse($this->s->publicador->menuPendente($this->site), 'site pequeno: republicado na própria requisição');
        self::assertFalse($this->marcado('contato'));
        self::assertSame(1, preg_match('#Notícias</a><ul><li><a href="/sobre">Sobre</a>#', (string) file_get_contents($this->arquivoDaPagina(''))));
    }

    public function testItemAtivoEAncestraisEstaoMarcados(): void
    {
        $this->s->objetos->atualizar($this->obj('bem-vindo-ao-novo-site')->id, ['no_menu' => true]);
        $this->s->menu->reconstruir($this->site);

        $html = $this->menuHtml('bem-vindo-ao-novo-site');

        self::assertStringContainsString('<li class="ancestral"><a href="/noticias">Notícias</a>', $html);
        self::assertStringContainsString('<li class="ativo"><a href="/bem-vindo-ao-novo-site" aria-current="page">', $html);
        self::assertSame(1, substr_count($html, 'aria-current="page"'));
        self::assertStringContainsString('<li><a href="/sobre">Sobre</a>', $html);
        self::assertStringContainsString('<li class="ativo"><a href="/" aria-current="page">Início</a>', $this->menuHtml(''));
    }

    public function testRotulosEUrlsSaemEscapados(): void
    {
        $this->s->objetos->atualizar($this->obj('sobre')->id, ['rotulo_menu' => '<script>alert(1)</script> & "x"']);
        $this->s->menu->reconstruir($this->site);

        $html = $this->menuHtml();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;x&quot;', $html);
    }

    public function testCortePorProfundidadeEPorItens(): void
    {
        $pasta = $this->s->classes->porNome('pasta') ?? self::fail();
        $pai = (int) $this->site->objetoRaizId;
        foreach (['a', 'b', 'c', 'd'] as $n) {
            $o = $this->s->objetos->criar($this->site->id, $pasta, $pai, "Nivel {$n}", "nivel-{$n}", [], 'publicado', ['no_menu' => true]);
            $pai = $o->id;
        }
        $conexao = Conexao::sqlite($this->caminhos->banco());
        $raso = new MenuDoSite($conexao, $this->caminhos, new Auditoria($conexao), 2);
        $snap = $raso->reconstruir($this->site);
        $html = MenuHtml::gerar($snap['arvore'], $raso->profundidade(), '', 0, '', null);

        self::assertGreaterThan(0, $snap['cortes'], 'o corte é contado para o aviso no painel');
        self::assertStringContainsString('Nivel a', $html);
        self::assertStringNotContainsString('Nivel c', $html);

        $fundo = new MenuDoSite($conexao, $this->caminhos, new Auditoria($conexao), 5);
        self::assertSame(0, $fundo->reconstruir($this->site)['cortes']);

        for ($i = 1; $i <= MenuDoSite::ITENS_POR_NIVEL + 5; $i++) {
            $this->s->objetos->criar($this->site->id, $pasta, (int) $this->site->objetoRaizId, "Item {$i}", "item-{$i}", [], 'publicado', ['no_menu' => true]);
        }
        $muitos = new MenuDoSite($conexao, $this->caminhos, new Auditoria($conexao), 5);
        $snap = $muitos->reconstruir($this->site);
        self::assertCount(MenuDoSite::ITENS_POR_NIVEL, $snap['arvore']);
        self::assertGreaterThan(0, $snap['cortes']);
    }

    public function testLinkInternoParaDestinoForaDaJanelaDePublicacaoFicaForaDoMenu(): void
    {
        $link = $this->s->classes->porNome('link') ?? self::fail();
        $pagina = $this->s->classes->porNome('pagina') ?? self::fail();
        $raiz = (int) $this->site->objetoRaizId;
        $futuro = $this->s->objetos->criar($this->site->id, $pagina, $raiz, 'Futuro', 'futuro', []);
        $vencido = $this->s->objetos->criar($this->site->id, $pagina, $raiz, 'Vencido', 'vencido', []);
        $ok = $this->s->objetos->criar($this->site->id, $pagina, $raiz, 'Vigente', 'vigente', []);
        foreach ([[$futuro, 'LinkFuturo'], [$vencido, 'LinkVencido'], [$ok, 'LinkVigente']] as [$alvo, $rotulo]) {
            $this->s->objetos->criar($this->site->id, $link, $raiz, $rotulo, strtolower($rotulo), ['destino_interno' => $alvo->id], 'publicado', ['no_menu' => true]);
        }
        $con = Conexao::sqlite($this->caminhos->banco());
        $con->executeStatement('UPDATE objeto SET data_publicacao = ? WHERE id = ?', [gmdate('c', time() + 86400), $futuro->id]);
        $con->executeStatement('UPDATE objeto SET data_validade = ? WHERE id = ?', [gmdate('c', time() - 86400), $vencido->id]);
        $this->s->menu->reconstruir($this->site);

        $html = $this->menuHtml();

        self::assertStringContainsString('LinkVigente', $html);
        self::assertStringNotContainsString('LinkFuturo', $html);
        self::assertStringNotContainsString('LinkVencido', $html);
    }

    public function testMenuRespeitaNoMenuOrdemEEstado(): void
    {
        $this->s->objetos->atualizar($this->obj('contato')->id, ['peso' => 1]);
        $this->s->objetos->atualizar($this->obj('noticias')->id, ['status' => 'privado']);
        $this->s->menu->reconstruir($this->site);

        $html = $this->menuHtml();

        self::assertSame(1, preg_match('#Contato.*Sobre#s', $html), 'a ordem é o peso');
        self::assertStringNotContainsString('Notícias', $html, 'não publicado não entra');
    }

    public function testPaginaPublicadaTemMenuEBusca(): void
    {
        $this->publicarTudo();
        $html = (string) $this->get('/sobre')->getContent();

        self::assertStringContainsString('<nav class="menu"', $html);
        self::assertStringContainsString('action="/busca"', $html);
        self::assertStringContainsString('<link rel="canonical"', $html);
    }

    public function testBuscaEscapaOTermoPaginaEMostraSemResultado(): void
    {
        $noticia = $this->s->classes->porNome('noticia') ?? self::fail();
        $noticias = $this->obj('noticias');
        for ($i = 1; $i <= 23; $i++) {
            $this->s->objetos->criar($this->site->id, $noticia, $noticias->id, "Zebra número {$i}", "zebra-{$i}", ['resumo' => 'sobre zebras'], 'publicado');
        }
        $this->publicarTudo();

        $p1 = $this->get('/busca?q=zebra');
        self::assertSame(200, $p1->getStatusCode());
        $c1 = (string) $p1->getContent();
        self::assertSame(10, substr_count($c1, 'Zebra número'));
        self::assertStringContainsString('pagina=2', $c1);
        self::assertStringContainsString('q=zebra', $c1);

        $c3 = (string) $this->get('/busca?q=zebra&pagina=3')->getContent();
        self::assertSame(3, substr_count($c3, 'Zebra número'), 'a última página tem o resto');
        self::assertStringNotContainsString('pagina=4', $c3);

        $hostil = '"><script>alert(1)</script>';
        $ch = (string) $this->get('/busca?q=' . rawurlencode($hostil))->getContent();
        self::assertStringNotContainsString('<script>alert(1)', $ch);
        self::assertStringContainsString('Nada encontrado', $ch);
        self::assertStringContainsString('&lt;script&gt;', $ch);

        self::assertStringContainsString('Digite o que procura', (string) $this->get('/busca')->getContent());
        self::assertSame(200, $this->get('/busca?q[]=x&pagina=abc')->getStatusCode(), 'entrada ruim não quebra');
    }

    public function testListagemDePastaPaginaComQuery(): void
    {
        $noticia = $this->s->classes->porNome('noticia') ?? self::fail();
        $noticias = $this->obj('noticias');
        for ($i = 1; $i <= 14; $i++) {
            $this->s->objetos->criar($this->site->id, $noticia, $noticias->id, "Item de lista {$i}", "lista-{$i}", ['resumo' => 'r'], 'publicado');
        }
        $this->publicarTudo();

        $estatica = (string) $this->get('/noticias')->getContent();
        self::assertStringContainsString('pagina=2', $estatica);
        $p2 = $this->get('/noticias?pagina=2');
        self::assertSame(200, $p2->getStatusCode());
        $c2 = (string) $p2->getContent();
        self::assertSame(5, substr_count($c2, '<div class="item">'), '15 notícias: 10 na primeira página, 5 na segunda');
        self::assertStringNotContainsString('pagina=3', $c2);
        self::assertSame(200, $this->get('/noticias?pagina=99999')->getStatusCode());
    }

    public function testNaoEncontradoUsaOMoldeDaPeleEDepoisOTextoSimples(): void
    {
        $this->publicarTudo();
        $r = $this->get('/nao-existe');
        self::assertSame(404, $r->getStatusCode());
        self::assertStringContainsString('<nav class="menu"', (string) $r->getContent());

        unlink($this->caminhos->peles() . '/basica/erro404.pbl');
        $r = $this->get('/nao-existe');
        self::assertSame(404, $r->getStatusCode());
        self::assertSame('Não encontrado', trim((string) $r->getContent()));
    }
}
