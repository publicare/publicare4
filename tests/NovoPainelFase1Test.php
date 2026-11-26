<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Perfil;
use Pbl\Admin\Pagina;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Novo painel, fase 1 (ADR 0031 a 0033): menu por tarefa, 303 das rotas antigas, <details>, abas com interruptores, barra de ações, Início e CSP. */
final class NovoPainelFase1Test extends TestCase
{
    use AdminAuxiliar;

    /**
     * @param array<string, string> $dados
     */
    private function pagina(string $classe, string $titulo, string $url, array $dados = []): \Pbl\Conteudo\Objeto
    {
        $c = $this->s->classes->porNome($classe) ?? self::fail();

        return $this->s->objetos->criar($this->siteId, $c, $this->inicioId, $titulo, $url, $dados + ['corpo' => '<p>x</p>'], 'publicado', []);
    }

    // ------------------------------------------------------------ menu

    /** @return array<string, array{0: string, 1: string}> */
    public static function itensAtivos(): array
    {
        return [
            'início' => ['/_pbl/', 'inicio'], 'objeto' => ['/_pbl/objeto/5/editar', 'conteudo'], 'conteúdo' => ['/_pbl/conteudo/3', 'conteudo'],
            'vencidos' => ['/_pbl/vencidos', 'vencidos'], 'lixeira' => ['/_pbl/lixeira', 'lixeira'], 'revisar' => ['/_pbl/revisar', 'revisar'],
            'pendências antigas' => ['/_pbl/pendencias', 'revisar'], 'peles' => ['/_pbl/site/1/peles', 'peles'], 'site' => ['/_pbl/site/1/configuracoes', 'site-config'], 'domínios' => ['/_pbl/site/1/dominios', 'site-dominios'], 'classe' => ['/_pbl/classe/4', 'tipos'],
            'classes' => ['/_pbl/classes', 'tipos'], 'contas' => ['/_pbl/contas', 'contas'], 'mais' => ['/_pbl/mais', 'mais'], 'cópias' => ['/_pbl/copias', 'copias'],
        ];
    }

    #[DataProvider('itensAtivos')]
    public function testItemAtivoPorPrefixo(string $caminho, string $item): void
    {
        self::assertSame($item, Pagina::itemAtivo($caminho));
    }

    public function testMenuDoAdministradorEhAgrupadoPorSecaoEMarcaOAtivo(): void
    {
        $h = $this->html($this->req('GET', '/_pbl/objeto/' . $this->inicioId . '/editar', $this->token('admin')));
        self::assertStringContainsString('<details class="pbl-menu" open', $h);
        foreach (['Conteúdo', 'Estrutura', 'Site', 'Pessoas', 'Sistema', 'Ajuda'] as $grupo) {
            self::assertMatchesRegularExpression('#<h2 class="pbl-grupo-titulo"[^>]*>' . $grupo . '</h2>#', $h, $grupo);
        }
        self::assertStringNotContainsString('>Mais<', $h, 'o item "Mais" saiu do menu');
        self::assertMatchesRegularExpression('#<a href="/_pbl/conteudo" aria-current="page">Lista de conteúdo</a>#', $h);
        self::assertStringNotContainsString('aria-current="page">Início', $h);
        foreach (['/_pbl/classes', '/_pbl/contas', '/_pbl/atualizacao', '/_pbl/copias', '/_pbl/tarefas', '/_pbl/email', '/_pbl/lixeira', '/_pbl/perfil'] as $link) {
            self::assertStringContainsString('href="' . $link . '"', $h, $link);
        }
        self::assertStringNotContainsString('navbar-toggler', $h);
        self::assertStringNotContainsString('data-bs-toggle="collapse"', $h);
    }

    public function testMenuPorPapel(): void
    {
        $leitor = $this->html($this->req('GET', '/_pbl/', $this->token('leitor')));
        self::assertStringContainsString('href="/_pbl/classes"', $leitor, 'tipos de conteúdo continuam acessíveis em leitura');
        self::assertStringNotContainsString('href="/_pbl/contas"', $leitor);
        self::assertStringNotContainsString('href="/_pbl/copias"', $leitor);
        self::assertStringNotContainsString('>Sistema<', $leitor, 'grupo vazio some');
        self::assertStringNotContainsString('>Revisar', $leitor);
        $editor = $this->html($this->req('GET', '/_pbl/', $this->token('editor')));
        self::assertStringContainsString('>Revisar', $editor);
        self::assertStringContainsString('href="/_pbl/lixeira"', $editor);
        self::assertStringNotContainsString('href="/_pbl/atualizacao"', $editor);
        $admin = $this->html($this->req('GET', '/_pbl/', $this->token('admin')));
        self::assertStringContainsString('>Sistema<', $admin);
        self::assertStringContainsString('>Site<', $admin);
        self::assertStringContainsString('Redirecionamentos', $admin);
    }

    public function testContadorDeRevisaoNoMenu(): void
    {
        $a = $this->pagina('pagina', 'Pendente', 'pendente');
        $this->s->objetos->atualizar($a->id, ['status' => 'submetido'], 'teste', null, false, $this->ids['admin']);
        $h = $this->html($this->req('GET', '/_pbl/', $this->token('editor')));
        self::assertMatchesRegularExpression('#Revisar <span class="pbl-contagem"[^>]*>1<#', $h);
    }

    public function testRotasAntigasRespondem303PreservandoAConsulta(): void
    {
        $t = $this->token('admin');
        $r = $this->req('GET', '/_pbl/pendencias?area=7', $t);
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/_pbl/revisar?aba=pendencias&area=7', $r->headers->get('Location'));
        $r = $this->req('GET', '/_pbl/meus-envios?estado=devolvidos', $t);
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/_pbl/revisar?aba=envios&estado=devolvidos', $r->headers->get('Location'));
        self::assertSame(200, $this->req('GET', '/_pbl/revisar?aba=envios', $t)->getStatusCode());
    }

    public function testRevisarSemPapelEhNegado(): void
    {
        self::assertSame(403, $this->req('GET', '/_pbl/revisar', $this->token('leitor'))->getStatusCode());
    }

    public function testMaisMostraACadaUmSoOQuePodeAbrir(): void
    {
        $editor = $this->html($this->req('GET', '/_pbl/mais', $this->token('editor')));
        self::assertStringContainsString('href="/_pbl/classes"', $editor);
        foreach (['/_pbl/contas', '/_pbl/atualizacao', '/_pbl/copias', '/_pbl/tarefas'] as $link) {
            self::assertStringNotContainsString('href="' . $link . '"', $editor, $link);
        }
        self::assertSame(303, $this->req('GET', '/_pbl/mais')->getStatusCode());
        $h = $this->html($this->req('GET', '/_pbl/mais', $this->token('admin')));
        foreach (['/_pbl/contas', '/_pbl/classes', '/_pbl/atualizacao', '/_pbl/copias', '/_pbl/tarefas'] as $link) {
            self::assertStringContainsString('href="' . $link . '"', $h);
        }
    }

    public function testMigalhasVisiveisEVocabularioNovo(): void
    {
        $filho = $this->pagina('pagina', 'Filha', 'filha');
        $h = $this->html($this->req('GET', '/_pbl/objeto/' . $filho->id, $this->token('admin')));
        self::assertStringContainsString('class="pbl-migalhas"', $h);
        foreach (['/_pbl/', '/_pbl/mais', '/_pbl/classes', '/_pbl/objeto/' . $this->inicioId . '/editar'] as $caminho) {
            $tela = $this->html($this->req('GET', $caminho, $this->token('admin')));
            $visivel = (string) preg_replace('#<(script|style)\b.*?</\1>#s', '', $tela);
            $visivel = strip_tags((string) preg_replace('#(href|value|name|id|class|for|data-[a-z-]+)="[^"]*"#', '', $visivel));
            self::assertDoesNotMatchRegularExpression('/\b(Objetos?|Classes?|Propriedades?|Moldes?)\b/', $visivel, "termo técnico visível em {$caminho}");
        }
    }

    // ------------------------------------------------------------ <details>

    public function testAjudaEPainelRecolhivelSaoDetailsSemJs(): void
    {
        $a = Pagina::ajuda('ajuda-x', 'Campo', 'Texto <em>da</em> ajuda.');
        self::assertStringStartsWith('<details class="pbl-ajuda">', $a);
        self::assertStringContainsString('<div id="ajuda-x" class="pbl-ajuda-texto" role="note">Texto <em>da</em> ajuda.</div>', $a);
        self::assertStringNotContainsString('data-bs-toggle', $a);
        self::assertStringContainsString(' open', Pagina::recolhivel('a', 'Resumo', 'Corpo', true));
        self::assertStringNotContainsString(' open', Pagina::recolhivel('a', 'Resumo', 'Corpo'));
        self::assertSame('', Pagina::ajuda('x', 'y', ''));
    }

    public function testNenhumCollapseNasTelasTocadas(): void
    {
        $t = $this->token('admin');
        foreach (['/_pbl/', '/_pbl/classes', '/_pbl/objeto/' . $this->inicioId, '/_pbl/objeto/' . $this->inicioId . '/editar', '/_pbl/revisar'] as $caminho) {
            $h = $this->html($this->req('GET', $caminho, $t));
            self::assertStringNotContainsString('data-bs-toggle="collapse"', $h, $caminho);
            self::assertStringNotContainsString('accordion-collapse', $h, $caminho);
        }
    }

    // ------------------------------------------------------------ edição em abas

    public function testEdicaoTemTresAbasEUmSoFormulario(): void
    {
        $p = $this->pagina('pagina', 'Sobre nós', 'sobre-nos');
        $h = $this->html($this->req('GET', '/_pbl/objeto/' . $p->id . '/editar', $this->token('admin')));
        self::assertSame(1, substr_count($h, '<form method="post" action="/_pbl/objeto/' . $p->id . '/editar"'));
        foreach (['conteudo', 'publicacao', 'avancado'] as $aba) {
            self::assertStringContainsString('id="aba-painel-' . $aba . '"', $h);
        }
        self::assertStringContainsString('role="tablist"', $h);
        self::assertStringContainsString('data-pbl-form', $h);
        self::assertStringContainsString('pbl-formulario.js', $h);
        // aba padrão: até 6 controles
        preg_match('#id="aba-painel-conteudo".*?(?=id="aba-painel-publicacao")#s', $h, $m);
        $painel = $m[0] ?? self::fail('painel Conteúdo ausente');
        self::assertLessThanOrEqual(6, preg_match_all('#<(input|textarea|select)\b(?![^>]*type="hidden")#', $painel));
        self::assertStringNotContainsString('seo_titulo', $painel, 'SEO fica atrás do interruptor');
        foreach (['usar_agenda', 'usar_tags', 'usar_seo', 'no_menu', 'usar_posicao', 'usar_aparencia'] as $nome) {
            self::assertStringContainsString('name="' . $nome . '" value="0"', $h, $nome);
        }
        self::assertStringContainsString('name="acao" value="salvar_publicar"', $h);
    }

    public function testAbaPedidaPorQueryEInterruptorNasceLigadoComValor(): void
    {
        $p = $this->pagina('pagina', 'Com tags', 'com-tags');
        $this->s->objetos->atualizar($p->id, ['tags' => ['alfa']], 'teste', null, false, $this->ids['admin']);
        $h = $this->html($this->req('GET', '/_pbl/objeto/' . $p->id . '/editar?aba=publicacao', $this->token('admin')));
        self::assertStringContainsString('data-pbl-aba-inicial="publicacao"', $h);
        self::assertMatchesRegularExpression('#data-pbl-toggle data-pbl-inicial="ligado"><div class="pbl-toggle-cab"><label class="pbl-toggle-texto" for="usar-tags"#', $h);
        self::assertMatchesRegularExpression('#data-pbl-inicial="desligado"><div class="pbl-toggle-cab"><label class="pbl-toggle-texto" for="usar-seo"#', $h);
    }

    public function testErroVoltaParaAbaDoErro(): void
    {
        $p = $this->pagina('pagina', 'Erro', 'erro');
        $r = $this->post($this->token('admin'), '/_pbl/objeto/' . $p->id . '/editar', ['titulo' => 'Erro', 'descricao' => '', 'peso' => '0', 'versao' => (string) $p->versao, 'url' => 'Inválido Aqui', 'usar_agenda' => '0', 'acao' => 'salvar']);
        self::assertSame(422, $r->getStatusCode());
        $h = $this->html($r);
        self::assertStringContainsString('data-pbl-aba-inicial="publicacao"', $h);
        self::assertStringContainsString('• 1 erro', $h);
        self::assertStringContainsString('id="resumo-de-erros"', $h);
        self::assertStringContainsString('aria-invalid="true"', $h);
    }

    public function testAbrirESalvarSemMexerNaoApagaNada(): void
    {
        $p = $this->pagina('noticia', 'Notícia', 'noticia-um', ['resumo' => 'Resumo', 'seo_titulo' => 'Título SEO', 'seo_descricao' => 'Descrição SEO']);
        $this->s->objetos->atualizar($p->id, ['tags' => ['um', 'dois'], 'peso' => 7, 'rotulo_menu' => 'Menu'], 'teste', null, false, $this->ids['admin']);
        $p = $this->s->objetos->porId($p->id) ?? self::fail();
        $h = $this->html($this->req('GET', '/_pbl/objeto/' . $p->id . '/editar', $this->token('admin')));
        // reproduz o que o navegador envia: todos os campos, interruptores com valor ligados
        $campos = ['versao' => (string) $p->versao, 'titulo' => 'Notícia', 'descricao' => '', 'url' => 'noticia-um', 'tags' => 'um, dois', 'peso' => '7', 'estado' => 'publicado',
            'rotulo_menu' => 'Menu', 'no_menu' => '0', 'usar_tags' => '1', 'usar_seo' => '1', 'usar_posicao' => '1', 'usar_agenda' => '0', 'usar_aparencia' => '0', 'acao' => 'salvar',
            'dados' => ['resumo' => 'Resumo', 'corpo' => '<p>x</p>', 'seo_titulo' => 'Título SEO', 'seo_descricao' => 'Descrição SEO', 'seo_nao_indexar' => '']];
        self::assertStringContainsString('value="Título SEO"', str_replace('>Título SEO<', 'value="Título SEO"', $h));
        $r = $this->post($this->token('admin'), '/_pbl/objeto/' . $p->id . '/editar', $campos);
        self::assertSame(303, $r->getStatusCode(), $this->html($r));
        $depois = $this->s->objetos->porId($p->id) ?? self::fail();
        self::assertSame('Título SEO', $depois->dados['seo_titulo']);
        self::assertSame(7, $depois->peso);
        $tags = $this->s->tags->nomesDoObjeto($p->id);
        sort($tags);
        self::assertSame(['dois', 'um'], $tags);
    }

    public function testInterruptorDesligadoGravaOPadraoNoServidor(): void
    {
        $p = $this->pagina('noticia', 'Notícia 2', 'noticia-dois', ['resumo' => 'R', 'seo_titulo' => 'Some', 'seo_descricao' => 'Some']);
        $this->s->objetos->atualizar($p->id, ['tags' => ['um'], 'peso' => 9], 'teste', null, false, $this->ids['admin']);
        $p = $this->s->objetos->porId($p->id) ?? self::fail();
        $campos = ['versao' => (string) $p->versao, 'titulo' => 'Notícia 2', 'descricao' => '', 'url' => 'noticia-dois', 'tags' => 'um', 'peso' => '9', 'estado' => 'publicado',
            'usar_tags' => '0', 'usar_seo' => '0', 'usar_posicao' => '0', 'usar_agenda' => '0', 'usar_aparencia' => '0', 'acao' => 'salvar',
            'dados' => ['resumo' => 'R', 'corpo' => '<p>x</p>', 'seo_titulo' => 'Some', 'seo_descricao' => 'Some']];
        $r = $this->post($this->token('admin'), '/_pbl/objeto/' . $p->id . '/editar', $campos);
        self::assertSame(303, $r->getStatusCode(), $this->html($r));
        $depois = $this->s->objetos->porId($p->id) ?? self::fail();
        self::assertSame(0, $depois->peso);
        self::assertSame([], $this->s->tags->nomesDoObjeto($p->id));
        self::assertEmpty($depois->dados['seo_titulo'] ?? null);
        self::assertSame('R', $depois->dados['resumo'], 'campo obrigatório ou de conteúdo nunca é apagado por interruptor');
    }

    // ------------------------------------------------------------ barra de ações

    public function testSalvarEPublicarExigePermissao(): void
    {
        $c = $this->s->classes->porNome('pagina') ?? self::fail();
        $this->s->usuarios->conceder($this->ids['leitor'], $this->inicioId, Perfil::Autor);
        $p = $this->s->objetos->criar($this->siteId, $c, $this->inicioId, 'Rascunho', 'rascunho', ['corpo' => '<p>x</p>'], 'privado', ['criado_por' => $this->ids['leitor']]);
        $campos = ['versao' => (string) $p->versao, 'titulo' => 'Rascunho', 'descricao' => '', 'url' => 'rascunho', 'peso' => '0', 'dados' => ['corpo' => '<p>x</p>'], 'acao' => 'salvar_publicar'];
        $r = $this->post($this->token('leitor'), '/_pbl/objeto/' . $p->id . '/editar', $campos);
        self::assertSame(403, $r->getStatusCode());
        self::assertNotEmpty($this->auditoria('objeto.publicar.negado'));
        self::assertSame('privado', $this->s->objetos->porId($p->id)?->status);

        $r = $this->post($this->token('admin'), '/_pbl/objeto/' . $p->id . '/editar', $campos + ['estado' => 'privado']);
        self::assertSame(303, $r->getStatusCode(), $this->html($r));
        self::assertSame('publicado', $this->s->objetos->porId($p->id)?->status);
    }

    public function testBarraDeAcoesDaEdicao(): void
    {
        $p = $this->pagina('pagina', 'Barra', 'barra');
        $this->s->objetos->atualizar($p->id, ['status' => 'privado'], 'teste', null, false, $this->ids['admin']);
        $h = $this->html($this->req('GET', '/_pbl/objeto/' . $p->id . '/editar', $this->token('admin')));
        self::assertStringContainsString('class="pbl-barra-form"', $h);
        self::assertStringContainsString('role="status"', $h);
        self::assertMatchesRegularExpression('#value="salvar_publicar" class="btn btn-primary">Salvar e publicar#', $h, 'privado: publicar é a ação principal');
        // sem permissão de publicar o botão não existe
        $this->s->usuarios->conceder($this->ids['leitor'], $this->inicioId, Perfil::Autor);
        $h2 = $this->html($this->req('GET', '/_pbl/objeto/' . $p->id . '/editar', $this->token('leitor')));
        self::assertStringNotContainsString('salvar_publicar', $h2);
    }

    public function testAcoesDoConteudoPorPapel(): void
    {
        $p = $this->pagina('pagina', 'Ações', 'acoes');
        $admin = $this->html($this->req('GET', '/_pbl/objeto/' . $p->id, $this->token('admin')));
        self::assertStringContainsString('>Editar</a>', $admin);
        self::assertStringContainsString('>Tirar do ar</a>', $admin);
        self::assertMatchesRegularExpression('#Ver no site <span aria-hidden="true">↗</span>#', $admin);
        self::assertStringContainsString('target="_blank" rel="noopener"', $admin);
        self::assertStringContainsString('<details class="pbl-mais"', $admin);
        self::assertStringContainsString('Mandar para a lixeira', $admin);
        $leitor = $this->html($this->req('GET', '/_pbl/objeto/' . $p->id, $this->token('leitor')));
        self::assertStringNotContainsString('>Editar</a>', $leitor);
        self::assertStringNotContainsString('Tirar do ar', $leitor);
        self::assertStringNotContainsString('Mandar para a lixeira', $leitor);
        $editor = $this->html($this->req('GET', '/_pbl/objeto/' . $p->id, $this->token('editor')));
        self::assertStringContainsString('>Editar</a>', $editor);
    }

    // ------------------------------------------------------------ Início

    public function testInicioTemUmaAcaoPrincipal(): void
    {
        $h = $this->html($this->req('GET', '/_pbl/', $this->token('admin')));
        self::assertSame(1, substr_count($h, 'class="pbl-acao-principal"'));
        $p = $this->pagina('pagina', 'Minha página', 'minha');
        $this->s->objetos->atualizar($p->id, ['titulo' => 'Minha página 2'], 'teste', null, false, $this->ids['editor']);
        $h = $this->html($this->req('GET', '/_pbl/', $this->token('editor')));
        self::assertStringContainsString('Continuar de onde parou', $h);
        self::assertStringContainsString('/_pbl/objeto/' . $p->id . '/editar', $h);
    }

    public function testInicioSemAcessoMostraEstadoVazioENadaDeOutros(): void
    {
        $this->s->usuarios->criar('ninguem', 'n@exemplo.org', 'Ninguém', self::SENHA);
        $this->ids['ninguem'] = $this->s->usuarios->porLogin('ninguem')->id ?? self::fail();
        $h = $this->html($this->req('GET', '/_pbl/', $this->token('ninguem')));
        self::assertStringContainsString('ainda não recebeu acesso', $h);
        self::assertStringNotContainsString('class="pbl-acao-principal"', $h);
        self::assertStringNotContainsString('Meu Site', $h);
    }

    // ------------------------------------------------------------ segurança e XSS

    public function testTituloComMarcacaoApareceEscapadoEmTodoLugar(): void
    {
        $xss = '<script>alert(1)</script>';
        $p = $this->pagina('pagina', $xss, 'xss', ['seo_titulo' => $xss, 'resumo' => $xss]);
        $t = $this->token('admin');
        foreach (['/_pbl/objeto/' . $p->id . '/editar', '/_pbl/objeto/' . $p->id, '/_pbl/objeto/' . $p->id . '/editar?aba=publicacao'] as $caminho) {
            $h = $this->html($this->req('GET', $caminho, $t));
            self::assertStringNotContainsString($xss, $h, $caminho);
            self::assertStringContainsString('&lt;script&gt;', $h, $caminho);
        }
    }

    public function testCspSoAceitaBlobNasTelasComImagem(): void
    {
        $t = $this->token('admin');
        $img = $this->s->classes->porNome('noticia') ?? self::fail();
        $n = $this->s->objetos->criar($this->siteId, $img, $this->inicioId, 'Com imagem', 'com-imagem', ['corpo' => 'x'], 'publicado', []);
        $csp = (string) $this->req('GET', '/_pbl/objeto/' . $n->id . '/editar', $t)->headers->get('Content-Security-Policy');
        self::assertStringContainsString("img-src 'self' data: blob:", $csp);
        self::assertStringContainsString("script-src 'self'", $csp);
        self::assertStringNotContainsString("script-src 'self' 'unsafe", $csp);
        foreach (['/_pbl/', '/_pbl/mais', '/_pbl/classes', '/_pbl/objeto/' . $n->id] as $caminho) {
            self::assertStringNotContainsString('blob:', (string) $this->req('GET', $caminho, $t)->headers->get('Content-Security-Policy'), $caminho);
        }
    }

    public function testSemScriptNemEstiloInlineNasTelasTocadas(): void
    {
        $p = $this->pagina('noticia', 'Inline', 'inline');
        $t = $this->token('admin');
        foreach (['/_pbl/', '/_pbl/mais', '/_pbl/revisar', '/_pbl/objeto/' . $p->id, '/_pbl/objeto/' . $p->id . '/editar', '/_pbl/objeto/' . $this->inicioId . '/novo?classe=' . ($this->s->classes->porNome('pagina')->id ?? 0)] as $caminho) {
            $h = $this->html($this->req('GET', $caminho, $t));
            self::assertDoesNotMatchRegularExpression('#<script(?![^>]*\bsrc=)#', $h, $caminho);
            self::assertStringNotContainsString(' style="', $h, $caminho);
            self::assertDoesNotMatchRegularExpression('# on[a-z]+="#', $h, $caminho);
        }
    }

    public function testPostContinuaExigindoCsrfEOrigem(): void
    {
        $p = $this->pagina('pagina', 'Seguro', 'seguro');
        $campos = ['versao' => (string) $p->versao, 'titulo' => 'Seguro', 'acao' => 'salvar'];
        self::assertSame(403, $this->post($this->token('admin'), '/_pbl/objeto/' . $p->id . '/editar', $campos, false)->getStatusCode());
        $r = $this->req('POST', '/_pbl/objeto/' . $p->id . '/editar', $this->token('admin'), ['_csrf' => $this->csrf($this->token('admin'))] + $campos, ['HTTP_ORIGIN' => 'https://outro.exemplo']);
        self::assertSame(403, $r->getStatusCode());
    }

    public function testArquivosNovosDoPainelExistem(): void
    {
        foreach (['pbl-formulario.css', 'pbl-formulario.js', 'pbl-js.js'] as $a) {
            self::assertFileExists(dirname(__DIR__) . '/public/_pbl/assets/' . $a);
        }
    }
}
