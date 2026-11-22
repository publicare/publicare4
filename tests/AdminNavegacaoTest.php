<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Admin\AcaoDeLinha;
use Pbl\Admin\DadosDaLinha;
use Pbl\Admin\IconesDeClasse;
use Pbl\Admin\LinhaDeConteudo;
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

/** Tela Conteúdo (navegação por níveis, filtros, ações por linha e em lote), de ponta a ponta. */
final class AdminNavegacaoTest extends TestCase
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

    /** @param array<string, mixed> $meta */
    private function filho(Objeto $pai, string $titulo, string $url, string $status = 'publicado', array $meta = []): Objeto
    {
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

    /**
     * @param array<string, mixed> $campos
     * @param array<string, string> $cab
     */
    private function req(string $metodo, string $caminho, ?string $token = null, array $campos = [], array $cab = self::ORIGEM): Response
    {
        $cookies = $token === null ? [] : ['__Host-pbl4' => $token];
        $r = Request::create('https://www.exemplo.org' . $caminho, $metodo, $campos, $cookies, [], ['REMOTE_ADDR' => '198.51.100.7'] + $cab);

        return (new Aplicacao($this->caminhos))->tratar($r);
    }

    private function html(Response $r): string
    {
        return (string) $r->getContent();
    }

    /**
     * @param list<Objeto> $alvos
     * @param array<string, mixed> $extra
     */
    private function lote(string $login, string $acao, array $alvos, array $extra = []): Response
    {
        $t = $this->token($login);
        $versoes = [];
        foreach ($alvos as $o) {
            $versoes[$o->id] = (string) $o->versao;
        }

        return $this->req('POST', '/_pbl/conteudo/acao', $t, $extra + [
            '_csrf' => $this->csrf($t), 'acao' => $acao, 'nivel' => (string) $this->inicio->id,
            'ids' => array_map(static fn (Objeto $o): string => (string) $o->id, $alvos), 'versao' => $versoes,
        ]);
    }

    public function testSemLoginMandaParaEntrar(): void
    {
        foreach (['/_pbl/conteudo', '/_pbl/conteudo/' . $this->inicio->id] as $c) {
            $r = $this->req('GET', $c);
            self::assertSame(303, $r->getStatusCode());
            self::assertSame('/_pbl/entrar', $r->headers->get('Location'));
        }
        $r = $this->req('POST', '/_pbl/conteudo/acao', null, ['acao' => 'publicar']);
        self::assertSame('/_pbl/entrar', $r->headers->get('Location'));
    }

    public function testEntradaVaiParaOUnicoPontoDeCadaPerfil(): void
    {
        $adm = $this->req('GET', '/_pbl/conteudo', $this->token('admin'));
        self::assertSame('/_pbl/conteudo/' . $this->inicio->id, $adm->headers->get('Location'));
        $ed = $this->req('GET', '/_pbl/conteudo', $this->token('editor'));
        self::assertSame('/_pbl/conteudo/' . $this->sobre->id, $ed->headers->get('Location'), 'quem só tem uma seção começa nela');
        $nin = $this->req('GET', '/_pbl/conteudo', $this->token('ninguem'));
        self::assertSame(200, $nin->getStatusCode());
        self::assertStringContainsString('ainda não recebeu acesso', $this->html($nin));
    }

    public function testNivelMostraFilhosMigalhasESubir(): void
    {
        $filho = $this->filho($this->sobre, 'Equipe', 'sobre/equipe');
        $html = $this->html($this->req('GET', '/_pbl/conteudo/' . $this->sobre->id, $this->token('admin')));
        self::assertStringContainsString('href="/_pbl/conteudo/' . $filho->id . '"', $html);
        self::assertStringContainsString('Equipe', $html);
        self::assertStringContainsString('Subir um nível', $html);
        self::assertStringContainsString('href="/_pbl/conteudo/' . $this->inicio->id . '"', $html, 'migalha do pai');
        self::assertStringContainsString('href="/_pbl/conteudo"', $html, 'migalha Conteúdo');
        self::assertStringContainsString('Criar conteúdo aqui dentro', $html, 'vocabulário de usuário (D12)');
        self::assertStringContainsString('Editar este conteúdo', $html);
        self::assertStringContainsString('data-pbl-marcar-todos', $html);
        self::assertStringNotContainsString('onclick=', $html);
    }

    public function testEditorNaoVeAcimaDaSuaSecao(): void
    {
        $html = $this->html($this->req('GET', '/_pbl/conteudo/' . $this->sobre->id, $this->token('editor')));
        self::assertStringNotContainsString('/_pbl/conteudo/' . $this->inicio->id . '"', $html);
        self::assertStringContainsString('disabled', $html, 'subir desativado');
        $r = $this->req('GET', '/_pbl/conteudo/' . $this->inicio->id, $this->token('editor'));
        self::assertSame(403, $r->getStatusCode());
        $inex = $this->req('GET', '/_pbl/conteudo/999999', $this->token('editor'));
        self::assertSame(403, $inex->getStatusCode(), 'mesma resposta para inexistente');
        $sem = static fn (string $h): string => (string) preg_replace('/name="_csrf" value="[0-9a-f]+"/', '', $h);
        self::assertSame($sem($this->html($r)), $sem($this->html($inex)));
    }

    public function testLeitorVeMasNaoTemAcoes(): void
    {
        $this->filho($this->inicio, 'Rascunho', 'rascunho', 'privado');
        $html = $this->html($this->req('GET', '/_pbl/conteudo/' . $this->inicio->id, $this->token('leitor')));
        self::assertStringContainsString('Rascunho', $html);
        self::assertStringNotContainsString('/editar', $html);
        self::assertStringNotContainsString('Criar conteúdo aqui dentro', $html);
                self::assertStringNotContainsString('/apagar', $html);
    }

    public function testFiltrosBuscaOrdemEPaginacao(): void
    {
        $this->filho($this->inicio, 'Alfa', 'alfa', 'privado');
        $this->filho($this->inicio, 'Beta', 'beta', 'publicado');
        $this->filho($this->inicio, 'Gama', 'gama', 'submetido');
        $t = $this->token('admin');
        $base = '/_pbl/conteudo/' . $this->inicio->id;
        $h = $this->html($this->req('GET', $base . '?estado=privado', $t));
        self::assertStringContainsString('Alfa', $h);
        self::assertStringNotContainsString('Beta</a>', $h);
        $h = $this->html($this->req('GET', $base . '?q=gam', $t));
        self::assertStringContainsString('Gama</a>', $h);
        self::assertStringNotContainsString('Alfa</a>', $h);
        $h = $this->html($this->req('GET', $base . '?estado=submetido', $t));
        self::assertStringContainsString('em revisão', $h);
        $h = $this->html($this->req('GET', $base . '?q=zzzz', $t));
        self::assertStringContainsString('Nenhum conteúdo com estes filtros', $h);
        for ($i = 0; $i < 26; ++$i) {
            $this->filho($this->inicio, 'Item ' . $i, 'item-' . $i);
        }
        $h = $this->html($this->req('GET', $base . '?q=item', $t));
        self::assertStringContainsString('Próximos', $h);
        $p2 = $this->html($this->req('GET', $base . '?q=item&inicio=25', $t));
        self::assertStringContainsString('Anteriores', $p2);
        self::assertStringNotContainsString('Próximos', $p2);
        // Valores estranhos caem no padrão, sem erro.
        self::assertSame(200, $this->req('GET', $base . '?estado=x&ordem=y&por=3&classe=abc&inicio=-5', $t)->getStatusCode());
    }

    public function testAgendadoEExpiradoViramBadges(): void
    {
        $this->filho($this->inicio, 'Futuro', 'futuro', 'publicado', ['data_publicacao' => gmdate('c', time() + 86400 * 30)]);
        $this->filho($this->inicio, 'Vencido', 'vencido', 'publicado', ['data_validade' => gmdate('c', time() - 86400)]);
        $t = $this->token('admin');
        $base = '/_pbl/conteudo/' . $this->inicio->id;
        $h = $this->html($this->req('GET', $base . '?estado=agendado', $t));
        self::assertStringContainsString('Futuro</a>', $h);
        self::assertStringContainsString('agendado para', $h);
        self::assertStringNotContainsString('Vencido</a>', $h);
        $h = $this->html($this->req('GET', $base . '?estado=expirado', $t));
        self::assertStringContainsString('Vencido</a>', $h);
        self::assertStringContainsString('expirado', $h);
    }

    public function testPublicarEDespublicarEmLoteComVersao(): void
    {
        $a = $this->filho($this->inicio, 'A', 'a', 'privado');
        $b = $this->filho($this->inicio, 'B', 'b', 'privado');
        $r = $this->lote('admin', 'publicar', [$a, $b]);
        self::assertSame(303, $r->getStatusCode());
        self::assertStringContainsString('ok=publicar', (string) $r->headers->get('Location'));
        self::assertStringContainsString('n=2', (string) $r->headers->get('Location'));
        self::assertSame('publicado', $this->obj($a->id)->status);
        $r = $this->lote('admin', 'despublicar', [$this->obj($a->id)]);
        self::assertSame('privado', $this->obj($a->id)->status);
        self::assertSame('publicado', $this->obj($b->id)->status);
        // Versão velha: ignorado, nada muda.
        $r = $this->lote('admin', 'publicar', [$a]);
        self::assertStringContainsString('f=1', (string) $r->headers->get('Location'));
        self::assertSame('privado', $this->obj($a->id)->status);
    }

    public function testCsrfEOrigemObrigatorios(): void
    {
        $a = $this->filho($this->inicio, 'A', 'a', 'privado');
        $t = $this->token('admin');
        $campos = ['acao' => 'publicar', 'ids' => [(string) $a->id], 'versao' => [$a->id => (string) $a->versao], 'nivel' => (string) $this->inicio->id];
        self::assertSame(403, $this->req('POST', '/_pbl/conteudo/acao', $t, $campos + ['_csrf' => 'errado'])->getStatusCode());
        self::assertSame(403, $this->req('POST', '/_pbl/conteudo/acao', $t, $campos + ['_csrf' => $this->csrf($t)], ['HTTP_ORIGIN' => 'https://mau.example'])->getStatusCode());
        self::assertSame('privado', $this->obj($a->id)->status);
    }

    public function testAutorEnviaParaRevisaoMasNaoPublica(): void
    {
        $meu = $this->filho($this->inicio, 'Meu', 'meu', 'privado', ['criado_por' => $this->ids['autor']]);
        $outro = $this->filho($this->inicio, 'Alheio', 'alheio', 'privado');
        $h = $this->html($this->req('GET', '/_pbl/conteudo/' . $this->inicio->id, $this->token('autor')));
        self::assertStringContainsString('Enviar para revisão', $h);
        $r = $this->lote('autor', 'publicar', [$meu]);
        self::assertSame('privado', $this->obj($meu->id)->status);
        self::assertStringContainsString('f=1', (string) $r->headers->get('Location'));
        $this->lote('autor', 'enviar', [$meu, $outro]);
        self::assertSame('submetido', $this->obj($meu->id)->status);
        self::assertSame('privado', $this->obj($outro->id)->status, 'não é dele');
    }

    public function testLoteNaoTocaForaDaAreaPermitida(): void
    {
        $fora = $this->filho($this->inicio, 'Fora', 'fora', 'privado');
        $dentro = $this->filho($this->sobre, 'Dentro', 'sobre/dentro', 'privado');
        $t = $this->token('editor');
        $r = $this->req('POST', '/_pbl/conteudo/acao', $t, [
            '_csrf' => $this->csrf($t), 'acao' => 'publicar', 'nivel' => (string) $this->sobre->id,
            'ids' => [(string) $fora->id, (string) $dentro->id, '999999'], 'versao' => [$fora->id => (string) $fora->versao, $dentro->id => (string) $dentro->versao],
        ]);
        self::assertSame('privado', $this->obj($fora->id)->status);
        self::assertSame('publicado', $this->obj($dentro->id)->status);
        self::assertStringContainsString('n=1', (string) $r->headers->get('Location'));
        self::assertStringContainsString('f=2', (string) $r->headers->get('Location'));
    }

    public function testLeitorNaoAltera(): void
    {
        $a = $this->filho($this->inicio, 'A', 'a', 'privado');
        $this->lote('leitor', 'publicar', [$a]);
        self::assertSame('privado', $this->obj($a->id)->status);
    }

    public function testExcluirPedeConfirmacaoEVaiParaLixeira(): void
    {
        $a = $this->filho($this->inicio, 'Apagar', 'apagar');
        $conf = $this->lote('admin', 'excluir', [$a]);
        self::assertSame(200, $conf->getStatusCode());
        self::assertStringContainsString('Apagar', $this->html($conf));
        self::assertSame('publicado', $this->obj($a->id)->status);
        $r = $this->lote('admin', 'excluir', [$a], ['confirmar' => '1']);
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('apagado', $this->obj($a->id)->status);
        $h = $this->html($this->req('GET', '/_pbl/conteudo/' . $this->inicio->id, $this->token('admin')));
        self::assertStringNotContainsString('Apagar</a>', $h);
        $h = $this->html($this->req('GET', '/_pbl/conteudo/' . $this->inicio->id . '?estado=apagado', $this->token('admin')));
        self::assertStringContainsString('Apagar</a>', $h);
        self::assertStringContainsString('Tirar da lixeira', $h);
    }

    public function testExcluirComFilhosEIgnorado(): void
    {
        $this->filho($this->sobre, 'Dentro', 'sobre/dentro');
        $sobre = $this->obj($this->sobre->id);
        $r = $this->lote('admin', 'excluir', [$sobre], ['confirmar' => '1']);
        self::assertNotSame('apagado', $this->obj($this->sobre->id)->status);
        self::assertStringContainsString('f=1', (string) $r->headers->get('Location'));
    }

    public function testPedidoMalFormadoEErroSemQuebrar(): void
    {
        $t = $this->token('admin');
        $r = $this->req('POST', '/_pbl/conteudo/acao', $t, ['_csrf' => $this->csrf($t), 'acao' => 'apagar-tudo', 'ids' => ['1']]);
        self::assertSame(400, $r->getStatusCode());
        $r = $this->req('POST', '/_pbl/conteudo/acao', $t, ['_csrf' => $this->csrf($t), 'acao' => 'publicar', 'ids' => 'x']);
        self::assertSame(400, $r->getStatusCode());
        $r = $this->req('POST', '/_pbl/conteudo/acao', $t, ['_csrf' => $this->csrf($t), 'acao' => 'publicar']);
        self::assertSame(303, $r->getStatusCode());
        self::assertStringContainsString('ok=nada', (string) $r->headers->get('Location'));
    }

    public function testLinhaNovaMostraIconeNomeDaClasseEEstadoSemRuido(): void
    {
        $f = $this->filho($this->inicio, 'Varanda com cadeiras', 'varanda', 'privado');
        $h = $this->html($this->req('GET', '/_pbl/conteudo/' . $this->inicio->id, $this->token('admin')));

        self::assertStringContainsString('<ul class="pbl-lista-conteudo"', $h);
        self::assertStringNotContainsString('<table', explode('pbl-lista-conteudo', $h, 2)[1]);
        self::assertStringContainsString('class="pbl-icone" role="img" title="pagina" aria-label="Tipo: pagina"', $h);
        self::assertStringContainsString('<span class="pbl-lc-classe">pagina</span>', $h);
        self::assertStringContainsString('>privado</span>', $h);
        self::assertStringNotContainsString('Por:', $h);
        self::assertStringNotContainsString('0 filhos', $h);
        self::assertStringContainsString('name="ids[]" value="' . $f->id . '" form="lote"', $h);
    }

    public function testSoTresAcoesAVistaEOrestoNoMenuMais(): void
    {
        $f = $this->filho($this->sobre, 'Equipe', 'sobre/equipe', 'publicado');
        $h = $this->html($this->req('GET', '/_pbl/conteudo/' . $this->sobre->id, $this->token('admin')));
        $linha = explode('<li class="pbl-lc', explode('Selecionar Equipe', $h, 2)[1], 2)[0];
        [$vista, $menu] = explode('<details class="pbl-mais">', $linha, 2);

        self::assertStringContainsString('aria-label="Editar: Equipe"', $vista);
        self::assertStringContainsString('aria-label="Tirar do ar: Equipe"', $vista);
        self::assertStringNotContainsString('Copiar', $vista);
        self::assertStringNotContainsString('Mover', $vista);
        self::assertStringNotContainsString('Apagar', $vista);
        foreach (['Abrir pasta', 'Histórico', 'Copiar', 'Recortar', 'Mover', 'Apagar'] as $rotulo) {
            self::assertStringContainsString($rotulo, $menu);
        }
        self::assertStringContainsString('/_pbl/objeto/' . $f->id . '/apagar', $menu, 'Apagar segue para a confirmação existente');
        self::assertLessThan(strpos($menu, 'Apagar'), strpos($menu, 'Recortar'));
    }

    public function testMenuMaisRespeitaPermissoesPorPerfil(): void
    {
        $f = $this->filho($this->inicio, 'Rascunho', 'rascunho', 'privado');
        $linha = static function (string $h): string {
            return explode('<li class="pbl-lc', explode('Selecionar Rascunho', $h, 2)[1] ?? '', 2)[0];
        };
        $f2 = $this->filho($this->sobre, 'Rascunho', 'sobre/rascunho', 'privado');
        $editor = $linha($this->html($this->req('GET', '/_pbl/conteudo/' . $this->sobre->id, $this->token('editor'))));
        self::assertStringContainsString('/_pbl/objeto/' . $f2->id . '/editar', $editor);
        self::assertStringContainsString('aria-label="Publicar: Rascunho"', $editor);

        $leitor = $this->html($this->req('GET', '/_pbl/conteudo/' . $this->inicio->id, $this->token('leitor')));
        self::assertStringNotContainsString('pbl-lc-acao--primaria', $leitor);
        self::assertStringNotContainsString('/apagar', $leitor);
        self::assertStringNotContainsString('name="ids[]" value="' . $f->id . '" form="lote"', $leitor, 'sem lote para o leitor');
        self::assertStringNotContainsString('pbl-lc-todos', $leitor);
    }

    public function testTituloEIconeDaClasseComHtmlSaemEscapadosNaLinha(): void
    {
        $this->filho($this->inicio, '<img src=x onerror=1>', 'xss2');
        $h = $this->html($this->req('GET', '/_pbl/conteudo/' . $this->inicio->id, $this->token('admin')));
        self::assertStringNotContainsString('<img src=x', $h);
        self::assertStringContainsString('&lt;img src=x onerror=1&gt;', $h);
    }

    public function testPaginacaoMostraIntervaloComALinhaNova(): void
    {
        for ($i = 0; $i < 26; ++$i) {
            $this->filho($this->inicio, 'Pg ' . $i, 'pg-' . $i);
        }
        $h = $this->html($this->req('GET', '/_pbl/conteudo/' . $this->inicio->id . '?q=pg', $this->token('admin')));
        self::assertStringContainsString('Mostrando 1 a 25 de 26', $h);
        self::assertSame(25, substr_count($h, 'class="pbl-lc"') + substr_count($h, 'class="pbl-lc pbl-lc-sem-sel"'));
    }

    public function testTituloComHtmlEhEscapado(): void
    {
        $this->filho($this->inicio, '<script>alert(1)</script>', 'xss');
        $h = $this->html($this->req('GET', '/_pbl/conteudo/' . $this->inicio->id, $this->token('admin')));
        self::assertStringNotContainsString('<script>alert(1)', $h);
        self::assertStringContainsString('&lt;script&gt;', $h);
    }

    public function testMenuETelaInicialTemEntradaParaConteudo(): void
    {
        $h = $this->html($this->req('GET', '/_pbl/', $this->token('admin')));
        self::assertStringContainsString('<a href="/_pbl/conteudo">Conteúdo</a>', $h, 'item Conteúdo no menu');
        self::assertMatchesRegularExpression('/Continuar de onde parou|Ver o site|Criar página/', $h, 'uma ação principal no Início');
    }

    public function testBarraColapsaAteXlSemLarguraFixa(): void
    {
        $h = $this->html($this->req('GET', '/_pbl/conteudo/' . $this->inicio->id, $this->token('admin')));
        self::assertStringContainsString('<details class="pbl-menu" open', $h, 'menu sem JavaScript em qualquer largura');
        self::assertStringNotContainsString('navbar-toggler', $h);
        self::assertStringContainsString('viewport-fit=cover', $h);
        self::assertDoesNotMatchRegularExpression('/style="[^"]*width\s*:\s*\d/', $h, 'sem largura fixa inline');
        self::assertStringContainsString('class="pbl-lista-conteudo"', $h);
        $css = (string) file_get_contents(__DIR__ . '/../public/_pbl/assets/pbl.css');
        self::assertStringContainsString('safe-area-inset-left', $css);
        self::assertStringContainsString('@media (min-width: 1200px)', $css);
    }

    public function testLinhaMostraIconeNomeDaClasseEEstadoESoTresAcoesAVista(): void
    {
        $this->filho($this->inicio, 'Linha nova', 'linha-nova', 'publicado');
        $h = $this->html($this->req('GET', '/_pbl/conteudo/' . $this->inicio->id, $this->token('admin')));
        self::assertStringContainsString('<ul class="pbl-lista-conteudo"', $h);
        self::assertStringContainsString('class="pbl-icone" role="img" title="pagina"', $h);
        self::assertStringContainsString('<span class="pbl-lc-classe">pagina</span>', $h);
        self::assertMatchesRegularExpression('/class="pbl-lc-selo"><span class="badge[^>]*>publicado/', $h);
        self::assertStringContainsString('<time class="pbl-lc-data"', $h);
        self::assertStringNotContainsString('Por: —', $h);
        self::assertStringNotContainsString('>—</td>', $h);
        self::assertStringNotContainsString(' 0 filhos', $h);
        preg_match('/<li class="pbl-lc[^"]*">(?:(?!<\/li><li class="pbl-lc).)*Linha nova.*?<\/li><\/ul><\/div>|<li class="pbl-lc[^"]*">(?:(?!<li class="pbl-lc).)*?Linha nova.*?<\/ul><\/details><\/div><\/li>/s', $h, $m);
        self::assertNotEmpty($m, 'linha encontrada');
        $linha = $m[0];
        [$aVista, $menu] = explode('<details class="pbl-mais">', $linha, 2);
        self::assertSame(3, substr_count($aVista, 'class="pbl-lc-acao '), 'editar, estado e abrir no site');
        self::assertStringContainsString('/editar', $aVista);
        self::assertStringContainsString('Tirar do ar', $aVista);
        self::assertStringContainsString('Abrir no site', $aVista);
        foreach (['Abrir pasta', 'Histórico', 'Copiar', 'Recortar', 'Mover', 'Apagar'] as $rotulo) {
            self::assertStringContainsString($rotulo, $menu);
            self::assertStringNotContainsString('>' . $rotulo, $aVista);
        }
        self::assertGreaterThan(strpos($menu, 'Mover'), strpos($menu, 'Apagar'), 'Apagar é o último do menu');
        self::assertStringContainsString('pbl-lc-item--perigo', $menu);
    }

    public function testLinhaSemJavaScriptNemEstiloEmLinha(): void
    {
        $this->filho($this->inicio, 'Qualquer', 'qualquer');
        $h = $this->html($this->req('GET', '/_pbl/conteudo/' . $this->inicio->id, $this->token('admin')));
        $lista = substr($h, (int) strpos($h, '<ul class="pbl-lista-conteudo"'));
        self::assertDoesNotMatchRegularExpression('/\sstyle=|\son[a-z]+=/i', $lista);
    }

    public function testNomeDaClasseComHtmlEhEscapadoNaLinha(): void
    {
        $c = $this->s->classes->porNome('pagina');
        self::assertNotNull($c);
        $h = LinhaDeConteudo::renderizar(
            new DadosDaLinha(7, '<b>t</b>', '/x', $c, '<img src=x onerror=alert(1)>', '', '2026-11-22T01:36:00+00:00', '22/11 01:36', '22/11/2026 01:36', 2),
            [AcaoDeLinha::link('Editar', 'editar', AcaoDeLinha::PRIMARIA, '/x/editar')],
        );
        self::assertStringNotContainsString('<img', $h);
        self::assertStringNotContainsString('<b>t', $h);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $h);
        self::assertStringContainsString('2 filhos', $h);
        self::assertStringContainsString('22/11 01:36', $h);
    }

    public function testIconePadraoPorTipoFixoEGenerico(): void
    {
        foreach (['pasta' => 'pasta', 'pagina' => 'documento', 'formulario' => 'formulario', 'arquivo' => 'arquivo', 'imagem' => 'imagem', 'link' => 'link'] as $tipo => $chave) {
            $c = $this->s->classes->porNome($tipo);
            if ($c !== null && $c->icone === '') {
                self::assertSame($chave, IconesDeClasse::resolver($c), $tipo);
            }
        }
        self::assertSame('generico', IconesDeClasse::resolver(null));
        self::assertStringContainsString('<svg', IconesDeClasse::svg('generico'));
    }

    public function testLeitorNaoTemAcoesDeEscritaNoMenu(): void
    {
        $this->filho($this->inicio, 'Somente leitura', 'somente-leitura', 'privado');
        $h = $this->html($this->req('GET', '/_pbl/conteudo/' . $this->inicio->id, $this->token('leitor')));
        $lista = substr($h, (int) strpos($h, '<ul class="pbl-lista-conteudo"'));
        $lista = substr($lista, 0, (int) strpos($lista, '</ul></li></ul>') ?: null);
        foreach (['Recortar', 'Apagar', 'Publicar', 'Editar', 'type="checkbox"'] as $x) {
            self::assertStringNotContainsString($x, $lista, $x);
        }
        self::assertStringContainsString('Somente leitura', $lista);
    }
}
