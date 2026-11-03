<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Perfil;
use Pbl\Acesso\SessaoRepositorio;
use Pbl\Aplicacao;
use Pbl\Caminhos;
use Pbl\Conteudo\Objeto;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Telas de conteúdo da administração, de ponta a ponta (ADR 0012). */
final class AdminConteudoTest extends TestCase
{
    private const SENHA = 'uma senha bem longa 2026';
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;
    private Objeto $inicio;
    private Objeto $sobre;
    /** @var array<string, int> login => id */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Meu <Site>', 'www.exemplo.org');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $site = $this->s->sites->porHost('www.exemplo.org');
        self::assertNotNull($site);
        $this->inicio = $this->objeto((int) $site->objetoRaizId);
        $this->sobre = $this->objeto((int) $this->s->objetos->porUrl($site->id, 'sobre')?->id);
        $u = $this->s->usuarios;
        $this->ids['admin'] = $u->criar('admin', 'admin@exemplo.org', 'Admin', self::SENHA, true)->id;
        $this->ids['leitor'] = $u->criar('leitor', 'leitor@exemplo.org', 'Leitor', self::SENHA)->id;
        $this->ids['autor'] = $u->criar('autor', 'autor@exemplo.org', 'Autor', self::SENHA)->id;
        $this->ids['editor'] = $u->criar('editor', 'editor@exemplo.org', 'Editor', self::SENHA)->id;
        $this->ids['ninguem'] = $u->criar('ninguem', 'ninguem@exemplo.org', 'Ninguém', self::SENHA)->id;
        $u->conceder($this->ids['leitor'], $this->inicio->id, Perfil::Leitor);
        $u->conceder($this->ids['autor'], $this->inicio->id, Perfil::Autor);
        $u->conceder($this->ids['editor'], $this->sobre->id, Perfil::Editor);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function objeto(int $id): Objeto
    {
        $o = $this->s->objetos->porId($id);
        self::assertNotNull($o);

        return $o;
    }

    private function token(string $login): string
    {
        return $this->s->sessoes()->criar($this->ids[$login]);
    }

    private function csrf(string $token): string
    {
        return $this->s->csrf()->daSessao(SessaoRepositorio::hash($token));
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

    /**
     * @param array<string, mixed> $extra
     * @param array<string, string> $cabecalhos
     */
    private function salvar(string $token, Objeto $o, string $titulo, string $descricao = '', array $extra = [], array $cabecalhos = ['HTTP_ORIGIN' => 'https://www.exemplo.org']): Response
    {
        return $this->req('POST', '/_pbl/objeto/' . $o->id . '/editar', $token, $extra + [
            '_csrf' => $this->csrf($token), 'versao' => (string) $o->versao, 'titulo' => $titulo, 'descricao' => $descricao,
        ], $cabecalhos);
    }

    public function testSemLoginTodaTelaMandaParaEntrarEOPostNaoGrava(): void
    {
        foreach (['/_pbl/', '/_pbl/objeto/' . $this->sobre->id, '/_pbl/objeto/' . $this->sobre->id . '/editar'] as $caminho) {
            $r = $this->req('GET', $caminho);
            self::assertSame(303, $r->getStatusCode(), $caminho);
            self::assertSame('/_pbl/entrar', $r->headers->get('Location'));
        }
        $post = $this->req('POST', '/_pbl/objeto/' . $this->sobre->id . '/editar', null, ['titulo' => 'Invadido', 'descricao' => '', 'versao' => '1']);

        self::assertSame(303, $post->getStatusCode());
        self::assertSame('Sobre', $this->objeto($this->sobre->id)->titulo);
    }

    public function testPainelMostraOsSitesQueCadaUmPodeVer(): void
    {
        $admin = (string) $this->req('GET', '/_pbl/', $this->token('admin'))->getContent();
        self::assertStringContainsString('Meu &lt;Site&gt;', $admin);
        self::assertStringContainsString('href="/_pbl/objeto/' . $this->inicio->id . '"', $admin);
        self::assertStringNotContainsString('Meu <Site>', $admin);

        $editor = (string) $this->req('GET', '/_pbl/', $this->token('editor'))->getContent();
        self::assertStringContainsString('href="/_pbl/objeto/' . $this->sobre->id . '"', $editor);
        self::assertStringNotContainsString('href="/_pbl/objeto/' . $this->inicio->id . '"', $editor);

        $ninguem = $this->req('GET', '/_pbl/', $this->token('ninguem'));
        self::assertSame(200, $ninguem->getStatusCode());
        self::assertStringContainsString('ainda não recebeu acesso', (string) $ninguem->getContent());
        self::assertStringNotContainsString('/_pbl/objeto/', (string) $ninguem->getContent());
    }

    public function testLeitorNavegaNaArvoreSemLinkDeEditar(): void
    {
        $r = $this->req('GET', '/_pbl/objeto/' . $this->inicio->id, $this->token('leitor'));
        $html = (string) $r->getContent();

        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('href="/_pbl/objeto/' . $this->sobre->id . '"', $html, 'lista o filho');
        self::assertStringContainsString('pagina', $html, 'mostra a classe');
        self::assertStringContainsString('publicado', $html, 'mostra o estado');
        self::assertStringNotContainsString('/editar', $html);
        self::assertStringContainsString("default-src 'none'", (string) $r->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        self::assertSame('DENY', $r->headers->get('X-Frame-Options'));
        self::assertStringContainsString('<main id="conteudo"', $html);
    }

    public function testNavegacaoMostraConteudoForaDoArParaQuemPodeVer(): void
    {
        $classe = $this->s->classes->porId($this->sobre->classeId);
        self::assertNotNull($classe);
        $this->s->objetos->criar($this->inicio->siteId, $classe, $this->sobre->id, 'Rascunho interno', 'rascunho-interno', [], 'privado');

        $html = (string) $this->req('GET', '/_pbl/objeto/' . $this->sobre->id, $this->token('editor'))->getContent();

        self::assertStringContainsString('Rascunho interno', $html);
        self::assertStringContainsString('privado (fora do ar)', $html);
        self::assertStringContainsString('href="/_pbl/objeto/' . $this->inicio->id . '"', $this->req('GET', '/_pbl/objeto/' . $this->sobre->id, $this->token('admin'))->getContent() ?: '', 'migalhas para o admin');
        self::assertStringNotContainsString('href="/_pbl/objeto/' . $this->inicio->id . '"', $html, 'migalhas não mostram ancestral que o editor não pode ver');
    }

    public function testSemPermissaoOuObjetoInexistenteDao403Iguais(): void
    {
        $token = $this->token('ninguem');
        $semPermissao = $this->req('GET', '/_pbl/objeto/' . $this->sobre->id, $token);
        $inexistente = $this->req('GET', '/_pbl/objeto/999999', $token);

        self::assertSame(403, $semPermissao->getStatusCode());
        self::assertSame(403, $inexistente->getStatusCode());
        self::assertSame($semPermissao->getContent(), $inexistente->getContent());
        self::assertStringNotContainsString('Sobre', (string) $semPermissao->getContent());
        self::assertSame(403, $this->req('GET', '/_pbl/objeto/' . $this->inicio->id, $this->token('editor'))->getStatusCode(), 'editor de Sobre não vê o pai');
    }

    public function testIdsMalFormadosNaoSaoRotas(): void
    {
        $token = $this->token('admin');
        foreach (['/_pbl/objeto/0', '/_pbl/objeto/01', '/_pbl/objeto/abc', '/_pbl/objeto/1/inexistente', '/_pbl/objeto/1/editar/2', '/_pbl/objeto/1/Editar', '/_pbl/objeto/12345678901', '/_pbl/objeto/-1'] as $caminho) {
            self::assertSame(404, $this->req('GET', $caminho, $token)->getStatusCode(), $caminho);
        }
        self::assertSame(404, $this->req('POST', '/_pbl/objeto/' . $this->sobre->id, $token)->getStatusCode(), 'ver não aceita POST');
    }

    public function testLeitorNaoEditaNemPorPostDiretoEATentativaFicaNaAuditoria(): void
    {
        $token = $this->token('leitor');

        self::assertSame(403, $this->req('GET', '/_pbl/objeto/' . $this->sobre->id . '/editar', $token)->getStatusCode());
        self::assertSame(403, $this->salvar($token, $this->sobre, 'Mudado pelo leitor')->getStatusCode());
        self::assertSame('Sobre', $this->objeto($this->sobre->id)->titulo);
        $negado = array_values(array_filter($this->s->auditoria->recentes(), static fn (array $l): bool => $l['acao'] === 'objeto.editar.negado'));
        self::assertCount(1, $negado);
        self::assertSame($this->sobre->id, $negado[0]['objeto_id']);
        self::assertSame('usuario:' . $this->ids['leitor'], $negado[0]['ator']);
        self::assertNull($negado[0]['detalhe']);
    }

    public function testAutorEditaSoOQueCriou(): void
    {
        $token = $this->token('autor');
        self::assertSame(403, $this->salvar($token, $this->sobre, 'Mudado pelo autor')->getStatusCode(), 'Sobre não foi criado por ele');

        $classe = $this->s->classes->porId($this->sobre->classeId);
        self::assertNotNull($classe);
        $meu = $this->s->objetos->criar($this->inicio->siteId, $classe, $this->inicio->id, 'Meu texto', 'meu-texto', [], 'privado', ['criado_por' => $this->ids['autor']]);

        self::assertSame(200, $this->req('GET', '/_pbl/objeto/' . $meu->id . '/editar', $token)->getStatusCode());
        self::assertSame(303, $this->salvar($token, $meu, 'Meu texto revisado')->getStatusCode());
        self::assertSame('Meu texto revisado', $this->objeto($meu->id)->titulo);
    }

    public function testEditorSalvaRepublicaAPaginaEOPaiERedireciona(): void
    {
        $this->s->publicador->publicarSite($this->s->sites->porId($this->inicio->siteId) ?? self::fail());
        $token = $this->token('editor');

        $form = $this->req('GET', '/_pbl/objeto/' . $this->sobre->id . '/editar', $token);
        self::assertSame(200, $form->getStatusCode());
        self::assertStringContainsString('name="_csrf" value="' . $this->csrf($token) . '"', (string) $form->getContent());
        self::assertStringContainsString('name="versao" value="' . $this->sobre->versao . '"', (string) $form->getContent());

        $r = $this->salvar($token, $this->sobre, '  Quem somos  ', "Linha 1\r\nLinha 2");

        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/_pbl/objeto/' . $this->sobre->id . '/previa?salvo=sim', $r->headers->get('Location'));
        $novo = $this->objeto($this->sobre->id);
        self::assertSame('Quem somos', $novo->titulo);
        self::assertSame("Linha 1\nLinha 2", $novo->descricao);
        self::assertSame($this->sobre->versao + 1, $novo->versao);
        self::assertSame($this->ids['editor'], $this->s->objetos->versoes($novo->id)[0]['usuario_id'] ?? null);
        self::assertStringContainsString('Quem somos', (string) file_get_contents($this->caminhos->pagina($novo->siteId, 'sobre')));
        self::assertStringContainsString('Quem somos', (string) file_get_contents($this->caminhos->pagina($novo->siteId, '')), 'a lista de filhos do pai também foi regravada');
        self::assertStringContainsString('Publicado', (string) $this->req('GET', (string) $r->headers->get('Location'), $token)->getContent());
    }

    public function testNadaMudouNaoCriaVersao(): void
    {
        $r = $this->salvar($this->token('admin'), $this->sobre, 'Sobre', '');

        self::assertSame('/_pbl/objeto/' . $this->sobre->id . '?salvo=nada', $r->headers->get('Location'));
        self::assertSame($this->sobre->versao, $this->objeto($this->sobre->id)->versao);
    }

    public function testCsrfInvalidoOuAusenteRecusaSemGravar(): void
    {
        $token = $this->token('admin');
        $outro = $this->token('editor');

        self::assertSame(403, $this->salvar($token, $this->sobre, 'X1', '', ['_csrf' => str_repeat('0', 64)])->getStatusCode());
        self::assertSame(403, $this->salvar($token, $this->sobre, 'X2', '', ['_csrf' => $this->csrf($outro)])->getStatusCode(), 'token de outra sessão');
        self::assertSame(403, $this->req('POST', '/_pbl/objeto/' . $this->sobre->id . '/editar', $token, ['titulo' => 'X3', 'descricao' => '', 'versao' => '1'])->getStatusCode());
        self::assertSame(403, $this->salvar($token, $this->sobre, 'X4', '', ['_csrf' => ['a' => 'b']])->getStatusCode(), 'token não textual');
        self::assertSame('Sobre', $this->objeto($this->sobre->id)->titulo);
    }

    public function testOrigemDeOutroSiteRecusaMesmoComTokenValido(): void
    {
        $token = $this->token('admin');

        self::assertSame(403, $this->salvar($token, $this->sobre, 'X1', '', [], ['HTTP_ORIGIN' => 'https://evil.example'])->getStatusCode());
        self::assertSame(403, $this->salvar($token, $this->sobre, 'X2', '', [], ['HTTP_ORIGIN' => 'null'])->getStatusCode());
        self::assertSame(403, $this->salvar($token, $this->sobre, 'X3', '', [], ['HTTP_REFERER' => 'https://evil.example/pagina'])->getStatusCode());
        self::assertSame('Sobre', $this->objeto($this->sobre->id)->titulo);
    }

    public function testTituloComHtmlEhSempreEscapado(): void
    {
        $token = $this->token('admin');
        $hostil = '"><script>alert(1)</script><img src=x onerror=alert(2)>';

        self::assertSame(303, $this->salvar($token, $this->sobre, $hostil, '<b>negrito</b>')->getStatusCode());
        self::assertSame($hostil, $this->objeto($this->sobre->id)->titulo, 'guardado como texto, sem alteração');
        $telas = [
            $this->req('GET', '/_pbl/objeto/' . $this->sobre->id, $token),
            $this->req('GET', '/_pbl/objeto/' . $this->sobre->id . '/editar', $token),
            $this->req('GET', '/_pbl/objeto/' . $this->inicio->id, $token),
        ];
        foreach ($telas as $r) {
            $html = (string) $r->getContent();
            self::assertStringNotContainsString('<script>alert(1)', $html);
            self::assertStringNotContainsString('<img src=x', $html);
            self::assertStringNotContainsString('<b>negrito</b>', $html);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        }
        self::assertStringContainsString('value="&quot;&gt;&lt;script&gt;', (string) $telas[1]->getContent(), 'atributo value não é quebrado pela aspa');
    }

    public function testEdicaoConcorrenteNaoSobrescreveOTrabalhoDoOutro(): void
    {
        $token = $this->token('admin');
        $aberto = $this->sobre; // versão que o formulário carregou
        $this->s->objetos->atualizar($aberto->id, ['titulo' => 'Salvo pela outra pessoa'], 'outra', null, false, $this->ids['editor']);

        $r = $this->salvar($token, $aberto, 'Minha versão');

        self::assertSame(409, $r->getStatusCode());
        $html = (string) $r->getContent();
        self::assertStringContainsString('Outra pessoa alterou', $html);
        self::assertStringContainsString('value="Minha versão"', $html, 'o texto digitado não se perde');
        self::assertStringContainsString('Salvo pela outra pessoa', $html, 'mostra o que está salvo');
        self::assertStringContainsString('name="versao" value="' . ($aberto->versao + 1) . '"', $html);
        self::assertSame('Salvo pela outra pessoa', $this->objeto($aberto->id)->titulo);

        $deNovo = $this->salvar($token, $this->objeto($aberto->id), 'Minha versão');
        self::assertSame(303, $deNovo->getStatusCode(), 'salvar de novo, ciente, funciona');
    }

    public function testValidacaoRecusaEntradaRuim(): void
    {
        $token = $this->token('admin');

        self::assertSame(422, $this->salvar($token, $this->sobre, '   ')->getStatusCode(), 'título vazio');
        self::assertSame(422, $this->salvar($token, $this->sobre, str_repeat('a', 301))->getStatusCode(), 'título longo');
        self::assertSame(422, $this->salvar($token, $this->sobre, 'ok', str_repeat('b', 2001))->getStatusCode(), 'descrição longa');
        self::assertSame(422, $this->salvar($token, $this->sobre, "com\x00nulo")->getStatusCode(), 'caractere de controle');
        self::assertSame(422, $this->salvar($token, $this->sobre, "duas\nlinhas")->getStatusCode(), 'quebra de linha no título');
        self::assertSame(422, $this->salvar($token, $this->sobre, "inv\xC3lido")->getStatusCode(), 'UTF-8 inválido');
        self::assertSame(400, $this->salvar($token, $this->sobre, 'ok', '', ['titulo' => ['x']])->getStatusCode(), 'valor não textual');
        self::assertSame(400, $this->salvar($token, $this->sobre, 'ok', '', ['versao' => 'abc'])->getStatusCode(), 'versão inválida');
        self::assertSame('Sobre', $this->objeto($this->sobre->id)->titulo);
        self::assertSame(303, $this->salvar($token, $this->sobre, 'Acentuação ção 😀', "linha\n\tcom tab")->getStatusCode(), 'texto legítimo passa');
    }

    public function testAuditoriaGuardaSoOsNomesDosCampos(): void
    {
        $this->salvar($this->token('editor'), $this->sobre, 'Título secreto XYZ', 'Descrição confidencial QWE');

        $linhas = array_values(array_filter($this->s->auditoria->recentes(), fn (array $l): bool => $l['acao'] === 'objeto.atualizar' && $l['objeto_id'] === $this->sobre->id));
        self::assertCount(1, $linhas);
        self::assertSame('usuario:' . $this->ids['editor'], $linhas[0]['ator']);
        $detalhe = (string) $linhas[0]['detalhe'];
        self::assertStringContainsString('titulo', $detalhe);
        self::assertStringContainsString('descricao', $detalhe);
        self::assertStringContainsString('administracao', $detalhe);
        self::assertStringNotContainsString('secreto', $detalhe);
        self::assertStringNotContainsString('confidencial', $detalhe);
    }

    public function testPaginaInicialNaoQuebraComOAvisoDeVersaoEEditorNaoOVe(): void
    {
        // Chaves::PUBLICAS é vazia (produção, hoje): o aviso de versão nova nunca aparece por aqui, mas a página tem de continuar OK.
        self::assertSame(200, $this->req('GET', '/_pbl/', $this->token('admin'))->getStatusCode());
        $h = (string) $this->req('GET', '/_pbl/', $this->token('editor'))->getContent();
        self::assertStringNotContainsString('Nova versão disponível', $h);
        self::assertStringNotContainsString('Verificação de novidades vencida', $h);
    }
}
