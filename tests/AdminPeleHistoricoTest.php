<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Perfil;
use Pbl\Acesso\SessaoRepositorio;
use Pbl\Aplicacao;
use Pbl\Caminhos;
use Pbl\Instalacao\Instalador;
use Pbl\Publicacao\GerenciadorDePeles;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Histórico, diferença, voltar, rascunho e pré-visualização do editor de peles (ADR 0012 §9, fase 5j). */
final class AdminPeleHistoricoTest extends TestCase
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

    private function token(string $login): string
    {
        $token = $this->tokenSemElevar($login);
        $sessao = $this->s->sessoes()->validar($token);
        if ($sessao !== null) {
            $this->s->modoElevado()->abrirParaTestes($sessao);
        }

        return $token;
    }

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
        return '/_pbl/site/' . $this->siteId . '/peles/basica';
    }

    private function html(Response $r): string
    {
        return (string) $r->getContent();
    }

    public function testSalvarCriarEApagarGeramUmaVersaoCadaComAHashEOAutor(): void
    {
        $t = $this->token('admin');
        $b = $this->base();
        self::assertSame([], $this->s->peles->historico('basica', 'temporario'));

        self::assertSame(303, $this->post($t, "{$b}/novo", ['nome' => 'temporario', 'conteudo' => '<p>um</p>'])->getStatusCode());
        $h1 = $this->s->peles->historico('basica', 'temporario');
        self::assertCount(1, $h1);
        self::assertSame('criar', $h1[0]['acao']);
        self::assertSame($this->ids['admin'], $h1[0]['usuario_id']);
        self::assertSame(hash('sha256', '<p>um</p>'), $h1[0]['hash']);

        $base = hash('sha256', '<p>um</p>');
        self::assertSame(303, $this->post($t, "{$b}/molde", ['nome' => 'temporario', 'conteudo' => '<p>dois</p>', 'base' => $base])->getStatusCode());
        $h2 = $this->s->peles->historico('basica', 'temporario');
        self::assertCount(2, $h2);
        self::assertSame('salvar', $h2[0]['acao']);
        self::assertSame(2, $h2[0]['versao']);

        self::assertSame(303, $this->post($t, "{$b}/apagar", ['nome' => 'temporario'])->getStatusCode());
        $h3 = $this->s->peles->historico('basica', 'temporario');
        self::assertCount(3, $h3);
        self::assertSame('apagar', $h3[0]['acao']);
        // A versão apagada guarda o conteúdo que havia antes de apagar (para dar para voltar).
        $v3 = $this->s->peles->versaoDoHistorico('basica', 'temporario', 3) ?? self::fail();
        self::assertSame('<p>dois</p>', $v3['conteudo']);
    }

    public function testTelaDeHistoricoMostraAsVersoesEOLinkDeDiferenca(): void
    {
        $t = $this->token('admin');
        $b = $this->base();
        $base = hash('sha256', (string) file_get_contents($this->caminhos->peles() . '/basica/padrao.pbl'));
        $this->post($t, "{$b}/molde", ['nome' => 'padrao', 'conteudo' => '<p>v1</p>', 'base' => $base]);
        $this->post($t, "{$b}/molde", ['nome' => 'padrao', 'conteudo' => '<p>v2</p>', 'base' => hash('sha256', '<p>v1</p>')]);
        $html = $this->html($this->req('GET', "{$b}/molde/historico?nome=padrao", $t));
        self::assertStringContainsString('Versão 2', $html);
        self::assertStringContainsString('Versão 1', $html);
        self::assertStringContainsString('Voltar a esta versão', $html);
        self::assertStringContainsString('diferenca', $html);
    }

    public function testDiferencaEntreVersoesEscapaHtmlMalicioso(): void
    {
        $t = $this->token('admin');
        $b = $this->base();
        $this->post($t, "{$b}/novo", ['nome' => 'x', 'conteudo' => "<p>linha 1</p>\n<p>igual</p>\n"]);
        $base = hash('sha256', "<p>linha 1</p>\n<p>igual</p>\n");
        $malicioso = "<p>linha 1</p>\n<p>igual</p>\n<@-- </script><script>alert(1)</script> --@>\n";
        $this->post($t, "{$b}/molde", ['nome' => 'x', 'conteudo' => $malicioso, 'base' => $base]);

        $r = $this->req('GET', "{$b}/molde/diferenca?nome=x&de=1&para=2", $t);
        self::assertSame(200, $r->getStatusCode());
        $html = $this->html($r);
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('pbl-diff-add', $html);
    }

    public function testVoltarAVersaoQueNaoPassaMaisPeloParserERecusada(): void
    {
        $t = $this->token('admin');
        $b = $this->base();
        $this->post($t, "{$b}/novo", ['nome' => 'camada', 'conteudo' => '<p>camada</p>']);
        $this->post($t, "{$b}/novo", ['nome' => 'filho', 'conteudo' => '<@estende "camada"@>']);
        // Versão 1 de "filho" ainda estende "camada". Troca para algo que não estende mais nada (para poder apagar "camada").
        $baseFilho = hash('sha256', '<@estende "camada"@>');
        $this->post($t, "{$b}/molde", ['nome' => 'filho', 'conteudo' => '<p>sem layout</p>', 'base' => $baseFilho]);
        self::assertSame(303, $this->post($t, "{$b}/apagar", ['nome' => 'camada'])->getStatusCode());

        // Agora voltar "filho" para a versão 1 (que depende de "camada", que não existe mais) tem de ser recusado.
        $r = $this->post($t, "{$b}/molde/voltar", ['nome' => 'filho', 'versao' => '1', 'base' => hash('sha256', '<p>sem layout</p>')]);
        self::assertSame(422, $r->getStatusCode());
        self::assertSame('<p>sem layout</p>', file_get_contents($this->caminhos->peles() . '/basica/filho.pbl'));
    }

    public function testVoltarExigeCsrfEModoElevado(): void
    {
        $tElevado = $this->token('admin');
        $b = $this->base();
        // Duas edições: versão 1 = "<p>v1</p>", versão 2 (a atual) = "<p>v2</p>". Voltar para a versão 1 é uma
        // mudança de verdade (não um "voltar" que já é o que está lá).
        $base = hash('sha256', (string) file_get_contents($this->caminhos->peles() . '/basica/padrao.pbl'));
        $this->post($tElevado, "{$b}/molde", ['nome' => 'padrao', 'conteudo' => '<p>v1</p>', 'base' => $base]);
        $this->post($tElevado, "{$b}/molde", ['nome' => 'padrao', 'conteudo' => '<p>v2</p>', 'base' => hash('sha256', '<p>v1</p>')]);

        // Sem CSRF: 403, nada volta.
        $r1 = $this->post($tElevado, "{$b}/molde/voltar", ['nome' => 'padrao', 'versao' => '1', 'base' => hash('sha256', '<p>v2</p>')], false);
        self::assertSame(403, $r1->getStatusCode());
        self::assertSame('<p>v2</p>', file_get_contents($this->caminhos->peles() . '/basica/padrao.pbl'));

        // Com CSRF mas sem o modo elevado (sessão nova): pede a senha.
        $tSemElevar = $this->tokenSemElevar('admin');
        $r2 = $this->post($tSemElevar, "{$b}/molde/voltar", ['nome' => 'padrao', 'versao' => '1', 'base' => hash('sha256', '<p>v2</p>')]);
        self::assertSame(422, $r2->getStatusCode());
        self::assertStringContainsString('Confirme sua senha', $this->html($r2));
        self::assertSame('<p>v2</p>', file_get_contents($this->caminhos->peles() . '/basica/padrao.pbl'));

        // Com a senha certa, volta para a versão 1.
        $r3 = $this->post($tSemElevar, "{$b}/molde/voltar", ['nome' => 'padrao', 'versao' => '1', 'base' => hash('sha256', '<p>v2</p>'), 'senha' => self::SENHA]);
        self::assertSame(303, $r3->getStatusCode());
        self::assertSame('<p>v1</p>', file_get_contents($this->caminhos->peles() . '/basica/padrao.pbl'));
        self::assertContains('pele.molde.voltar', array_column($this->s->auditoria->recentes(), 'acao'));
        // Voltar grava uma versão nova (versão 3), com o conteúdo da versão 1; o histórico não é reescrito.
        $v3 = $this->s->peles->versaoDoHistorico('basica', 'padrao', 3) ?? self::fail();
        self::assertSame('<p>v1</p>', $v3['conteudo']);
        self::assertSame('voltar', $v3['acao']);
    }

    public function testRascunhoNaoAlteraOArquivoNemOCacheNemAPaginaPublica(): void
    {
        $t = $this->token('admin');
        $b = $this->base();
        $antes = (string) file_get_contents($this->caminhos->peles() . '/basica/padrao.pbl');
        $site = $this->s->sites->porId($this->siteId) ?? self::fail();
        $arquivoPagina = $this->caminhos->pagina($site->id, '');
        $paginaAntes = is_file($arquivoPagina) ? file_get_contents($arquivoPagina) : null;

        $r = $this->post($t, "{$b}/molde/rascunho", ['nome' => 'padrao', 'conteudo' => '<h1>RASCUNHO SÓ MEU</h1>']);
        self::assertSame(303, $r->getStatusCode());

        self::assertSame($antes, file_get_contents($this->caminhos->peles() . '/basica/padrao.pbl'), 'o arquivo do molde não muda');
        self::assertSame($paginaAntes, is_file($arquivoPagina) ? file_get_contents($arquivoPagina) : null, 'a página publicada não muda');
        self::assertSame([], $this->s->peles->historico('basica', 'padrao'), 'rascunho não gera versão');
        $rascunho = $this->s->peles->rascunho('basica', 'padrao');
        self::assertNotNull($rascunho);
        self::assertSame('<h1>RASCUNHO SÓ MEU</h1>', $rascunho['conteudo']);

        // Descartar o rascunho também não toca em nada.
        $this->post($t, "{$b}/molde/rascunho", ['nome' => 'padrao', 'conteudo' => '', 'descartar' => '1', 'rascunho_base' => hash('sha256', '<h1>RASCUNHO SÓ MEU</h1>')]);
        self::assertNull($this->s->peles->rascunho('basica', 'padrao'));
        self::assertSame($antes, file_get_contents($this->caminhos->peles() . '/basica/padrao.pbl'));
    }

    public function testRascunhoMaisNovoDeOutraPessoaNaoESobrescritoEmSilencio(): void
    {
        $t = $this->token('admin');
        $b = $this->base();

        // A e B abrem o editor sem rascunho (base vazia). A salva primeiro.
        $a = $this->post($t, "{$b}/molde/rascunho", ['nome' => 'padrao', 'conteudo' => '<p>da pessoa A</p>', 'rascunho_base' => '']);
        self::assertSame(303, $a->getStatusCode());

        // B ainda tem a base antiga: conflito, sem sobrescrever, e o texto de B volta na caixa.
        $r = $this->post($t, "{$b}/molde/rascunho", ['nome' => 'padrao', 'conteudo' => '<p>da pessoa B</p>', 'rascunho_base' => '']);
        self::assertSame(409, $r->getStatusCode());
        self::assertStringContainsString('Outra pessoa alterou o rascunho', $this->html($r));
        self::assertStringContainsString('da pessoa B', $this->html($r));
        $guardado = $this->s->peles->rascunho('basica', 'padrao') ?? self::fail();
        self::assertSame('<p>da pessoa A</p>', $guardado['conteudo']);

        // Descartar com base velha também é conflito; o rascunho de A continua.
        $d = $this->post($t, "{$b}/molde/rascunho", ['nome' => 'padrao', 'conteudo' => '', 'descartar' => '1', 'rascunho_base' => '']);
        self::assertSame(409, $d->getStatusCode());
        self::assertNotNull($this->s->peles->rascunho('basica', 'padrao'));

        // Com a base certa (o hash do rascunho de A), B consegue salvar.
        $ok = $this->post($t, "{$b}/molde/rascunho", ['nome' => 'padrao', 'conteudo' => '<p>da pessoa B</p>', 'rascunho_base' => hash('sha256', '<p>da pessoa A</p>')]);
        self::assertSame(303, $ok->getStatusCode());
        $guardado = $this->s->peles->rascunho('basica', 'padrao') ?? self::fail();
        self::assertSame('<p>da pessoa B</p>', $guardado['conteudo']);
    }

    public function testPublicarRascunhoValidaGravaERepublica(): void
    {
        $t = $this->token('admin');
        $b = $this->base();
        $this->post($t, "{$b}/molde/rascunho", ['nome' => 'padrao', 'conteudo' => '<h1>Publicado via rascunho <@eco #titulo@></h1>']);
        $r = $this->post($t, "{$b}/molde/publicar", ['nome' => 'padrao']);
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('<h1>Publicado via rascunho <@eco #titulo@></h1>', file_get_contents($this->caminhos->peles() . '/basica/padrao.pbl'));
        self::assertNull($this->s->peles->rascunho('basica', 'padrao'));
        $historico = $this->s->peles->historico('basica', 'padrao');
        self::assertSame('salvar', $historico[0]['acao']);
        $site = $this->s->sites->porId($this->siteId) ?? self::fail();
        self::assertStringContainsString('Publicado via rascunho', (string) file_get_contents($this->caminhos->pagina($site->id, '')));
    }

    public function testPreVisualizacaoSemPermissaoDa403ESemRascunhoDa404(): void
    {
        $b = $this->base();
        $editor = $this->token('editor');
        // O editor não administra o site (não pode chegar nem na pele).
        self::assertSame(403, $this->req('GET', "{$b}/molde/previsualizar?nome=padrao", $editor)->getStatusCode());

        $admin = $this->token('admin');
        // Sem rascunho: 404 (nada para pré-visualizar).
        self::assertSame(404, $this->req('GET', "{$b}/molde/previsualizar?nome=padrao", $admin)->getStatusCode());
    }

    public function testPreVisualizacaoRendizaORascunhoSemPublicarComCabecalhosDeIsolamento(): void
    {
        $admin = $this->token('admin');
        $b = $this->base();
        $this->post($admin, "{$b}/molde/rascunho", ['nome' => 'padrao', 'conteudo' => '<h1>Pré-visualização <@eco #titulo@></h1>']);
        $site = $this->s->sites->porId($this->siteId) ?? self::fail();
        $raiz = $this->s->objetos->porId((int) $site->objetoRaizId) ?? self::fail();

        $escolher = $this->html($this->req('GET', "{$b}/molde/previsualizar?nome=padrao", $admin));
        self::assertStringContainsString('<select', $escolher);

        $r = $this->req('GET', "{$b}/molde/previsualizar?nome=padrao&objeto=" . $raiz->id, $admin);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('Pré-visualização', $this->html($r));
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        self::assertStringContainsString('noindex', (string) $r->headers->get('X-Robots-Tag'));
        self::assertStringContainsString("default-src 'none'", (string) $r->headers->get('Content-Security-Policy'));

        self::assertFileDoesNotExist($this->caminhos->pagina($site->id, ''), 'a pré-visualização não grava o cache público');
        self::assertStringNotContainsString('Pré-visualização', (string) file_get_contents($this->caminhos->peles() . '/basica/padrao.pbl'));

        // Página inexistente ou de outro site: 404 (nunca revela qual é o motivo).
        self::assertSame(404, $this->req('GET', "{$b}/molde/previsualizar?nome=padrao&objeto=999999", $admin)->getStatusCode());
    }

    public function testRetencaoDoHistoricoMantemSoAsVersoesMaisNovas(): void
    {
        $limite = GerenciadorDePeles::MAX_VERSOES_POR_MOLDE;
        for ($i = 0; $i < $limite + 5; $i++) {
            $this->s->peles->gravar('basica', 'retencao', '<p>' . $i . '</p>', $this->ids['admin'], $i === 0 ? 'criar' : 'salvar');
        }
        $historico = $this->s->peles->historico('basica', 'retencao');
        self::assertCount($limite, $historico);
        // A mais nova é a última gravada; a mais antiga guardada é a 6ª (as 5 primeiras saíram pela retenção).
        self::assertSame($limite + 5, $historico[0]['versao']);
        self::assertSame(6, $historico[count($historico) - 1]['versao']);
        self::assertNull($this->s->peles->versaoDoHistorico('basica', 'retencao', 5));
        self::assertNotNull($this->s->peles->versaoDoHistorico('basica', 'retencao', 6));
    }
}
