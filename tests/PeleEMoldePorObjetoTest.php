<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Conteudo\Objeto;
use Pbl\Instalacao\PelesDistribuidas;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** ADR 0022: pele e molde por objeto (herança), peles distribuídas, assets, API da barra de edição e "Gravar e publicar". */
final class PeleEMoldePorObjetoTest extends TestCase
{
    use AdminAuxiliar;

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $dados
     */
    private function criar(string $classe, ?int $pai, string $titulo, string $url, array $meta = [], array $dados = []): Objeto
    {
        $c = $this->s->classes->porNome($classe) ?? self::fail("sem classe {$classe}");

        return $this->s->objetos->criar($this->siteId, $c, $pai, $titulo, $url, $dados + ['corpo' => '<p>' . $titulo . '</p>'], 'publicado', $meta);
    }

    private function site(): \Pbl\Conteudo\Site
    {
        return $this->s->sites->porId($this->siteId) ?? self::fail();
    }

    private function sincronizarPeles(): void
    {
        (new PelesDistribuidas($this->caminhos))->sincronizar();
    }

    public function testPeleHerdadaDoAncestralMaisProximo(): void
    {
        $this->sincronizarPeles();
        $a = $this->criar('pagina', $this->inicioId, 'A', 'a', ['pele' => 'onepage']);
        $b = $this->criar('pagina', $a->id, 'B', 'b');
        $c = $this->criar('pagina', $b->id, 'C', 'c', ['pele' => 'portal']);
        $d = $this->criar('pagina', $c->id, 'D', 'd');
        $livre = $this->criar('pagina', $this->inicioId, 'Livre', 'livre');
        $ap = $this->s->aparencia;

        self::assertSame(['pele' => 'onepage', 'raiz' => $a->id], $ap->da($this->site(), $b));
        self::assertSame(['pele' => 'portal', 'raiz' => $c->id], $ap->da($this->site(), $d));
        self::assertSame(['pele' => 'portal', 'raiz' => $c->id], $ap->da($this->site(), $c));
        self::assertSame($this->site()->pele, $ap->da($this->site(), $livre)['pele']);
        self::assertNull($ap->da($this->site(), $livre)['raiz']);
    }

    public function testPeleSemPastaEmDiscoEIgnorada(): void
    {
        $a = $this->criar('pagina', $this->inicioId, 'A', 'a', ['pele' => 'inexistente']);
        self::assertNull($this->s->aparencia->da($this->site(), $a)['raiz']);
    }

    public function testInstalaTodasAsPeles(): void
    {
        $this->sincronizarPeles();
        foreach (['basica', 'onepage', 'portal'] as $p) {
            self::assertFileExists($this->caminhos->peles() . "/{$p}/padrao.pbl");
        }
        self::assertFileExists($this->caminhos->peles() . '/basica/home.pbl');
        self::assertFileExists($this->caminhos->peles() . '/onepage/assets/bootstrap.min.css');
        self::assertFileExists($this->caminhos->peles() . '/portal/assets/bootstrap.bundle.min.js');
    }

    public function testSincronizarNaoSobrescrevePersonalizada(): void
    {
        $this->sincronizarPeles();
        $arq = $this->caminhos->peles() . '/portal/rodape.pbl';
        file_put_contents($arq, 'meu rodape');
        $r = (new PelesDistribuidas($this->caminhos))->sincronizar();

        self::assertSame('meu rodape', file_get_contents($arq));
        self::assertNotSame([], array_filter($r['avisos'], static fn (string $a): bool => str_contains($a, 'portal/rodape.pbl')));
    }

    public function testMoldeHomeNaRaizEMoldeDoObjetoVence(): void
    {
        $this->sincronizarPeles();
        $this->s->publicador->publicarSite($this->site());
        $raizHtml = (string) file_get_contents($this->caminhos->pagina($this->siteId, ''));
        self::assertStringContainsString('data-pbl-objeto="' . $this->inicioId . '"', $raizHtml);

        $p = $this->criar('pagina', $this->inicioId, 'Com molde', 'com-molde', ['molde' => 'home']);
        $this->s->publicador->publicar($this->site(), $p);
        $html = (string) file_get_contents($this->caminhos->pagina($this->siteId, 'com-molde'));
        self::assertStringContainsString('<h1>Com molde</h1>', $html);
        // O molde "home" não existe em "pele inexistente": cai nos moldes padrão sem erro.
        $q = $this->criar('pagina', $this->inicioId, 'Molde ruim', 'molde-ruim', ['molde' => 'nao-existe']);
        $this->s->publicador->publicar($this->site(), $q);
        self::assertStringContainsString('Molde ruim', (string) file_get_contents($this->caminhos->pagina($this->siteId, 'molde-ruim')));
    }

    public function testSubarvoreComPeleOnepageUsaAssetsEMenuProprios(): void
    {
        $this->sincronizarPeles();
        $op = $this->criar('pagina', $this->inicioId, 'Onepage', 'onepage', ['pele' => 'onepage', 'no_menu' => true]);
        $this->criar('pagina', $op->id, 'Sobre nos', 'op-sobre', ['peso' => 1], ['resumo' => 'Resumo sobre']);
        $this->criar('pagina', $this->inicioId, 'Fora', 'fora', ['no_menu' => true]);
        $this->s->publicador->publicarSite($this->site());

        $html = (string) file_get_contents($this->caminhos->pagina($this->siteId, 'onepage'));
        self::assertStringContainsString('/_pbl/peles/onepage/assets/bootstrap.min.css?v=', $html);
        self::assertStringContainsString('href="#s-', $html);
        self::assertStringContainsString('Sobre nos', $html);
        self::assertStringNotContainsString('Fora', $html);

        $fora = (string) file_get_contents($this->caminhos->pagina($this->siteId, 'fora'));
        self::assertStringNotContainsString('/_pbl/peles/onepage/', $fora);
    }

    public function testPortalMenuPartiDaRaizDaPele(): void
    {
        $this->sincronizarPeles();
        $pt = $this->criar('pagina', $this->inicioId, 'Portal', 'portal', ['pele' => 'portal', 'no_menu' => true]);
        $this->criar('pagina', $pt->id, 'Interna do portal', 'pt-interna', ['no_menu' => true]);
        $this->criar('pagina', $this->inicioId, 'So do site', 'so-do-site', ['no_menu' => true]);
        $this->s->publicador->publicarSite($this->site());

        $html = (string) file_get_contents($this->caminhos->pagina($this->siteId, 'pt-interna'));
        self::assertStringContainsString('/_pbl/peles/portal/assets/pele.css?v=', $html);
        self::assertStringContainsString('Interna do portal', $html);
        self::assertStringNotContainsString('So do site', $html);
        self::assertStringContainsString('pbl-migalhas', $html);
    }

    public function testAssetsDePeleServidosPeloCaminhoDaPele(): void
    {
        $this->sincronizarPeles();
        $r = $this->req('GET', '/_pbl/peles/portal/assets/pele.css');
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        self::assertSame(404, $this->req('GET', '/_pbl/peles/portal/assets/../padrao.pbl')->getStatusCode());
        self::assertSame(404, $this->req('GET', '/_pbl/peles/portal/padrao.pbl')->getStatusCode());
        self::assertSame(404, $this->req('GET', '/_pbl/peles/..%2Fx/assets/pele.css')->getStatusCode());
        self::assertContains($this->req('POST', '/_pbl/peles/portal/assets/pele.css', null, [], self::ORIGEM)->getStatusCode(), [404, 405]);
    }

    public function testApiDeAcoesSemSessaoDa401(): void
    {
        $r = $this->req('GET', '/_pbl/api/acoes?objeto=' . $this->inicioId);
        self::assertSame(401, $r->getStatusCode());
        self::assertStringContainsString('application/json', (string) $r->headers->get('Content-Type'));
    }

    public function testApiDeAcoesPorPapel(): void
    {
        $p = $this->criar('pagina', $this->inicioId, 'Pagina', 'pagina');
        $url = '/_pbl/api/acoes?objeto=' . $p->id;

        $admin = json_decode($this->html($this->req('GET', $url, $this->token('admin'))), true);
        self::assertIsArray($admin);
        self::assertSame(['editar', 'despublicar', 'mover', 'excluir'], array_keys($admin['acoes']));
        self::assertNotSame('', $admin['csrf']);

        $leitor = $this->req('GET', $url, $this->token('leitor'));
        self::assertSame(200, $leitor->getStatusCode());
        self::assertSame([], json_decode($this->html($leitor), true)['acoes']);
        self::assertSame('', json_decode($this->html($leitor), true)['csrf']);

        self::assertSame(404, $this->req('GET', '/_pbl/api/acoes?objeto=abc', $this->token('admin'))->getStatusCode());
    }

    public function testApiDeEstadoPublicaExigeCsrfEPermissao(): void
    {
        $p = $this->criar('pagina', $this->inicioId, 'Pagina', 'pagina');
        $campos = ['objeto' => (string) $p->id, 'acao' => 'despublicar', 'versao' => (string) $p->versao];

        $semCsrf = $this->post($this->token('admin'), '/_pbl/api/estado', $campos, false);
        self::assertSame(403, $semCsrf->getStatusCode());

        $leitor = $this->post($this->token('leitor'), '/_pbl/api/estado', $campos);
        self::assertSame(403, $leitor->getStatusCode());
        self::assertSame('publicado', $this->s->objetos->porId($p->id)?->status);

        $semSessao = $this->req('POST', '/_pbl/api/estado', null, $campos, self::ORIGEM);
        self::assertSame(401, $semSessao->getStatusCode());

        $ok = $this->post($this->token('editor'), '/_pbl/api/estado', $campos);
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('privado', $this->s->objetos->porId($p->id)?->status);

        $conflito = $this->post($this->token('admin'), '/_pbl/api/estado', ['acao' => 'publicar', 'versao' => '1'] + $campos);
        self::assertSame(409, $conflito->getStatusCode());
    }

    public function testCookieIndicadorNoLoginENaSaida(): void
    {
        $r = $this->req('POST', '/_pbl/entrar', null, ['login' => 'admin', 'senha' => self::SENHA, '_csrf' => $this->s->csrf()->paraEntrar()], self::ORIGEM);
        $nomes = array_map(static fn ($c): string => $c->getName() . ':' . ($c->isHttpOnly() ? 'http' : 'js'), $r->headers->getCookies());
        self::assertContains('pbl4_ind:js', $nomes);
        self::assertContains('__Host-pbl4:http', $nomes);
    }

    public function testBarraNasPaginasPublicas(): void
    {
        $this->sincronizarPeles();
        $this->s->publicador->publicarSite($this->site());
        $html = (string) file_get_contents($this->caminhos->pagina($this->siteId, ''));
        self::assertMatchesRegularExpression('#<script src="/_pbl/assets/pbl-barra\.js\?v=[0-9a-f]{8}" defer></script>#', $html);
        self::assertStringContainsString('data-pbl-objeto="' . $this->inicioId . '"', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testSelectsDePeleEMoldeNaEdicao(): void
    {
        $this->sincronizarPeles();
        $p = $this->criar('pagina', $this->inicioId, 'Pagina', 'pagina');
        $tela = $this->html($this->req('GET', '/_pbl/objeto/' . $p->id . '/editar', $this->token('admin')));
        self::assertStringContainsString('name="pele"', $tela);
        self::assertStringContainsString('name="molde"', $tela);
        self::assertStringContainsString('value="portal"', $tela);

        $leitor = $this->html($this->req('GET', '/_pbl/objeto/' . $p->id . '/editar', $this->token('editor')));
        self::assertStringContainsString('name="pele"', $leitor);
    }

    public function testPeleInvalidaERecusadaEValidaRepublica(): void
    {
        $this->sincronizarPeles();
        $p = $this->criar('pagina', $this->inicioId, 'Pagina', 'pagina');
        $base = ['titulo' => 'Pagina', 'versao' => (string) $p->versao, 'estado' => 'publicado', 'corpo' => '<p>x</p>', 'molde' => '', 'descricao' => '', 'url' => $p->urlAmigavel, 'peso' => '0'];

        $ruim = $this->post($this->token('admin'), '/_pbl/objeto/' . $p->id . '/editar', ['pele' => '../x'] + $base);
        self::assertSame(422, $ruim->getStatusCode());
        self::assertNull($this->s->objetos->porId($p->id)?->pele);

        $ok = $this->post($this->token('admin'), '/_pbl/objeto/' . $p->id . '/editar', ['pele' => 'portal'] + $base);
        self::assertContains($ok->getStatusCode(), [302, 303]);
        self::assertSame('portal', $this->s->objetos->porId($p->id)?->pele);
        self::assertStringContainsString('/_pbl/peles/portal/', (string) file_get_contents($this->caminhos->pagina($this->siteId, 'pagina')));

        $herda = $this->post($this->token('admin'), '/_pbl/objeto/' . $p->id . '/editar', ['pele' => '', 'versao' => (string) $this->s->objetos->porId($p->id)?->versao] + $base);
        self::assertContains($herda->getStatusCode(), [302, 303]);
        self::assertNull($this->s->objetos->porId($p->id)?->pele);
    }

    public function testGravarEPublicar(): void
    {
        $p = $this->criar('pagina', $this->inicioId, 'Pagina', 'pagina');
        $this->s->objetos->atualizar($p->id, ['status' => 'privado']);
        $p = $this->s->objetos->porId($p->id) ?? self::fail();
        $tela = $this->html($this->req('GET', '/_pbl/objeto/' . $p->id . '/editar', $this->token('admin')));
        self::assertStringContainsString('btn btn-success bg-gradient', $tela);
        self::assertStringContainsString('Gravar e publicar', $tela);

        $r = $this->post($this->token('admin'), '/_pbl/objeto/' . $p->id . '/editar', ['titulo' => 'Pagina', 'versao' => (string) $p->versao, 'estado' => 'privado', 'corpo' => '<p>x</p>', 'acao' => 'publicar', 'descricao' => '', 'url' => $p->urlAmigavel, 'peso' => '0']);
        self::assertContains($r->getStatusCode(), [302, 303]);
        self::assertSame('publicado', $this->s->objetos->porId($p->id)?->status);
    }

    public function testAutorNaoVeGravarEPublicar(): void
    {
        $u = $this->s->usuarios->criar('autor', 'autor@exemplo.org', 'Autor', self::SENHA);
        $this->ids['autor'] = $u->id;
        $this->s->usuarios->conceder($u->id, $this->inicioId, \Pbl\Acesso\Perfil::Autor);
        $p = $this->s->objetos->criar($this->siteId, $this->s->classes->porNome('pagina') ?? self::fail(), $this->inicioId, 'Do autor', 'do-autor', ['corpo' => 'x'], 'privado', ['criado_por' => $u->id]);
        $tela = $this->html($this->req('GET', '/_pbl/objeto/' . $p->id . '/editar', $this->token('autor')));
        self::assertStringNotContainsString('Gravar e publicar', $tela);
        self::assertStringNotContainsString('name="pele"', $tela);
    }
}
