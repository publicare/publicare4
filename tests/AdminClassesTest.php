<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Perfil;
use Pbl\Acesso\SessaoRepositorio;
use Pbl\Aplicacao;
use Pbl\Caminhos;
use Pbl\Conteudo\Classe;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Telas de tipos de conteúdo (classes) da administração, de ponta a ponta (ADR 0012, fase 5c). */
final class AdminClassesTest extends TestCase
{
    private const SENHA = 'uma senha bem longa 2026';
    private const ORIGEM = ['HTTP_ORIGIN' => 'https://www.exemplo.org'];
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;
    private Classe $pagina;
    private int $siteId;
    private int $inicioId;
    /** @var array<string, int> login => id */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Meu Site', 'www.exemplo.org');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $site = $this->s->sites->porHost('www.exemplo.org');
        self::assertNotNull($site);
        $this->siteId = $site->id;
        $this->inicioId = (int) $site->objetoRaizId;
        $this->pagina = $this->s->classes->porNome('pagina') ?? self::fail();
        $u = $this->s->usuarios;
        $this->ids['admin'] = $u->criar('admin', 'admin@exemplo.org', 'Admin', self::SENHA, true)->id;
        $this->ids['editor'] = $u->criar('editor', 'editor@exemplo.org', 'Editor', self::SENHA)->id;
        $this->ids['leitor'] = $u->criar('leitor', 'leitor@exemplo.org', 'Leitor', self::SENHA)->id;
        $u->conceder($this->ids['editor'], $this->inicioId, Perfil::Editor);
        $u->conceder($this->ids['leitor'], $this->inicioId, Perfil::Leitor);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
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

    /** @param array<string, mixed> $campos */
    private function post(string $token, string $caminho, array $campos, bool $comCsrf = true): Response
    {
        return $this->req('POST', $caminho, $token, ($comCsrf ? ['_csrf' => $this->csrf($token)] : []) + $campos, self::ORIGEM);
    }

    private function classe(string $nome): Classe
    {
        return $this->s->classes->porNome($nome) ?? self::fail("classe {$nome} não existe");
    }

    /**
     * O formulário de edição como a tela o monta (sem mudar nada).
     *
     * @return array<string, mixed>
     */
    private function formulario(Classe $c): array
    {
        $p = [];
        foreach ($c->propriedades as $i => $prop) {
            $p[$prop['nome']] = [
                'rotulo' => $prop['rotulo'] ?? '', 'tipo' => $prop['tipo'], 'classe' => $prop['classe'] ?? '',
                'ordem' => (string) (($i + 1) * 10), 'situacao' => ($prop['arquivada'] ?? false) ? 'arquivada' : 'ativa',
            ] + (($prop['obrigatorio'] ?? false) ? ['obrigatorio' => '1'] : []);
        }

        return ['versao' => (string) $c->versao, 'descricao' => $c->descricao, 'filhas' => $c->filhas, 'p' => $p,
            'novo' => ['nome' => '', 'rotulo' => '', 'tipo' => 'texto', 'classe' => '', 'ordem' => '']] + ($c->aceitaFilhos ? ['aceita_filhos' => '1'] : []);
    }

    /** @param array<string, mixed> $campos */
    private function salvar(string $token, Classe $c, array $campos): Response
    {
        return $this->post($token, '/_pbl/classe/' . $c->id . '/editar', $campos);
    }

    /** @return list<array{quando: string, ator: string, acao: string, objeto_id: ?int, detalhe: ?string}> */
    private function auditoria(string $acao): array
    {
        return array_values(array_filter($this->s->auditoria->recentes(), static fn (array $l): bool => $l['acao'] === $acao));
    }

    public function testSemLoginTodaTelaMandaParaEntrarEOPostNaoGrava(): void
    {
        $id = $this->pagina->id;
        foreach (['/_pbl/classes', '/_pbl/classes/nova', "/_pbl/classe/{$id}/editar", "/_pbl/classe/{$id}/apagar"] as $caminho) {
            $r = $this->req('GET', $caminho);
            self::assertSame(303, $r->getStatusCode(), $caminho);
            self::assertSame('/_pbl/entrar', $r->headers->get('Location'));
        }
        foreach (['/_pbl/classes/nova' => ['nome' => 'invasora'], "/_pbl/classe/{$id}/editar" => ['descricao' => 'x', 'versao' => '1'], "/_pbl/classe/{$id}/apagar" => ['versao' => '1']] as $caminho => $campos) {
            self::assertSame(303, $this->req('POST', $caminho, null, $campos, self::ORIGEM)->getStatusCode(), $caminho);
        }
        self::assertNull($this->s->classes->porNome('invasora'));
        self::assertSame(1, $this->classe('pagina')->versao);
    }

    public function testListaMostraTiposEQuemModelaVeAcoesEUso(): void
    {
        $admin = $this->req('GET', '/_pbl/classes', $this->token('admin'));
        $html = (string) $admin->getContent();
        self::assertSame(200, $admin->getStatusCode());
        self::assertStringContainsString('<code>pagina</code>', $html);
        self::assertStringContainsString('href="/_pbl/classes/nova"', $html);
        self::assertStringContainsString('href="/_pbl/classe/' . $this->pagina->id . '/editar"', $html);
        self::assertMatchesRegularExpression('#<td>2</td><td>2</td>#', $html, '2 campos, 2 conteúdos');
        self::assertStringContainsString("default-src 'none'", (string) $admin->headers->get('Content-Security-Policy'));

        $leitor = $this->req('GET', '/_pbl/classes', $this->token('leitor'));
        $html = (string) $leitor->getContent();
        self::assertSame(200, $leitor->getStatusCode());
        self::assertStringContainsString('<code>pagina</code>', $html);
        self::assertStringNotContainsString('/_pbl/classes/nova', $html);
        self::assertStringNotContainsString('/editar', $html);
        self::assertStringNotContainsString('Conteúdos que usam', $html, 'quem não modela não vê contagem (que cruza sites)');
        self::assertStringContainsString('Só administradores', $html);

        self::assertStringContainsString('href="/_pbl/classes"', (string) $this->req('GET', '/_pbl/', $this->token('leitor'))->getContent(), 'link na barra');
    }

    public function testQuemNaoEhAdministradorNaoAbreNemGravaEATentativaFicaNaAuditoria(): void
    {
        $token = $this->token('editor');
        $id = $this->pagina->id;
        foreach (['/_pbl/classes/nova', "/_pbl/classe/{$id}/editar", "/_pbl/classe/{$id}/apagar", '/_pbl/classe/999999/editar'] as $caminho) {
            self::assertSame(403, $this->req('GET', $caminho, $token)->getStatusCode(), $caminho);
        }
        self::assertSame(
            (string) $this->req('GET', "/_pbl/classe/{$id}/editar", $token)->getContent(),
            (string) $this->req('GET', '/_pbl/classe/999999/editar', $token)->getContent(),
            'quem não modela não descobre quais ids existem',
        );

        self::assertSame(403, $this->post($token, '/_pbl/classes/nova', ['nome' => 'do_editor'])->getStatusCode());
        $form = $this->formulario($this->pagina);
        $form['descricao'] = 'mudada pelo editor';
        self::assertSame(403, $this->salvar($token, $this->pagina, $form)->getStatusCode());
        self::assertSame(403, $this->post($token, "/_pbl/classe/{$id}/apagar", ['versao' => '1'])->getStatusCode());

        self::assertNull($this->s->classes->porNome('do_editor'));
        self::assertSame('', $this->classe('pagina')->descricao);
        $negados = $this->auditoria('classe.negado');
        self::assertCount(3, $negados);
        self::assertSame('usuario:' . $this->ids['editor'], $negados[0]['ator']);
        self::assertStringNotContainsString('mudada', (string) $negados[1]['detalhe']);
    }

    public function testUsuarioDesativadoPerdeOAcesso(): void
    {
        $token = $this->token('admin');
        $this->s->usuarios->definirAtivo($this->ids['admin'], false);

        self::assertSame(303, $this->req('GET', '/_pbl/classes/nova', $token)->getStatusCode());
    }

    public function testCsrfEOrigemSaoConferidosEmTodoPost(): void
    {
        $token = $this->token('admin');
        $outro = $this->token('editor');
        $id = $this->pagina->id;
        $rotas = ['/_pbl/classes/nova' => ['nome' => 'nova_classe'], "/_pbl/classe/{$id}/editar" => ['descricao' => 'X'] + $this->formulario($this->pagina), "/_pbl/classe/{$id}/apagar" => ['versao' => '1']];
        foreach ($rotas as $caminho => $campos) {
            self::assertSame(403, $this->req('POST', $caminho, $token, $campos, self::ORIGEM)->getStatusCode(), "{$caminho} sem token");
            self::assertSame(403, $this->req('POST', $caminho, $token, ['_csrf' => str_repeat('0', 64)] + $campos, self::ORIGEM)->getStatusCode(), "{$caminho} token falso");
            self::assertSame(403, $this->req('POST', $caminho, $token, ['_csrf' => $this->csrf($outro)] + $campos, self::ORIGEM)->getStatusCode(), "{$caminho} token de outra sessão");
            self::assertSame(403, $this->req('POST', $caminho, $token, ['_csrf' => ['a']] + $campos, self::ORIGEM)->getStatusCode(), "{$caminho} token não textual");
            self::assertSame(403, $this->req('POST', $caminho, $token, ['_csrf' => $this->csrf($token)] + $campos, ['HTTP_ORIGIN' => 'https://evil.example'])->getStatusCode(), "{$caminho} outra origem");
            self::assertSame(403, $this->req('POST', $caminho, $token, ['_csrf' => $this->csrf($token)] + $campos, ['HTTP_REFERER' => 'https://evil.example/x'])->getStatusCode(), "{$caminho} referer de fora");
        }
        self::assertNull($this->s->classes->porNome('nova_classe'));
        self::assertSame(1, $this->classe('pagina')->versao);
        self::assertSame([], $this->auditoria('classe.negado'), 'pedido forjado nem chega à permissão');
    }

    public function testRotasMalFormadasDao404(): void
    {
        $token = $this->token('admin');
        foreach (['/_pbl/classe/0/editar', '/_pbl/classe/01/editar', '/_pbl/classe/abc/editar', '/_pbl/classe/1', '/_pbl/classe/1/outra', '/_pbl/classe/12345678901/editar', '/_pbl/classesx', '/_pbl/classes/nova/x'] as $caminho) {
            self::assertSame(404, $this->req('GET', $caminho, $token)->getStatusCode(), $caminho);
        }
        self::assertSame(404, $this->post($token, '/_pbl/classes', [])->getStatusCode(), 'lista não aceita POST');
        self::assertSame(404, $this->req('GET', '/_pbl/classe/999999/editar', $token)->getStatusCode(), 'administrador: tipo inexistente');
    }

    public function testCriarTipoComDescricaoEFilhasERegistrarSoONome(): void
    {
        $token = $this->token('admin');
        self::assertSame(200, $this->req('GET', '/_pbl/classes/nova', $token)->getStatusCode());

        $r = $this->post($token, '/_pbl/classes/nova', ['nome' => ' noticia ', 'descricao' => "Notícias do site\r\nsegredo XYZ", 'aceita_filhos' => '1', 'filhas' => ['pagina', 'noticia']]);

        $c = $this->classe('noticia');
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/_pbl/classe/' . $c->id . '/editar?ok=criada', $r->headers->get('Location'));
        self::assertSame("Notícias do site\nsegredo XYZ", $c->descricao);
        self::assertTrue($c->aceitaFilhos);
        self::assertSame(['noticia', 'pagina'], $c->filhas);
        self::assertSame([], $c->propriedades);
        self::assertStringContainsString('Tipo de conteúdo criado', (string) $this->req('GET', (string) $r->headers->get('Location'), $token)->getContent());
        $log = $this->auditoria('classe.criar');
        self::assertCount(1, $log);
        self::assertStringContainsString('noticia', (string) $log[0]['detalhe']);
        self::assertStringNotContainsString('segredo', (string) $log[0]['detalhe']);

        $this->post($token, '/_pbl/classes/nova', ['nome' => 'folha']);
        self::assertFalse($this->classe('folha')->aceitaFilhos, 'caixa desmarcada = não tem filhos');
    }

    public function testCriarRecusaEntradaRuim(): void
    {
        $token = $this->token('admin');
        foreach (['', 'Noticia', '1noticia', 'notícia', 'com-hifen', 'com espaco', str_repeat('a', 65), 'pagina'] as $nome) {
            self::assertSame(422, $this->post($token, '/_pbl/classes/nova', ['nome' => $nome])->getStatusCode(), "nome: {$nome}");
        }
        self::assertSame(422, $this->post($token, '/_pbl/classes/nova', ['nome' => 'ok1', 'descricao' => str_repeat('d', 2001)])->getStatusCode());
        self::assertSame(422, $this->post($token, '/_pbl/classes/nova', ['nome' => 'ok2', 'descricao' => "com\x00nulo"])->getStatusCode());
        self::assertSame(422, $this->post($token, '/_pbl/classes/nova', ['nome' => 'ok3', 'descricao' => "inv\xC3lido"])->getStatusCode());
        self::assertSame(422, $this->post($token, '/_pbl/classes/nova', ['nome' => 'ok4', 'aceita_filhos' => '1', 'filhas' => ['nao_existe']])->getStatusCode());
        self::assertSame(400, $this->post($token, '/_pbl/classes/nova', ['nome' => ['x']])->getStatusCode());
        self::assertSame(400, $this->post($token, '/_pbl/classes/nova', ['nome' => 'ok5', 'filhas' => 'pagina'])->getStatusCode());
        self::assertSame(1, count($this->s->classes->todas()), 'nada foi criado');
    }

    public function testTextoHostilEhSempreEscapado(): void
    {
        $token = $this->token('admin');
        $hostil = '"><script>alert(1)</script><img src=x onerror=alert(2)>';
        $this->post($token, '/_pbl/classes/nova', ['nome' => 'evento', 'descricao' => $hostil]);
        $c = $this->classe('evento');
        $form = $this->formulario($c);
        $form['novo'] = ['nome' => 'local', 'rotulo' => $hostil, 'tipo' => 'texto', 'classe' => '', 'ordem' => ''];
        self::assertSame(303, $this->salvar($token, $c, $form)->getStatusCode());
        self::assertSame($hostil, $this->classe('evento')->propriedades[0]['rotulo'] ?? null, 'guardado como texto');

        $telas = [$this->req('GET', '/_pbl/classes', $token), $this->req('GET', '/_pbl/classe/' . $c->id . '/editar', $token)];
        foreach ($telas as $r) {
            $html = (string) $r->getContent();
            self::assertStringNotContainsString('<script>alert(1)', $html);
            self::assertStringNotContainsString('<img src=x', $html);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        }
        self::assertStringContainsString('value="&quot;&gt;&lt;script&gt;', (string) $telas[1]->getContent(), 'atributo value não é quebrado pela aspa');

        $form = $this->formulario($this->classe('evento'));
        $form['p']['local']['situacao'] = 'remover';
        $form['p']['"><b>x</b>'] = ['rotulo' => 'x', 'tipo' => 'texto', 'ordem' => '1', 'situacao' => 'ativa'];
        $r = $this->salvar($token, $this->classe('evento'), $form);
        self::assertStringNotContainsString('<b>x</b>', (string) $r->getContent(), 'chave hostil no formulário não é refletida');
    }

    public function testEditarDescricaoRotulosOrdemEAdicionarCampo(): void
    {
        $token = $this->token('admin');
        $form = $this->formulario($this->pagina);
        self::assertStringContainsString('name="versao" value="1"', (string) $this->req('GET', '/_pbl/classe/' . $this->pagina->id . '/editar', $token)->getContent());
        $form['descricao'] = 'Páginas comuns, texto confidencial QWE';
        $form['p']['resumo']['rotulo'] = 'Resumo secreto ABC';
        $form['p']['corpo']['ordem'] = '5'; // corpo passa para antes de resumo
        $form['p']['corpo']['obrigatorio'] = '1';
        $form['novo'] = ['nome' => 'destaque', 'rotulo' => 'Destaque', 'tipo' => 'booleano', 'classe' => '', 'ordem' => '', 'indexavel' => '1'];

        $r = $this->salvar($token, $this->pagina, $form);

        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/_pbl/classe/' . $this->pagina->id . '/editar?ok=salva', $r->headers->get('Location'));
        $c = $this->classe('pagina');
        self::assertSame(2, $c->versao);
        self::assertSame('Páginas comuns, texto confidencial QWE', $c->descricao);
        self::assertSame(['corpo', 'resumo', 'destaque'], array_column($c->propriedades, 'nome'));
        self::assertSame(['nome' => 'corpo', 'tipo' => 'html', 'indexavel' => false, 'obrigatorio' => true], $c->propriedades[0]);
        self::assertSame(['nome' => 'resumo', 'tipo' => 'texto', 'indexavel' => true, 'rotulo' => 'Resumo secreto ABC'], $c->propriedades[1]);
        self::assertSame(['nome' => 'destaque', 'tipo' => 'booleano', 'indexavel' => true, 'rotulo' => 'Destaque'], $c->propriedades[2]);
        self::assertStringContainsString('Alterações salvas', (string) $this->req('GET', (string) $r->headers->get('Location'), $token)->getContent());

        $log = $this->auditoria('classe.atualizar');
        self::assertCount(1, $log);
        $detalhe = (string) $log[0]['detalhe'];
        foreach (['descricao', 'propriedade.resumo.rotulo', 'propriedade.corpo.obrigatorio', 'propriedade.destaque.nova', 'ordem', '"versao":2'] as $esperado) {
            self::assertStringContainsString($esperado, $detalhe);
        }
        self::assertStringNotContainsString('confidencial', $detalhe);
        self::assertStringNotContainsString('secreto', $detalhe);

        // o conteúdo existente continua editável com a classe nova
        $sobre = $this->s->objetos->porUrl($this->siteId, 'sobre') ?? self::fail();
        $this->s->objetos->atualizar($sobre->id, ['dados' => ['destaque' => true]]);
        self::assertSame(1, $this->s->objetos->porId($sobre->id)?->dados['destaque']);
    }

    public function testNadaMudouNaoCriaVersao(): void
    {
        $r = $this->salvar($this->token('admin'), $this->pagina, $this->formulario($this->pagina));

        self::assertSame('/_pbl/classe/' . $this->pagina->id . '/editar?ok=nada', $r->headers->get('Location'));
        self::assertSame(1, $this->classe('pagina')->versao);
        self::assertSame([], $this->auditoria('classe.atualizar'));
    }

    public function testValidacaoDaEdicaoRecusaEntradaRuimSemGravar(): void
    {
        $token = $this->token('admin');
        $casos = [
            'rótulo longo' => static function (array &$f): void { $f['p']['resumo']['rotulo'] = str_repeat('r', 101); },
            'rótulo com quebra' => static function (array &$f): void { $f['p']['resumo']['rotulo'] = "a\nb"; },
            'ordem inválida' => static function (array &$f): void { $f['p']['resumo']['ordem'] = '-1'; },
            'ordem vazia' => static function (array &$f): void { $f['p']['resumo']['ordem'] = ''; },
            'situação inválida' => static function (array &$f): void { $f['p']['corpo']['situacao'] = 'apagar_tudo'; },
            'tipo inexistente' => static function (array &$f): void { $f['novo'] = ['nome' => 'x', 'tipo' => 'php', 'rotulo' => '', 'classe' => '', 'ordem' => '']; },
            'nome reservado' => static function (array &$f): void { $f['novo'] = ['nome' => 'titulo', 'tipo' => 'texto', 'rotulo' => '', 'classe' => '', 'ordem' => '']; },
            'nome repetido' => static function (array &$f): void { $f['novo'] = ['nome' => 'resumo', 'tipo' => 'texto', 'rotulo' => '', 'classe' => '', 'ordem' => '']; },
            'nome inválido' => static function (array &$f): void { $f['novo'] = ['nome' => 'Com Espaço', 'tipo' => 'texto', 'rotulo' => '', 'classe' => '', 'ordem' => '']; },
            'rótulo sem nome' => static function (array &$f): void { $f['novo'] = ['nome' => '', 'tipo' => 'texto', 'rotulo' => 'Só rótulo', 'classe' => '', 'ordem' => '']; },
            'ref para classe inexistente' => static function (array &$f): void { $f['novo'] = ['nome' => 'autor', 'tipo' => 'ref', 'rotulo' => '', 'classe' => 'nao_existe', 'ordem' => '']; },
            'filha inexistente' => static function (array &$f): void { $f['filhas'] = ['nao_existe']; },
            'descrição longa' => static function (array &$f): void { $f['descricao'] = str_repeat('d', 2001); },
        ];
        foreach ($casos as $nome => $mudar) {
            $form = $this->formulario($this->pagina);
            $mudar($form);
            self::assertSame(422, $this->salvar($token, $this->pagina, $form)->getStatusCode(), $nome);
        }
        $incompletos = [
            'campo existente faltando' => static function (array &$f): void { unset($f['p']['corpo']); },
            'rótulo não textual' => static function (array &$f): void { $f['p']['corpo']['rotulo'] = ['x']; },
            'versão inválida' => static function (array &$f): void { $f['versao'] = 'abc'; },
            'filhas não lista' => static function (array &$f): void { $f['filhas'] = 'pagina'; },
        ];
        foreach ($incompletos as $nome => $mudar) {
            $form = $this->formulario($this->pagina);
            $mudar($form);
            self::assertSame(400, $this->salvar($token, $this->pagina, $form)->getStatusCode(), $nome);
        }
        self::assertSame(1, $this->classe('pagina')->versao);

        $form = $this->formulario($this->pagina);
        $form['p']['resumo']['rotulo'] = 'Meu rótulo digitado';
        $form['p']['resumo']['ordem'] = 'x';
        self::assertStringContainsString('value="Meu rótulo digitado"', (string) $this->salvar($token, $this->pagina, $form)->getContent(), 'o que foi digitado não se perde no erro');
    }

    public function testCampoEmUsoNaoTrocaDeTipoNemEhRemovido(): void
    {
        $token = $this->token('admin');
        $tela = (string) $this->req('GET', '/_pbl/classe/' . $this->pagina->id . '/editar', $token)->getContent();
        self::assertStringContainsString('name="p[resumo][tipo]" value="texto"', $tela, 'campo em uso: tipo fixo na tela');
        self::assertStringNotContainsString('<option value="remover"', $tela, 'nenhum campo da página está livre');

        $form = $this->formulario($this->pagina);
        $form['p']['resumo']['tipo'] = 'numero'; // forjado: a tela não oferece
        $r = $this->salvar($token, $this->pagina, $form);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('tem valor em 2 conteúdo(s)', (string) $r->getContent());

        $form = $this->formulario($this->pagina);
        $form['p']['resumo']['situacao'] = 'remover';
        $r = $this->salvar($token, $this->pagina, $form);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('não pode ser removido', (string) $r->getContent());

        $c = $this->classe('pagina');
        self::assertSame(1, $c->versao);
        self::assertSame('texto', $c->propriedades[0]['tipo']);
        self::assertSame('Sobre este site', $this->s->objetos->porUrl($this->siteId, 'sobre')?->dados['resumo']);
    }

    public function testCampoQueTeveValorEmVersaoAntigaTambemEhProtegido(): void
    {
        $token = $this->token('admin');
        $sobre = $this->s->objetos->porUrl($this->siteId, 'sobre') ?? self::fail();
        foreach ([$this->inicioId, $sobre->id] as $id) {
            $this->s->objetos->atualizar($id, ['dados' => ['resumo' => null]]); // tira o valor de hoje, que fica no histórico
        }
        $form = $this->formulario($this->pagina);
        $form['p']['resumo']['situacao'] = 'remover';

        $r = $this->salvar($token, $this->pagina, $form);

        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('contando versões antigas', (string) $r->getContent());
        self::assertSame('Sobre este site', $this->s->objetos->restaurar($sobre->id, 1)->dados['resumo'], 'restaurar continua funcionando');
    }

    public function testArquivarCampoEmUsoPedeConfirmacaoENaoApagaDados(): void
    {
        $token = $this->token('admin');
        $form = $this->formulario($this->pagina);
        $form['p']['corpo']['situacao'] = 'arquivada';

        $confirmar = $this->salvar($token, $this->pagina, $form);

        self::assertSame(200, $confirmar->getStatusCode());
        $html = (string) $confirmar->getContent();
        self::assertStringContainsString('2 conteúdos têm valor neste campo', $html);
        self::assertStringContainsString('name="confirmar" value="1"', $html);
        self::assertStringContainsString('name="p[corpo][situacao]" value="arquivada"', $html);
        self::assertSame(1, $this->classe('pagina')->versao, 'nada gravado antes de confirmar');

        $revisar = $this->salvar($token, $this->pagina, $form + ['revisar' => '1']);
        self::assertSame(200, $revisar->getStatusCode());
        self::assertStringContainsString('<option value="arquivada" selected>', (string) $revisar->getContent(), 'volta ao formulário com a escolha');
        self::assertSame(1, $this->classe('pagina')->versao);

        self::assertSame(303, $this->salvar($token, $this->pagina, $form + ['confirmar' => '1'])->getStatusCode());
        $c = $this->classe('pagina');
        self::assertTrue($c->propriedades[1]['arquivada'] ?? false);
        $sobre = $this->s->objetos->porUrl($this->siteId, 'sobre') ?? self::fail();
        self::assertSame('<p>Página de exemplo.</p>', $sobre->dados['corpo'], 'valor continua guardado');
        $this->s->objetos->atualizar($sobre->id, ['dados' => ['resumo' => 'Novo resumo']]);
        self::assertSame('<p>Página de exemplo.</p>', $this->s->objetos->porId($sobre->id)?->dados['corpo'], 'e o conteúdo continua editável');
        self::assertStringContainsString('propriedade.corpo.arquivada', (string) $this->auditoria('classe.atualizar')[0]['detalhe']);

        $volta = $this->formulario($c);
        $volta['p']['corpo']['situacao'] = 'ativa';
        self::assertSame(303, $this->salvar($token, $c, $volta)->getStatusCode(), 'desarquivar não pede confirmação');
        self::assertArrayNotHasKey('arquivada', $this->classe('pagina')->propriedades[1]);
    }

    public function testCampoSemValorPodeTrocarDeTipoESerRemovido(): void
    {
        $token = $this->token('admin');
        $form = $this->formulario($this->pagina);
        $form['novo'] = ['nome' => 'nota', 'rotulo' => '', 'tipo' => 'texto', 'classe' => '', 'ordem' => ''];
        $this->salvar($token, $this->pagina, $form);
        $c = $this->classe('pagina');
        self::assertStringContainsString('<option value="remover"', (string) $this->req('GET', '/_pbl/classe/' . $c->id . '/editar', $token)->getContent());

        $form = $this->formulario($c);
        $form['p']['nota']['tipo'] = 'ref';
        $form['p']['nota']['classe'] = 'pagina';
        self::assertSame(303, $this->salvar($token, $c, $form)->getStatusCode());
        $c = $this->classe('pagina');
        self::assertSame(['nome' => 'nota', 'tipo' => 'ref', 'indexavel' => true, 'classe' => 'pagina'], $c->propriedades[2]);

        $form = $this->formulario($c);
        $form['p']['nota']['situacao'] = 'remover';
        self::assertSame(303, $this->salvar($token, $c, $form)->getStatusCode(), 'remover campo sem valor não pede confirmação');
        self::assertSame(['resumo', 'corpo'], array_column($this->classe('pagina')->propriedades, 'nome'));
        self::assertStringContainsString('propriedade.nota.removida', (string) $this->auditoria('classe.atualizar')[0]['detalhe']);
    }

    public function testEdicaoConcorrenteNaoSobrescreve(): void
    {
        $token = $this->token('admin');
        $aberto = $this->pagina;
        $outra = $this->formulario($aberto);
        $outra['descricao'] = 'Salvo pela outra pessoa';
        self::assertSame(303, $this->salvar($this->token('admin'), $aberto, $outra)->getStatusCode());

        $minha = $this->formulario($aberto);
        $minha['p']['resumo']['rotulo'] = 'Minha mudança';
        $r = $this->salvar($token, $aberto, $minha);

        self::assertSame(409, $r->getStatusCode());
        $html = (string) $r->getContent();
        self::assertStringContainsString('Outra pessoa alterou', $html);
        self::assertStringContainsString('Salvo pela outra pessoa', $html, 'mostra o que está salvo');
        self::assertStringContainsString('name="versao" value="2"', $html);
        $c = $this->classe('pagina');
        self::assertSame('Salvo pela outra pessoa', $c->descricao);
        self::assertArrayNotHasKey('rotulo', $c->propriedades[0]);
    }

    public function testApagarSoTipoSemConteudoESemDependentes(): void
    {
        $token = $this->token('admin');
        $id = $this->pagina->id;

        $bloqueado = (string) $this->req('GET', "/_pbl/classe/{$id}/apagar", $token)->getContent();
        self::assertStringContainsString('não pode ser apagado: 2 conteúdos usam', $bloqueado);
        self::assertStringNotContainsString('name="versao"', $bloqueado, 'sem botão de apagar');
        self::assertSame(409, $this->post($token, "/_pbl/classe/{$id}/apagar", ['versao' => '1'])->getStatusCode(), 'POST direto também é recusado');
        self::assertNotNull($this->s->classes->porId($id));

        $this->post($token, '/_pbl/classes/nova', ['nome' => 'autor']);
        $this->post($token, '/_pbl/classes/nova', ['nome' => 'livro', 'aceita_filhos' => '1', 'filhas' => ['autor']]);
        $autor = $this->classe('autor');
        self::assertStringContainsString('<code>livro</code> dependem dele', (string) $this->req('GET', "/_pbl/classe/{$autor->id}/apagar", $token)->getContent());
        self::assertSame(409, $this->post($token, "/_pbl/classe/{$autor->id}/apagar", ['versao' => '1'])->getStatusCode());

        $livro = $this->classe('livro');
        self::assertStringContainsString('name="versao" value="1"', (string) $this->req('GET', "/_pbl/classe/{$livro->id}/apagar", $token)->getContent());
        self::assertSame(409, $this->post($token, "/_pbl/classe/{$livro->id}/apagar", ['versao' => '7'])->getStatusCode(), 'versão velha');
        self::assertSame(400, $this->post($token, "/_pbl/classe/{$livro->id}/apagar", ['versao' => 'x'])->getStatusCode());
        $r = $this->post($token, "/_pbl/classe/{$livro->id}/apagar", ['versao' => '1']);
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/_pbl/classes?ok=apagada', $r->headers->get('Location'));
        self::assertNull($this->s->classes->porNome('livro'));
        self::assertStringContainsString('Tipo de conteúdo apagado', (string) $this->req('GET', '/_pbl/classes?ok=apagada', $token)->getContent());
        $log = $this->auditoria('classe.apagar');
        self::assertCount(1, $log);
        self::assertStringContainsString('livro', (string) $log[0]['detalhe']);

        self::assertSame(303, $this->post($token, "/_pbl/classe/{$autor->id}/apagar", ['versao' => '1'])->getStatusCode(), 'sem dependentes, sai');
    }
}
