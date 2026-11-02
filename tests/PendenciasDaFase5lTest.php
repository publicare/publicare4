<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Conteudo\Objeto;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pendências da 5l (fase 5l2): sitemap.xml e robots.txt, endereço absoluto em canonical/og:*, avisos e republicação em lotes
 * no painel, menu no histórico de versões e a tela de objetos para `no_menu`/`rotulo_menu`.
 */
final class PendenciasDaFase5lTest extends TestCase
{
    use AdminAuxiliar;

    private function obj(string $url): Objeto
    {
        return $this->s->objetos->porUrl($this->siteId, $url) ?? self::fail("sem objeto {$url}");
    }

    private function publico(string $caminho, string $host = 'www.exemplo.org'): Response
    {
        return (new \Pbl\Aplicacao($this->caminhos))->tratar(\Symfony\Component\HttpFoundation\Request::create("https://{$host}{$caminho}"));
    }

    private function publicarTudo(): void
    {
        $this->s->publicador->publicarSite($this->s->sites->porId($this->siteId) ?? self::fail());
    }

    /** @param array<string, string|int|float|bool> $dados */
    private function pagina(string $titulo, string $url, string $status = 'publicado', array $dados = []): Objeto
    {
        $classe = $this->s->classes->porNome('pagina') ?? self::fail();

        return $this->s->objetos->criar($this->siteId, $classe, $this->inicioId, $titulo, $url, $dados, $status);
    }

    public function testRobotsApontaOSitemapEBloqueiaAreasInternas(): void
    {
        $r = $this->publico('/robots.txt');

        self::assertSame(200, $r->getStatusCode());
        self::assertStringStartsWith('text/plain', (string) $r->headers->get('Content-Type'));
        $txt = $this->html($r);
        self::assertStringContainsString("User-agent: *\n", $txt);
        self::assertStringContainsString("Disallow: /_pbl/\n", $txt);
        self::assertStringContainsString("Disallow: /dados/\n", $txt);
        self::assertStringContainsString("Sitemap: https://www.exemplo.org/sitemap.xml\n", $txt);
    }

    public function testSitemapListaSoOQueEPublicoComHostDoDominioPrincipal(): void
    {
        $this->pagina('Rascunho', 'rascunho', 'privado');
        $this->pagina('Escondida', 'escondida', 'publicado', ['seo_nao_indexar' => 1]);
        $vencida = $this->pagina('Vencida', 'vencida');
        $this->s->objetos->atualizar($vencida->id, ['data_validade' => '2000-01-01T00:00:00+00:00']);
        $futura = $this->pagina('Futura', 'futura');
        $this->s->objetos->atualizar($futura->id, ['data_publicacao' => '2999-01-01T00:00:00+00:00']);
        $this->pagina('Visivel & cia', 'visivel');
        $link = $this->s->classes->porNome('link') ?? self::fail();
        $this->s->objetos->criar($this->siteId, $link, $this->inicioId, 'Externo', 'externo', ['destino_externo' => 'https://exemplo.com/'], 'publicado');
        $this->s->dominios->adicionar($this->siteId, 'alias.exemplo.org', 'servir');

        $r = $this->publico('/sitemap.xml', 'alias.exemplo.org'); // pedido por um alias: o host do XML continua o principal

        self::assertSame(200, $r->getStatusCode());
        self::assertStringStartsWith('application/xml', (string) $r->headers->get('Content-Type'));
        $xml = $this->html($r);
        self::assertNotFalse(simplexml_load_string($xml), 'XML bem formado');
        self::assertStringContainsString('<loc>https://www.exemplo.org/sobre</loc>', $xml);
        self::assertStringContainsString('<loc>https://www.exemplo.org/visivel</loc>', $xml);
        self::assertStringContainsString('<loc>https://www.exemplo.org/</loc>', $xml, 'a página inicial');
        self::assertStringNotContainsString('alias.exemplo.org', $xml);
        foreach (['rascunho', 'escondida', 'vencida', 'futura', 'externo'] as $fora) {
            self::assertStringNotContainsString("/{$fora}<", $xml, $fora);
        }
        self::assertMatchesRegularExpression('#<lastmod>\d{4}-\d{2}-\d{2}</lastmod>#', $xml);
    }

    public function testSitemapViraIndiceQuandoPassaDoLimiteSemPerderUrls(): void
    {
        $site = $this->s->sites->porId($this->siteId) ?? self::fail();
        $inteiro = (new \Pbl\Publicacao\SitemapERobots($this->s))->tratar($site, '/sitemap.xml') ?? self::fail();
        preg_match_all('#<loc>([^<]+)</loc>#', $this->html($inteiro), $todas);
        self::assertGreaterThan(3, count($todas[1]));
        self::assertStringContainsString('<urlset', $this->html($inteiro));
        self::assertNull((new \Pbl\Publicacao\SitemapERobots($this->s))->tratar($site, '/sitemap-1.xml'), 'sem índice não há sitemap-N');

        $menor = new \Pbl\Publicacao\SitemapERobots($this->s, 2);
        $indice = $this->html($menor->tratar($site, '/sitemap.xml') ?? self::fail());
        self::assertStringContainsString('<sitemapindex', $indice);
        preg_match_all('#<loc>(https://www\.exemplo\.org/sitemap-\d+\.xml)</loc>#', $indice, $partes);
        self::assertCount((int) ceil(count($todas[1]) / 2), $partes[1]);

        $juntas = [];
        foreach ($partes[1] as $i => $_) {
            $xml = $this->html($menor->tratar($site, '/sitemap-' . ($i + 1) . '.xml') ?? self::fail());
            preg_match_all('#<loc>([^<]+)</loc>#', $xml, $m);
            self::assertLessThanOrEqual(2, count($m[1]));
            $juntas = array_merge($juntas, $m[1]);
        }
        self::assertSame($todas[1], $juntas);
        self::assertNull($menor->tratar($site, '/sitemap-' . (count($partes[1]) + 1) . '.xml'));
        self::assertTrue(\Pbl\Publicacao\SitemapERobots::atende('/sitemap-12.xml'));
        self::assertFalse(\Pbl\Publicacao\SitemapERobots::atende('/sitemap-0.xml'));
    }

    public function testSitemapERobotsNaoCaemEmPaginaPublicadaNemEmMetodoDiferente(): void
    {
        self::assertSame(404, (new \Pbl\Aplicacao($this->caminhos))->tratar(\Symfony\Component\HttpFoundation\Request::create('https://www.exemplo.org/sitemap.xml', 'POST'))->getStatusCode());
        self::assertSame(404, $this->publico('/outro.xml')->getStatusCode());
    }

    public function testCanonicalEOgSaemAbsolutosComODominioPrincipal(): void
    {
        $this->publicarTudo();

        $html = (string) file_get_contents($this->caminhos->pagina($this->siteId, 'sobre'));

        self::assertStringContainsString('<link rel="canonical" href="https://www.exemplo.org/sobre">', $html);
        self::assertStringContainsString('<meta property="og:url" content="https://www.exemplo.org/sobre">', $html);
        $home = (string) file_get_contents($this->caminhos->pagina($this->siteId, ''));
        self::assertStringContainsString('<link rel="canonical" href="https://www.exemplo.org/">', $home);
    }

    public function testTrocarODominioPrincipalRepublicaOsEnderecos(): void
    {
        $this->publicarTudo();
        $token = $this->token('admin');
        $this->s->dominios->adicionar($this->siteId, 'novo.exemplo.org', 'servir');

        $r = $this->post($token, "/_pbl/site/{$this->siteId}/dominios/principal", ['host' => 'novo.exemplo.org', 'antigo' => 'servir']);

        self::assertSame(303, $r->getStatusCode(), $this->html($r));
        $html = (string) file_get_contents($this->caminhos->pagina($this->siteId, 'sobre'));
        self::assertStringContainsString('<link rel="canonical" href="https://novo.exemplo.org/sobre">', $html);
        self::assertStringContainsString('https://novo.exemplo.org/sitemap.xml', $this->html($this->publico('/robots.txt', 'novo.exemplo.org')));
    }

    public function testRepublicarEmLotesTerminaLimpaOPendenteERemoveOrfas(): void
    {
        $site = $this->s->sites->porId($this->siteId) ?? self::fail();
        $this->publicarTudo();
        $orfa = $this->caminhos->pagina($this->siteId, 'orfa');
        file_put_contents($orfa, 'x');
        $this->s->menu->marcarPendente($this->siteId);
        $total = $this->s->objetos->contarDoSite($this->siteId);

        $depois = 0;
        $tratadas = 0;
        $chamadas = 0;
        do {
            $r = $this->s->publicador->republicarLote($site, $depois, 2);
            $tratadas += $r['processadas'];
            $depois = $r['ultimo'];
            $chamadas++;
            self::assertLessThan(50, $chamadas);
            if (!$r['concluido']) {
                self::assertTrue($this->s->publicador->menuPendente($site), 'pendente até o último lote');
            }
        } while (!$r['concluido']);

        self::assertSame($total, $tratadas);
        self::assertGreaterThan(1, $chamadas, 'foi em mais de um lote');
        self::assertFalse($this->s->publicador->menuPendente($site));
        self::assertFileDoesNotExist($orfa);
        self::assertFileExists($this->caminhos->pagina($this->siteId, 'sobre'));
    }

    public function testPainelAvisaMenuPendenteECortadoERepublicaAgora(): void
    {
        $site = $this->s->sites->porId($this->siteId) ?? self::fail();
        $this->publicarTudo();
        $token = $this->token('admin');
        $url = "/_pbl/site/{$this->siteId}/publicacao";

        $limpo = $this->html($this->req('GET', $url, $token));
        self::assertStringNotContainsString('alert-warning', $limpo);

        $this->s->menu->marcarPendente($this->siteId);
        $pasta = $this->s->classes->porNome('pasta') ?? self::fail();
        for ($i = 1; $i <= \Pbl\Publicacao\MenuDoSite::ITENS_POR_NIVEL + 2; $i++) {
            $this->s->objetos->criar($this->siteId, $pasta, $this->inicioId, "Item {$i}", "item-{$i}", [], 'publicado', ['no_menu' => true]);
        }
        $this->s->menu->reconstruir($site);
        $this->s->menu->marcarPendente($this->siteId);

        $aviso = $this->html($this->req('GET', $url, $token));
        self::assertStringContainsString('O menu foi cortado', $aviso);
        self::assertStringContainsString('ainda mostram a versão antiga', $aviso);
        self::assertStringContainsString('Menu e publicação', $this->html($this->req('GET', '/_pbl/', $token)));

        $r = $this->post($token, $url, ['depois' => '0']);
        self::assertSame(303, $r->getStatusCode(), $this->html($r));
        self::assertSame($url . '?ok=republicado', $r->headers->get('Location'));
        self::assertFalse($this->s->publicador->menuPendente($site));
        self::assertCount(1, $this->auditoria('site.republicar'));
        self::assertStringContainsString('Site republicado', $this->html($this->req('GET', $url . '?ok=republicado', $token)));
    }

    public function testPainelContinuaOLoteQuandoHaMaisObjetos(): void
    {
        $pasta = $this->s->classes->porNome('pasta') ?? self::fail();
        for ($i = 1; $i <= 105; $i++) {
            $this->s->objetos->criar($this->siteId, $pasta, $this->inicioId, "Pasta {$i}", "pasta-{$i}", [], 'publicado');
        }
        $token = $this->token('admin');
        $url = "/_pbl/site/{$this->siteId}/publicacao";

        $r = $this->post($token, $url, ['depois' => '0']);
        self::assertSame(200, $r->getStatusCode());
        $html = $this->html($r);
        self::assertStringContainsString('Continuar', $html);
        if (preg_match('#name="depois" value="(\d+)"><input type="hidden" name="feitas" value="(\d+)"#', $html, $m) !== 1) {
            self::fail('sem o formulário de continuação');
        }
        self::assertGreaterThan(0, (int) $m[1]);

        $fim = $this->post($token, $url, ['depois' => $m[1], 'feitas' => $m[2]]);
        self::assertSame(303, $fim->getStatusCode(), $this->html($fim));
        self::assertFileExists($this->caminhos->pagina($this->siteId, 'pasta-105'));
    }

    public function testPainelDePublicacaoExigeAdministradorECsrf(): void
    {
        $url = "/_pbl/site/{$this->siteId}/publicacao";
        self::assertSame(403, $this->req('GET', $url, $this->token('editor'))->getStatusCode());
        self::assertSame(303, $this->req('GET', $url)->getStatusCode(), 'sem sessão vai para entrar');
        self::assertSame(403, $this->post($this->token('admin'), $url, ['depois' => '0'], false)->getStatusCode(), 'sem CSRF');
        self::assertSame(404, $this->req('GET', $url . '/x', $this->token('admin'))->getStatusCode());
    }

    public function testMenuFicaNoHistoricoDeVersoesEVoltaAoRestaurar(): void
    {
        $o = $this->pagina('Contato novo', 'contato-novo');
        $o = $this->s->objetos->atualizar($o->id, ['no_menu' => true, 'rotulo_menu' => 'Fale conosco']);
        $o = $this->s->objetos->atualizar($o->id, ['no_menu' => false, 'rotulo_menu' => 'Outro']);
        $v = $this->s->objetos->versoes($o->id);

        $linha = $this->s->conexao()->fetchOne('SELECT dados FROM objeto_versao WHERE objeto_id = ? AND versao = 2', [$o->id]);
        self::assertSame(['no_menu' => 1, 'rotulo_menu' => 'Fale conosco'], json_decode((string) $linha, true)['_menu']);
        self::assertCount(3, $v);

        $r = $this->s->objetos->restaurar($o->id, 2);

        self::assertTrue($r->noMenu);
        self::assertSame('Fale conosco', $r->rotuloMenu);
        self::assertArrayNotHasKey('_menu', $r->dados, 'a chave do histórico não vaza para o objeto');
    }

    public function testVersaoAntigaSemMenuNaoMexeNoMenuAoRestaurar(): void
    {
        $o = $this->pagina('Antigo', 'antigo');
        $o = $this->s->objetos->atualizar($o->id, ['no_menu' => true, 'rotulo_menu' => 'Fica']);
        $o = $this->s->objetos->atualizar($o->id, ['titulo' => 'Antigo 2']);
        $this->s->conexao()->executeStatement("UPDATE objeto_versao SET dados = '{}' WHERE objeto_id = ? AND versao = 1", [$o->id]);

        $r = $this->s->objetos->restaurar($o->id, 1);

        self::assertSame('Antigo', $r->titulo);
        self::assertTrue($r->noMenu, 'versão de antes do menu ser versionado: o menu atual fica como está');
        self::assertSame('Fica', $r->rotuloMenu);
    }

    public function testTelaDeObjetosEditaNoMenuERotuloEMostraNaCriacaoENaEdicao(): void
    {
        $token = $this->token('admin');
        $sobre = $this->obj('sobre');

        $form = $this->html($this->req('GET', "/_pbl/objeto/{$sobre->id}/editar", $token));
        self::assertStringContainsString('name="no_menu"', $form);
        self::assertStringContainsString('name="rotulo_menu"', $form);
        $novo = $this->html($this->req('GET', "/_pbl/objeto/{$sobre->id}/novo?classe=" . ($this->s->classes->porNome('pagina') ?? self::fail())->id, $token));
        self::assertStringContainsString('name="no_menu"', $novo);
        $raiz = $this->html($this->req('GET', "/_pbl/objeto/{$this->inicioId}/editar", $token));
        self::assertStringNotContainsString('name="rotulo_menu"', $raiz, 'a página inicial não entra no menu');

        $base = ['versao' => (string) $sobre->versao, 'titulo' => 'Sobre', 'descricao' => '', 'url' => 'sobre', 'peso' => '0', 'dados' => []];
        $r = $this->post($token, "/_pbl/objeto/{$sobre->id}/editar", $base + ['no_menu' => '0', 'rotulo_menu' => 'Quem <somos>']);
        self::assertSame(303, $r->getStatusCode(), $this->html($r));
        $depois = $this->obj('sobre');
        self::assertFalse($depois->noMenu);
        self::assertSame('Quem <somos>', $depois->rotuloMenu);
        $editar = $this->html($this->req('GET', "/_pbl/objeto/{$sobre->id}/editar", $token));
        self::assertStringContainsString('value="Quem &lt;somos&gt;"', $editar, 'o rótulo volta escapado');

        $r = $this->post($token, "/_pbl/objeto/{$sobre->id}/editar", ['versao' => (string) $depois->versao] + $base + ['no_menu' => '1', 'rotulo_menu' => str_repeat('x', 61)]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('pode ter até', $this->html($r));
        self::assertFalse($this->obj('sobre')->noMenu, 'nada gravado quando o rótulo é inválido');

        $r = $this->post($token, "/_pbl/objeto/{$sobre->id}/editar", ['versao' => (string) $depois->versao] + $base + ['no_menu' => '1', 'rotulo_menu' => '']);
        self::assertSame(303, $r->getStatusCode(), $this->html($r));
        $final = $this->obj('sobre');
        self::assertTrue($final->noMenu);
        self::assertSame('', $final->rotuloMenu, 'vazio volta a usar o título');
        $menu = $this->s->menu->arvore($this->s->sites->porId($this->siteId) ?? self::fail());
        self::assertContains('Sobre', array_column($menu, 'rotulo'));
    }

    public function testCriarPeloPainelComNoMenuEntraNoMenu(): void
    {
        $token = $this->token('admin');
        $classe = $this->s->classes->porNome('pagina') ?? self::fail();

        $r = $this->post($token, "/_pbl/objeto/{$this->inicioId}/novo", [
            'classe' => (string) $classe->id, 'titulo' => 'Equipe', 'descricao' => '', 'url' => '', 'peso' => '0', 'dados' => [],
            'no_menu' => '1', 'rotulo_menu' => 'Nossa equipe',
        ]);

        self::assertSame(303, $r->getStatusCode(), $this->html($r));
        $criado = $this->obj('equipe');
        self::assertTrue($criado->noMenu);
        self::assertSame('Nossa equipe', $criado->rotuloMenu);
        $linha = $this->s->conexao()->fetchOne('SELECT dados FROM objeto_versao WHERE objeto_id = ? AND versao = 1', [$criado->id]);
        self::assertSame('Nossa equipe', json_decode((string) $linha, true)['_menu']['rotulo_menu']);
    }
}
