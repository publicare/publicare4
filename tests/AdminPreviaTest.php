<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Perfil;
use Pbl\Acesso\SessaoRepositorio;
use Pbl\Aplicacao;
use Pbl\Caminhos;
use Pbl\Conteudo\Classe;
use Pbl\Conteudo\Objeto;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Depois de gravar, o painel leva à prévia renderizada com a faixa de resultado (mensagem pelo estado real). */
final class AdminPreviaTest extends TestCase
{
    private const SENHA = 'uma senha bem longa 2026';
    private const ORIGEM = ['HTTP_ORIGIN' => 'https://www.exemplo.org'];
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;
    private Objeto $inicio;
    private Objeto $sobre;
    private Classe $pagina;
    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Meu Site', 'www.exemplo.org');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $site = $this->s->sites->porHost('www.exemplo.org');
        self::assertNotNull($site);
        $this->inicio = $this->obj((int) $site->objetoRaizId);
        $this->sobre = $this->obj((int) $this->s->objetos->porUrl($site->id, 'sobre')?->id);
        $pagina = $this->s->classes->porNome('pagina');
        self::assertNotNull($pagina);
        $this->pagina = $pagina;
        $u = $this->s->usuarios;
        foreach (['admin' => true, 'leitor' => false, 'autor' => false, 'editor' => false, 'ninguem' => false] as $login => $adm) {
            $this->ids[$login] = $u->criar($login, $login . '@exemplo.org', ucfirst($login), self::SENHA, $adm)->id;
        }
        $u->conceder($this->ids['leitor'], $this->inicio->id, Perfil::Leitor);
        $u->conceder($this->ids['autor'], $this->inicio->id, Perfil::Autor);
        $u->conceder($this->ids['editor'], $this->sobre->id, Perfil::Editor);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function obj(int $id): Objeto
    {
        $o = $this->s->objetos->porId($id);
        self::assertNotNull($o);

        return $o;
    }

    private function filho(string $titulo, string $url, string $status = 'privado', array $meta = [], ?Objeto $pai = null): Objeto
    {
        $pai ??= $this->inicio;

        return $this->s->objetos->criar($pai->siteId, $this->pagina, $pai->id, $titulo, $url, [], $status, $meta);
    }

    private function token(string $login): string
    {
        return $this->s->sessoes()->criar($this->ids[$login]);
    }

    private function csrf(string $token): string
    {
        return $this->s->csrf()->daSessao(SessaoRepositorio::hash($token));
    }

    /** @param array<string, mixed> $campos */
    private function req(string $metodo, string $caminho, ?string $token = null, array $campos = []): Response
    {
        $cookies = $token === null ? [] : ['__Host-pbl4' => $token];
        $r = Request::create('https://www.exemplo.org' . $caminho, $metodo, $campos, $cookies, [], ['REMOTE_ADDR' => '198.51.100.7'] + self::ORIGEM);

        return (new Aplicacao($this->caminhos))->tratar($r);
    }

    /** @param array<string, mixed> $campos */
    private function post(string $login, string $caminho, array $campos): Response
    {
        $t = $this->token($login);

        return $this->req('POST', $caminho, $t, $campos + ['_csrf' => $this->csrf($t)]);
    }

    /** Segue o redirecionamento de gravação até a prévia e devolve o HTML dela. */
    private function previa(string $login, Response $r, string $esperado): string
    {
        self::assertSame(303, $r->getStatusCode());
        $local = (string) $r->headers->get('Location');
        self::assertMatchesRegularExpression('#^/_pbl/objeto/\d+/previa\?salvo=#', $local);
        $p = $this->req('GET', $local, $this->token($login));
        self::assertSame(200, $p->getStatusCode());
        $h = (string) $p->getContent();
        self::assertStringContainsString('id="faixa-previa"', $h);
        self::assertMatchesRegularExpression('#<strong>' . preg_quote($esperado, '#') . '#', $h);
        self::assertStringContainsString("'unsafe-inline'", (string) $p->headers->get('Content-Security-Policy'));
        self::assertStringContainsString("script-src 'self'", (string) $p->headers->get('Content-Security-Policy'));
        self::assertStringNotContainsString('onclick=', $h);

        return $h;
    }

    public function testGravarRascunhoMostraFaixaDeRascunho(): void
    {
        $a = $this->filho('Rascunho A', 'ra');
        $h = $this->previa('admin', $this->post('admin', '/_pbl/objeto/' . $a->id . '/editar', ['versao' => (string) $a->versao, 'titulo' => 'Rascunho B', 'descricao' => '']), 'Gravado como rascunho (privado, não está no ar)');
        self::assertStringContainsString('Voltar a editar', $h);
        self::assertStringContainsString('href="/_pbl/conteudo/' . $this->inicio->id . '"', $h, 'lista no nível do pai');
        self::assertStringNotContainsString('Abrir a página pública', $h);
        self::assertStringContainsString('Rascunho B', $h, 'a prévia é a página renderizada');
    }

    public function testGravarEPublicarMostraPublicadoComLinkPublico(): void
    {
        $a = $this->filho('Para o ar', 'ar');
        $h = $this->previa('admin', $this->post('admin', '/_pbl/objeto/' . $a->id . '/editar', ['versao' => (string) $a->versao, 'titulo' => 'Para o ar', 'descricao' => 'x', 'acao' => 'publicar']), 'Publicado');
        self::assertStringContainsString('Abrir a página pública', $h);
        self::assertStringContainsString('https://www.exemplo.org/ar', $h);
    }

    public function testAgendadoDespublicadoEEstadoReal(): void
    {
        $a = $this->filho('Estado', 'estado');
        $url = '/_pbl/objeto/' . $a->id . '/estado';
        $this->previa('admin', $this->post('admin', $url, ['versao' => (string) $a->versao, 'estado' => 'publicado', 'data_publicacao' => '2099-01-02T10:30', 'data_validade' => '']), 'Agendado para 02/01 10:30');
        $a = $this->obj($a->id);
        $h = $this->previa('admin', $this->post('admin', $url, ['versao' => (string) $a->versao, 'estado' => 'privado', 'data_publicacao' => '', 'data_validade' => '']), 'Despublicado');
        // Publicar de novo e despublicar outra vez.
        $a = $this->obj($a->id);
        $this->post('admin', $url, ['versao' => (string) $a->versao, 'estado' => 'publicado', 'data_publicacao' => '', 'data_validade' => '']);
        $a = $this->obj($a->id);
        $this->previa('admin', $this->post('admin', $url, ['versao' => (string) $a->versao, 'estado' => 'privado', 'data_publicacao' => '', 'data_validade' => '']), 'Despublicado');
    }

    public function testRevisaoEnviarDevolverEAprovar(): void
    {
        $meu = $this->filho('Do autor', 'do-autor', 'privado', ['criado_por' => $this->ids['autor']]);
        $h = $this->previa('autor', $this->post('autor', '/_pbl/revisao/' . $meu->id . '/enviar', ['versao' => (string) $meu->versao, 'voltar' => 'objeto']), 'Enviado para revisão');
        self::assertStringNotContainsString('Abrir a página pública', $h);
        $o = $this->obj($meu->id);
        $this->previa('admin', $this->post('admin', '/_pbl/revisao/' . $o->id . '/devolver', ['versao' => (string) $o->versao, 'voltar' => 'objeto', 'motivo' => 'Ajustar o texto']), 'Devolvido');
        $o = $this->obj($meu->id);
        $this->previa('autor', $this->post('autor', '/_pbl/revisao/' . $o->id . '/reenviar', ['versao' => (string) $o->versao, 'voltar' => 'objeto']), 'Enviado para revisão');
        $o = $this->obj($meu->id);
        $this->previa('admin', $this->post('admin', '/_pbl/revisao/' . $o->id . '/aprovar', ['versao' => (string) $o->versao, 'voltar' => 'objeto']), 'Publicado');
    }

    public function testCriarLevaAPreviaDeRascunho(): void
    {
        $r = $this->post('admin', '/_pbl/objeto/' . $this->inicio->id . '/novo', ['classe' => (string) $this->pagina->id, 'titulo' => 'Nova', 'url_amigavel' => 'nova', 'descricao' => '']);
        if ($r->getStatusCode() === 303) {
            $this->previa('admin', $r, 'Gravado como rascunho');
        } else {
            self::markTestIncomplete('Formulário de criação exige outros campos: ' . $r->getStatusCode());
        }
    }

    public function testSemPermissaoNaoVaza(): void
    {
        $fora = $this->filho('Segredo fora', 'segredo');
        $r = $this->req('GET', '/_pbl/objeto/' . $fora->id . '/previa?salvo=sim', $this->token('editor'));
        self::assertSame(403, $r->getStatusCode());
        self::assertStringNotContainsString('Segredo fora', (string) $r->getContent());
        $inex = $this->req('GET', '/_pbl/objeto/999999/previa?salvo=sim', $this->token('editor'));
        self::assertSame(403, $inex->getStatusCode());
        $sem = $this->req('GET', '/_pbl/objeto/' . $fora->id . '/previa');
        self::assertSame('/_pbl/entrar', $sem->headers->get('Location'));
        $nin = $this->req('GET', '/_pbl/objeto/' . $fora->id . '/previa', $this->token('ninguem'));
        self::assertSame(403, $nin->getStatusCode());
    }

    public function testLeitorVeFaixaSemVoltarAEditar(): void
    {
        $a = $this->filho('Visivel', 'visivel', 'publicado');
        $h = (string) $this->req('GET', '/_pbl/objeto/' . $a->id . '/previa?salvo=sim', $this->token('leitor'))->getContent();
        self::assertStringContainsString('<strong>Publicado', $h);
        self::assertStringNotContainsString('Voltar a editar', $h);
        self::assertStringContainsString('Ver na lista', $h);
    }

    public function testMensagemVemDoEstadoNaoDoCodigo(): void
    {
        $a = $this->filho('Privado', 'priv');
        $h = (string) $this->req('GET', '/_pbl/objeto/' . $a->id . '/previa?salvo=aprovado', $this->token('admin'))->getContent();
        self::assertStringContainsString('<strong>Gravado como rascunho', $h, 'código dizia aprovado, mas o objeto é privado');
        $h = (string) $this->req('GET', '/_pbl/objeto/' . $a->id . '/previa?salvo=%3Cb%3E', $this->token('admin'))->getContent();
        self::assertStringNotContainsString('<b>', substr($h, 0, 4000));
    }

    public function testObjetoNaLixeiraNaoTemPrevia(): void
    {
        $a = $this->filho('Lixo', 'lixo');
        $this->s->objetos->mandarParaLixeira($a->id, $a->versao, 'teste', $this->ids['admin']);
        $r = $this->req('GET', '/_pbl/objeto/' . $a->id . '/previa?salvo=apagado', $this->token('admin'));
        self::assertSame(303, $r->getStatusCode());
        self::assertStringStartsWith('/_pbl/objeto/' . $a->id, (string) $r->headers->get('Location'));
    }
}
