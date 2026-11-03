<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Perfil;
use Pbl\Acesso\SessaoRepositorio;
use Pbl\Aplicacao;
use Pbl\Caminhos;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Redirecionamentos e peles da administração, de ponta a ponta (ADR 0012, fase 5e). */
final class AdminSiteTest extends TestCase
{
    private const SENHA = 'uma senha bem longa 2026';
    private const ORIGEM = ['HTTP_ORIGIN' => 'https://www.exemplo.org'];
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;
    private int $siteId;
    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Meu Site', 'www.exemplo.org');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $site = $this->s->sites->porHost('www.exemplo.org') ?? self::fail();
        $this->siteId = $site->id;
        $u = $this->s->usuarios;
        $this->ids['admin'] = $u->criar('admin', 'admin@exemplo.org', 'Admin', self::SENHA, true)->id;
        $this->ids['editor'] = $u->criar('editor', 'editor@exemplo.org', 'Editor', self::SENHA)->id;
        $u->conceder($this->ids['editor'], (int) $site->objetoRaizId, Perfil::Editor);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    /** Sessão já no modo elevado (fase 5h), sem conferir senha nem tocar no limite de tentativas (ver AdminAuxiliar). */
    private function token(string $login): string
    {
        $token = $this->tokenSemElevar($login);
        $sessao = $this->s->sessoes()->validar($token);
        if ($sessao !== null) {
            $this->s->modoElevado()->abrirParaTestes($sessao);
        }

        return $token;
    }

    /** Sessão nova, sem o modo elevado — para testar a própria reautenticação das peles/moldes. */
    private function tokenSemElevar(string $login): string
    {
        return $this->s->sessoes()->criar($this->ids[$login]);
    }

    /**
     * @param array<string, mixed> $campos
     * @param array<string, string> $cabecalhos
     */
    private function req(string $metodo, string $caminho, ?string $token = null, array $campos = [], array $cabecalhos = []): Response
    {
        $cookies = $token === null ? [] : ['__Host-pbl4' => $token];
        $r = Request::create('https://www.exemplo.org' . $caminho, $metodo, $campos, $cookies, [], ['REMOTE_ADDR' => '198.51.100.7'] + $cabecalhos);

        return (new Aplicacao($this->caminhos))->tratar($r);
    }

    /** @param array<string, mixed> $campos */
    private function post(string $token, string $caminho, array $campos, bool $comCsrf = true): Response
    {
        $csrf = $this->s->csrf()->daSessao(SessaoRepositorio::hash($token));

        return $this->req('POST', $caminho, $token, ($comCsrf ? ['_csrf' => $csrf] : []) + $campos, self::ORIGEM);
    }

    private function base(): string
    {
        return '/_pbl/site/' . $this->siteId;
    }

    private function html(Response $r): string
    {
        return (string) $r->getContent();
    }

    /** @return array<string, string> */
    private function regra(string $origem, string $destino): array
    {
        return ['origem' => $origem, 'destino' => $destino, 'codigo' => '301'];
    }

    public function testSemLoginTodaRotaMandaParaEntrarEPostNaoGrava(): void
    {
        $b = $this->base();
        foreach (['/redirecionamentos', '/redirecionamentos/1/editar', '/peles', '/peles/basica', '/peles/basica/molde?nome=padrao', '/peles/basica/novo', '/peles/basica/apagar?nome=padrao', '/busca'] as $c) {
            self::assertSame(303, $this->req('GET', $b . $c)->getStatusCode(), $c);
        }
        foreach (['/redirecionamentos', '/peles/ativar', '/peles/basica/molde', '/peles/basica/novo', '/peles/basica/apagar', '/busca'] as $c) {
            self::assertSame(303, $this->req('POST', $b . $c, null, ['nome' => 'x', 'conteudo' => 'x'], self::ORIGEM)->getStatusCode(), $c);
        }
        self::assertSame([], $this->s->redirecionamentos->doSite($this->siteId));
        self::assertFileDoesNotExist($this->caminhos->peles() . '/basica/x.pbl');
    }

    public function testRotasDesconhecidasDao404(): void
    {
        $t = $this->token('admin');
        foreach (['/peles/BASICA', '/peles/basica/molde/x', '/redirecionamentos/abc/editar', '/redirecionamentos/1/outra', '/peles/..', '/busca/x', '/busca/1'] as $c) {
            self::assertSame(404, $this->req('GET', $this->base() . $c, $t)->getStatusCode(), $c);
        }
        self::assertSame(404, $this->req('DELETE', $this->base() . '/peles', $t)->getStatusCode());
    }

    public function testSemPermissaoDeAdministrarTodaRotaDa403EPostNaoGrava(): void
    {
        $t = $this->token('editor');
        $b = $this->base();
        foreach (['/redirecionamentos', '/peles', '/peles/basica', '/peles/basica/molde?nome=padrao', '/peles/basica/novo', '/peles/basica/apagar?nome=padrao', '/busca'] as $c) {
            self::assertSame(403, $this->req('GET', $b . $c, $t)->getStatusCode(), $c);
        }
        self::assertSame(403, $this->post($t, $b . '/redirecionamentos', $this->regra('/a', '/b'))->getStatusCode());
        self::assertSame(403, $this->post($t, $b . '/peles/basica/molde', ['nome' => 'padrao', 'conteudo' => 'x', 'base' => 'x'])->getStatusCode());
        self::assertSame(403, $this->post($t, $b . '/peles/basica/novo', ['nome' => 'novo', 'conteudo' => 'x'])->getStatusCode());
        self::assertSame(403, $this->post($t, $b . '/peles/basica/apagar', ['nome' => 'padrao'])->getStatusCode());
        self::assertSame(403, $this->post($t, $b . '/peles/ativar', ['pele' => 'basica'])->getStatusCode());
        self::assertSame(403, $this->post($t, $b . '/busca', ['ligado' => '1'])->getStatusCode());
        self::assertSame([], $this->s->redirecionamentos->doSite($this->siteId));
        self::assertFileDoesNotExist($this->caminhos->peles() . '/basica/novo.pbl');
        self::assertNotEmpty(array_filter($this->s->auditoria->recentes(), static fn (array $l): bool => $l['acao'] === 'site.negado'));
        // Site inexistente: o não-administrador também leva 403 (não revela quais ids existem).
        self::assertSame(403, $this->req('GET', '/_pbl/site/999/peles', $t)->getStatusCode());
        self::assertSame(404, $this->req('GET', '/_pbl/site/999/peles', $this->token('admin'))->getStatusCode());
    }

    public function testPostSemCsrfOuComOrigemErradaRecusa(): void
    {
        $t = $this->token('admin');
        $b = $this->base();
        $posts = [
            '/redirecionamentos' => $this->regra('/a', '/b'),
            '/peles/ativar' => ['pele' => 'basica'],
            '/peles/basica/molde' => ['nome' => 'padrao', 'conteudo' => 'x', 'base' => 'x'],
            '/peles/basica/novo' => ['nome' => 'novo', 'conteudo' => 'x'],
            '/peles/basica/apagar' => ['nome' => 'padrao'],
            '/busca' => ['ligado' => '1'],
        ];
        $csrf = $this->s->csrf()->daSessao(SessaoRepositorio::hash($t));
        foreach ($posts as $c => $campos) {
            self::assertSame(403, $this->post($t, $b . $c, $campos, false)->getStatusCode(), $c . ' sem csrf');
            self::assertSame(403, $this->req('POST', $b . $c, $t, ['_csrf' => $csrf] + $campos, ['HTTP_ORIGIN' => 'https://evil.example'])->getStatusCode(), $c . ' origem');
        }
        self::assertSame([], $this->s->redirecionamentos->doSite($this->siteId));
        self::assertFileDoesNotExist($this->caminhos->peles() . '/basica/novo.pbl');
    }

    public function testRedirecionamentoCriaListaEditaERemove(): void
    {
        $t = $this->token('admin');
        $b = $this->base() . '/redirecionamentos';
        $r = $this->post($t, $b, $this->regra('/antigo/<b>x</b>', '/novo'));
        self::assertSame(422, $r->getStatusCode(), 'origem inválida');
        $r = $this->post($t, $b, $this->regra('/antigo', '/novo') + ['prefixo' => '1']);
        self::assertSame(303, $r->getStatusCode());
        $regras = $this->s->redirecionamentos->doSite($this->siteId);
        self::assertCount(1, $regras);
        self::assertTrue($regras[0]['prefixo']);
        self::assertSame(['destino' => '/novo/x', 'codigo' => 301], $this->s->redirecionamentos->resolver($this->siteId, '/antigo/x'));
        $lista = $this->html($this->req('GET', $b, $t));
        self::assertStringContainsString('<code>/antigo</code>', $lista);

        $id = $regras[0]['id'];
        self::assertStringContainsString('value="/novo"', $this->html($this->req('GET', "{$b}/{$id}/editar", $t)));
        self::assertSame(303, $this->post($t, "{$b}/{$id}/editar", ['origem' => '/antigo', 'destino' => 'https://exemplo.com/p', 'codigo' => '302'])->getStatusCode());
        $regra = $this->s->redirecionamentos->doSite($this->siteId)[0];
        self::assertSame(['https://exemplo.com/p', 302, false], [$regra['destino'], $regra['codigo'], $regra['prefixo']]);
        self::assertSame(422, $this->post($t, "{$b}/{$id}/editar", $this->regra('/antigo', 'javascript:alert(1)'))->getStatusCode());

        self::assertSame(303, $this->post($t, "{$b}/{$id}/remover", [])->getStatusCode());
        self::assertSame([], $this->s->redirecionamentos->doSite($this->siteId));
        self::assertSame(404, $this->post($t, "{$b}/{$id}/remover", [])->getStatusCode());
        $acoes = array_column($this->s->auditoria->recentes(), 'acao');
        self::assertContains('redirecionamento.criar', $acoes);
        self::assertContains('redirecionamento.remover', $acoes);
    }

    public function testRedirecionamentoDuplicataLacoEAvisos(): void
    {
        $t = $this->token('admin');
        $b = $this->base() . '/redirecionamentos';
        self::assertSame(303, $this->post($t, $b, $this->regra('/a', '/b'))->getStatusCode());
        $dup = $this->post($t, $b, $this->regra('/a', '/c'));
        self::assertSame(422, $dup->getStatusCode());
        self::assertStringContainsString('Já existe uma regra', $this->html($dup));
        // Laço direto e laço pela cadeia.
        self::assertSame(422, $this->post($t, $b, $this->regra('/x', '/x'))->getStatusCode());
        $laco = $this->post($t, $b, $this->regra('/b', '/a'));
        self::assertSame(422, $laco->getStatusCode());
        self::assertStringContainsString('laço', $this->html($laco));
        self::assertCount(1, $this->s->redirecionamentos->doSite($this->siteId));
        // Cadeia sem laço: aceita, com aviso.
        $ok = $this->post($t, $b, $this->regra('/z', '/a'));
        self::assertSame(303, $ok->getStatusCode());
        self::assertSame(303, $this->post($t, $b, $this->regra('/y', '/z'))->getStatusCode());
        $r = $this->req('GET', (string) $this->post($t, $b, $this->regra('/w', '/y'))->headers->get('Location'), $t);
        self::assertStringContainsString('mais de um salto', $this->html($r));
        // Aviso de sobreposição com conteúdo existente.
        $inicio = $this->s->objetos->porId((int) $this->s->sites->porId($this->siteId)?->objetoRaizId) ?? self::fail();
        $pagina = $this->s->classes->porNome('pagina') ?? self::fail();
        $this->s->objetos->criar($this->siteId, $pagina, $inicio->id, 'Novidades', 'novidades', [], 'publicado');
        $r = $this->req('GET', (string) $this->post($t, $b, $this->regra('/novidades', '/b'))->headers->get('Location'), $t);
        self::assertStringContainsString('já existe um conteúdo', mb_strtolower($this->html($r)));
    }

    public function testEdicaoDeRegraNaoViraLacoComAPropriaRegraAntiga(): void
    {
        $t = $this->token('admin');
        $b = $this->base() . '/redirecionamentos';
        $this->post($t, $b, $this->regra('/a', '/b'));
        $this->post($t, $b, $this->regra('/b', '/c'));
        $id = $this->s->redirecionamentos->doSite($this->siteId)[1]['id'];
        // Trocar /b -> /c por /b -> /d é válido (a regra antiga não conta).
        self::assertSame(303, $this->post($t, "{$b}/{$id}/editar", $this->regra('/b', '/d'))->getStatusCode());
        self::assertSame(422, $this->post($t, "{$b}/{$id}/editar", $this->regra('/b', '/a'))->getStatusCode());
    }

    public function testRedirecionamentoRecusaRegraDeOutroSiteEPeleXss(): void
    {
        $t = $this->token('admin');
        $outro = $this->s->sites->criar('Outro', 'outro.exemplo.org');
        $this->s->redirecionamentos->adicionar($outro->id, '/q', '/r');
        $id = $this->s->redirecionamentos->doSite($outro->id)[0]['id'];
        self::assertSame(404, $this->req('GET', $this->base() . "/redirecionamentos/{$id}/editar", $t)->getStatusCode());
        self::assertSame(404, $this->post($t, $this->base() . "/redirecionamentos/{$id}/remover", [])->getStatusCode());
        self::assertCount(1, $this->s->redirecionamentos->doSite($outro->id));
        // Destino com aspas é escapado no formulário reapresentado.
        $r = $this->post($t, $this->base() . '/redirecionamentos', ['origem' => '/a', 'destino' => '"><script>alert(1)</script>', 'codigo' => '301']);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringNotContainsString('<script>alert', $this->html($r));
    }

    public function testListaDePelesVerArquivosEAtivar(): void
    {
        $t = $this->token('admin');
        $b = $this->base() . '/peles';
        self::assertStringContainsString('<code>basica</code>', $this->html($this->req('GET', $b, $t)));
        self::assertStringContainsString('padrao.pbl', $this->html($this->req('GET', "{$b}/basica", $t)));
        self::assertSame(404, $this->req('GET', "{$b}/inexistente", $t)->getStatusCode());
        mkdir($this->caminhos->peles() . '/escura');
        copy($this->caminhos->peles() . '/basica/padrao.pbl', $this->caminhos->peles() . '/escura/padrao.pbl');
        mkdir($this->caminhos->peles() . '/vazia');
        self::assertSame(303, $this->post($t, "{$b}/ativar", ['pele' => 'escura'])->getStatusCode());
        self::assertSame('escura', $this->s->sites->porId($this->siteId)?->pele);
        // Sem molde padrao, pele inexistente e nome de travessia: recusa e não muda.
        foreach (['vazia', 'nao-existe', '../basica', 'basica/../escura'] as $pele) {
            self::assertSame(422, $this->post($t, "{$b}/ativar", ['pele' => $pele])->getStatusCode(), $pele);
        }
        self::assertSame('escura', $this->s->sites->porId($this->siteId)?->pele);
    }

    public function testTelaDeBuscaMostraEGravaAOpcaoDeTolerancia(): void
    {
        $t = $this->token('admin');
        $b = $this->base() . '/busca';
        self::assertFalse($this->s->sites->porId($this->siteId)?->buscaTolerancia, 'desligada por padrão');
        $html = $this->html($this->req('GET', $b, $t));
        self::assertStringNotContainsString('checked', $html);

        self::assertSame(303, $this->post($t, $b, ['ligado' => '1'])->getStatusCode());
        self::assertTrue($this->s->sites->porId($this->siteId)?->buscaTolerancia);
        self::assertStringContainsString('checked', $this->html($this->req('GET', $b, $t)));
        self::assertNotEmpty(array_filter($this->s->auditoria->recentes(), static fn (array $l): bool => $l['acao'] === 'busca.tolerancia'));

        // sem o campo "ligado" no POST: desliga de novo (como um checkbox desmarcado, que o navegador não envia)
        self::assertSame(303, $this->post($t, $b, [])->getStatusCode());
        self::assertFalse($this->s->sites->porId($this->siteId)?->buscaTolerancia);
    }

    public function testEditarMoldeFelizValidaGravaERepublica(): void
    {
        $t = $this->token('admin');
        $b = $this->base() . '/peles/basica';
        $form = $this->html($this->req('GET', "{$b}/molde?nome=padrao", $t));
        self::assertStringContainsString('<textarea', $form);
        $base = hash('sha256', (string) file_get_contents($this->caminhos->peles() . '/basica/padrao.pbl'));
        self::assertStringContainsString('name="base" value="' . $base . '"', $form);
        $novo = "<h1>Olá <@eco #titulo@></h1>\n";
        $r = $this->post($t, "{$b}/molde", ['nome' => 'padrao', 'conteudo' => str_replace("\n", "\r\n", $novo), 'base' => $base]);
        self::assertSame(303, $r->getStatusCode());
        self::assertSame($novo, file_get_contents($this->caminhos->peles() . '/basica/padrao.pbl'));
        // Republicou: a página inicial no cache usa o molde novo.
        $site = $this->s->sites->porId($this->siteId) ?? self::fail();
        $pagina = (string) file_get_contents($this->caminhos->pagina($site->id, 'sobre'));
        self::assertStringContainsString('<h1>Olá ', $pagina);
        self::assertSame([], glob($this->caminhos->peles() . '/basica/.*.tmp') ?: []);
        self::assertContains('pele.molde.salvar', array_column($this->s->auditoria->recentes(), 'acao'));
    }

    public function testMoldeInvalidoNaoGravaEMostraLinha(): void
    {
        $t = $this->token('admin');
        $b = $this->base() . '/peles/basica';
        $original = (string) file_get_contents($this->caminhos->peles() . '/basica/padrao.pbl');
        $base = hash('sha256', $original);
        $r = $this->post($t, "{$b}/molde", ['nome' => 'padrao', 'conteudo' => "<p>ok</p>\n<p><@se #titulo@>sem fechar</p>\n", 'base' => $base]);
        self::assertSame(422, $r->getStatusCode());
        self::assertMatchesRegularExpression('/linha \d+/', $this->html($r));
        self::assertStringContainsString('O molde não foi salvo', $this->html($r));
        self::assertSame($original, file_get_contents($this->caminhos->peles() . '/basica/padrao.pbl'));
        // Grande demais e UTF-8 inválido.
        $grande = str_repeat('a', 200_001);
        self::assertSame(422, $this->post($t, "{$b}/molde", ['nome' => 'padrao', 'conteudo' => $grande, 'base' => $base])->getStatusCode());
        self::assertSame(422, $this->post($t, "{$b}/molde", ['nome' => 'padrao', 'conteudo' => "\xFF\xFE", 'base' => $base])->getStatusCode());
        self::assertSame($original, file_get_contents($this->caminhos->peles() . '/basica/padrao.pbl'));
        // Herança para um layout que não existe.
        self::assertSame(422, $this->post($t, "{$b}/molde", ['nome' => 'padrao', 'conteudo' => '<@estende "naoexiste"@>', 'base' => $base])->getStatusCode());
    }

    public function testConflitoDeEdicaoDa409(): void
    {
        $t = $this->token('admin');
        $b = $this->base() . '/peles/basica';
        $r = $this->post($t, "{$b}/molde", ['nome' => 'padrao', 'conteudo' => '<p>x</p>', 'base' => str_repeat('0', 64)]);
        self::assertSame(409, $r->getStatusCode());
        self::assertNotSame('<p>x</p>', file_get_contents($this->caminhos->peles() . '/basica/padrao.pbl'));
    }

    public function testTravessiaDeCaminhoEExtensoesNaoEscrevemForaDaPele(): void
    {
        $t = $this->token('admin');
        $b = $this->base() . '/peles/basica';
        $maus = ['../x', '..', 'a/../b', '/etc/passwd', 'a//b', 'a\\b', 'x.php', 'x.pbl.php', "x\0y", 'A', 'a b', '', 'a/b/c/d', '.htaccess', str_repeat('a', 101), '%2e%2e/x'];
        foreach ($maus as $nome) {
            $r = $this->post($t, "{$b}/novo", ['nome' => $nome, 'conteudo' => '<p>x</p>']);
            self::assertSame(422, $r->getStatusCode(), $nome);
        }
        self::assertSame(['agenda.pbl', 'assets', 'bloco.pbl', 'bloco_aviso.pbl', 'bloco_rodape.pbl', 'busca.pbl', 'cabeca.pbl', 'destaque.pbl', 'erro404.pbl', 'estilo.pbl', 'evento.pbl', 'faixa_destaques.pbl', 'faq.pbl', 'formulario.pbl', 'home.pbl', 'item.pbl', 'link.pbl', 'link_a.pbl', 'noticia.pbl', 'padrao.pbl', 'paginacao.pbl', 'pasta.pbl', 'perguntas.pbl', 'topo.pbl'], array_values(array_diff(scandir($this->caminhos->peles() . '/basica') ?: [], ['.', '..'])));
        self::assertFileDoesNotExist($this->caminhos->peles() . '/x.pbl');
        self::assertFileDoesNotExist($this->raiz . '/x.pbl');
        // Leitura, edição e apagar com nomes maus não encontram nada (404) e não apagam.
        foreach (['../../pbl4.sqlite', '../basica/padrao', 'padrao.php'] as $nome) {
            self::assertSame(404, $this->req('GET', "{$b}/molde?nome=" . rawurlencode($nome), $t)->getStatusCode(), $nome);
            self::assertSame(404, $this->post($t, "{$b}/apagar", ['nome' => $nome])->getStatusCode(), $nome);
            self::assertSame(404, $this->post($t, "{$b}/molde", ['nome' => $nome, 'conteudo' => 'x', 'base' => 'y'])->getStatusCode(), $nome);
        }
        self::assertFileExists($this->caminhos->peles() . '/basica/padrao.pbl');
        // Pele com nome de travessia.
        self::assertSame(404, $this->req('GET', $this->base() . '/peles/..%2F..', $t)->getStatusCode());
    }

    public function testLinkSimbolicoNaPeleNaoEhSeguido(): void
    {
        $t = $this->token('admin');
        $fora = $this->raiz . '/fora';
        mkdir($fora);
        file_put_contents($fora . '/segredo.pbl', 'SEGREDO');
        symlink($fora, $this->caminhos->peles() . '/basica/ln');
        symlink($fora . '/segredo.pbl', $this->caminhos->peles() . '/basica/atalho.pbl');
        $b = $this->base() . '/peles/basica';
        self::assertStringNotContainsString('atalho', $this->html($this->req('GET', $b, $t)));
        self::assertSame(404, $this->req('GET', "{$b}/molde?nome=atalho", $t)->getStatusCode());
        self::assertSame(404, $this->req('GET', "{$b}/molde?nome=ln/segredo", $t)->getStatusCode());
        $r = $this->post($t, "{$b}/novo", ['nome' => 'ln/novo', 'conteudo' => '<p>x</p>']);
        self::assertNotSame(303, $r->getStatusCode());
        self::assertFileDoesNotExist($fora . '/novo.pbl');
        $r = $this->post($t, "{$b}/novo", ['nome' => 'atalho', 'conteudo' => '<p>x</p>']);
        self::assertFileExists($fora . '/segredo.pbl');
        self::assertSame('SEGREDO', file_get_contents($fora . '/segredo.pbl'));
        unlink($this->caminhos->peles() . '/basica/ln');
        unlink($this->caminhos->peles() . '/basica/atalho.pbl');
    }

    public function testCriarMoldeEmPastaDuplicadoEApagar(): void
    {
        $t = $this->token('admin');
        $b = $this->base() . '/peles/basica';
        self::assertSame(303, $this->post($t, "{$b}/novo", ['nome' => 'include/menu', 'conteudo' => '<nav><@eco #titulo@></nav>'])->getStatusCode());
        self::assertFileExists($this->caminhos->peles() . '/basica/include/menu.pbl');
        self::assertStringContainsString('include/menu.pbl', $this->html($this->req('GET', $b, $t)));
        $dup = $this->post($t, "{$b}/novo", ['nome' => 'include/menu', 'conteudo' => '<p>x</p>']);
        self::assertSame(422, $dup->getStatusCode());
        // Um molde que cita o menu: apagar o menu é recusado.
        self::assertSame(303, $this->post($t, "{$b}/novo", ['nome' => 'pagina', 'conteudo' => '<@incluir arquivo="include/menu"@>'])->getStatusCode());
        self::assertSame(409, $this->post($t, "{$b}/apagar", ['nome' => 'include/menu'])->getStatusCode());
        self::assertSame(409, $this->req('GET', "{$b}/apagar?nome=include%2Fmenu", $t)->getStatusCode());
        // padrao nunca se apaga.
        self::assertSame(409, $this->post($t, "{$b}/apagar", ['nome' => 'padrao'])->getStatusCode());
        self::assertFileExists($this->caminhos->peles() . '/basica/padrao.pbl');
        // Confirmação e remoção do que ninguém cita.
        self::assertSame(200, $this->req('GET', "{$b}/apagar?nome=pagina", $t)->getStatusCode());
        self::assertSame(303, $this->post($t, "{$b}/apagar", ['nome' => 'pagina'])->getStatusCode());
        self::assertFileDoesNotExist($this->caminhos->peles() . '/basica/pagina.pbl');
        self::assertContains('pele.molde.apagar', array_column($this->s->auditoria->recentes(), 'acao'));
    }

    public function testConteudoDoMoldeEhEscapadoNaTela(): void
    {
        $t = $this->token('admin');
        $b = $this->base() . '/peles/basica';
        $this->post($t, "{$b}/novo", ['nome' => 'xss', 'conteudo' => '</textarea><script>alert(1)</script>']);
        $r = $this->req('GET', "{$b}/molde?nome=xss", $t);
        self::assertStringNotContainsString('<script>alert', $this->html($r));
        self::assertStringContainsString('&lt;/textarea&gt;', $this->html($r));
        self::assertStringContainsString("default-src 'none'", (string) $r->headers->get('Content-Security-Policy'));
    }

    public function testPhpCruNoMoldeEhRecusado(): void
    {
        $t = $this->token('admin');
        $b = $this->base() . '/peles/basica';
        $original = (string) file_get_contents($this->caminhos->peles() . '/basica/padrao.pbl');
        $r = $this->post($t, "{$b}/molde", ['nome' => 'padrao', 'conteudo' => '<?php file_put_contents("/tmp/pwn-pbl4", "x"); ?>ok', 'base' => hash('sha256', $original)]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('PHP cru', $this->html($r));
        self::assertSame($original, file_get_contents($this->caminhos->peles() . '/basica/padrao.pbl'));
        self::assertFileDoesNotExist('/tmp/pwn-pbl4');
    }

    // ---------------------------------------------------------------- modo elevado (fase 5h)

    public function testCriarEditarApagarMoldeEAtivarPeleExigemSenhaDeNovo(): void
    {
        // Um molde descartável, criado com uma sessão já elevada, só para testar apagar sem a senha (o padrao nunca
        // se apaga, reauth ou não, então não serve para esse caso).
        $b = $this->base() . '/peles/basica';
        $this->post($this->token('admin'), "{$b}/novo", ['nome' => 'descartavel', 'conteudo' => '<p>x</p>']);
        self::assertFileExists($this->caminhos->peles() . '/basica/descartavel.pbl');

        $t = $this->tokenSemElevar('admin');
        $original = (string) file_get_contents($this->caminhos->peles() . '/basica/padrao.pbl');

        $r = $this->post($t, "{$b}/novo", ['nome' => 'semsenha', 'conteudo' => '<p>x</p>']);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('Confirme sua senha', $this->html($r));
        self::assertFileDoesNotExist($this->caminhos->peles() . '/basica/semsenha.pbl');

        $r2 = $this->post($t, "{$b}/molde", ['nome' => 'padrao', 'conteudo' => '<p>mudou</p>', 'base' => hash('sha256', $original)]);
        self::assertSame(422, $r2->getStatusCode());
        self::assertSame($original, file_get_contents($this->caminhos->peles() . '/basica/padrao.pbl'));

        $r3 = $this->post($t, "{$b}/apagar", ['nome' => 'descartavel']);
        self::assertSame(422, $r3->getStatusCode());
        self::assertFileExists($this->caminhos->peles() . '/basica/descartavel.pbl', 'nada apagado sem a senha');

        $r4 = $this->post($t, $this->base() . '/peles/ativar', ['pele' => 'escura']);
        self::assertSame(422, $r4->getStatusCode());
        self::assertSame('basica', $this->s->sites->porId($this->siteId)?->pele);
    }

    public function testCriarMoldeComSenhaCertaFuncionaEAbreOModoElevadoParaApagar(): void
    {
        $t = $this->tokenSemElevar('admin');
        $b = $this->base() . '/peles/basica';
        $r = $this->post($t, "{$b}/novo", ['nome' => 'comsenha', 'conteudo' => '<p>x</p>', 'senha' => self::SENHA]);
        self::assertSame(303, $r->getStatusCode());
        self::assertFileExists($this->caminhos->peles() . '/basica/comsenha.pbl');

        // Já elevado: apagar não pede a senha de novo.
        $r2 = $this->post($t, "{$b}/apagar", ['nome' => 'comsenha']);
        self::assertSame(303, $r2->getStatusCode());
        self::assertFileDoesNotExist($this->caminhos->peles() . '/basica/comsenha.pbl');
    }

    public function testSenhaErradaAoCriarMoldeNaoGravaNemVaiParaAAuditoria(): void
    {
        $t = $this->tokenSemElevar('admin');
        $b = $this->base() . '/peles/basica';
        $r = $this->post($t, "{$b}/novo", ['nome' => 'falhou', 'conteudo' => '<p>x</p>', 'senha' => 'senha-errada']);
        self::assertSame(401, $r->getStatusCode());
        self::assertFileDoesNotExist($this->caminhos->peles() . '/basica/falhou.pbl');
        self::assertStringNotContainsString('senha-errada', (string) json_encode($this->s->auditoria->recentes(500)));
    }
}
