<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Perfil;
use Pbl\Acesso\SessaoRepositorio;
use Pbl\Admin\Pagina;
use Pbl\Aplicacao;
use Pbl\Caminhos;
use Pbl\Banco\Conexao;
use Pbl\Conteudo\Classe;
use Pbl\Conteudo\ConflitoDeArvore;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\Site;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Telas de objetos da administração (ADR 0012, fatia 5b): criar, editar propriedades, estado, mover, lixeira e histórico. */
final class AdminObjetosTest extends TestCase
{
    private const SENHA = 'uma senha bem longa 2026';
    private const ORIGEM = ['HTTP_ORIGIN' => 'https://www.exemplo.org'];
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;
    private Site $site;
    private Classe $pagina;
    private Classe $evento;
    private Objeto $inicio;
    private Objeto $sobre;
    /** @var array<string, int> login => id */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Meu Site', 'www.exemplo.org');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $this->site = $this->s->sites->porHost('www.exemplo.org') ?? self::fail();
        $this->inicio = $this->obj((int) $this->site->objetoRaizId);
        $this->sobre = $this->obj((int) $this->s->objetos->porUrl($this->site->id, 'sobre')?->id);
        $this->pagina = $this->s->classes->porNome('pagina') ?? self::fail();
        $this->evento = $this->s->classes->criar('evento', [
            ['nome' => 'local', 'tipo' => 'texto', 'indexavel' => true],
            ['nome' => 'vagas', 'tipo' => 'numero', 'indexavel' => true],
            ['nome' => 'quando', 'tipo' => 'data'],
            ['nome' => 'aberto', 'tipo' => 'booleano'],
            ['nome' => 'pagina_relacionada', 'tipo' => 'ref', 'classe' => 'pagina'],
            ['nome' => 'corpo', 'tipo' => 'html'],
        ]);
        $u = $this->s->usuarios;
        $this->ids['admin'] = $u->criar('admin', 'admin@exemplo.org', 'Admin', self::SENHA, true)->id;
        $this->ids['leitor'] = $u->criar('leitor', 'leitor@exemplo.org', 'Leitor', self::SENHA)->id;
        $this->ids['autor'] = $u->criar('autor', 'autor@exemplo.org', 'Autor', self::SENHA)->id;
        $this->ids['editor'] = $u->criar('editor', 'editor@exemplo.org', 'Editor', self::SENHA)->id;
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
     * POST com CSRF e origem válidos (a menos que `$campos`/`$cabecalhos` digam outra coisa).
     *
     * @param array<string, mixed> $campos
     * @param array<string, string> $cabecalhos
     */
    private function post(string $token, string $caminho, array $campos = [], array $cabecalhos = self::ORIGEM): Response
    {
        return $this->req('POST', $caminho, $token, $campos + ['_csrf' => $this->csrf($token)], $cabecalhos);
    }

    private function html(Response $r): string
    {
        return (string) $r->getContent();
    }

    /**
     * @param array<string, string|int> $dados
     * @param array{peso?: int, descricao?: string} $meta
     */
    private function evento(string $titulo, ?Objeto $pai = null, array $dados = [], string $status = 'publicado', array $meta = []): Objeto
    {
        static $n = 0;
        $n++;

        return $this->s->objetos->criar($this->site->id, $this->evento, ($pai ?? $this->sobre)->id, $titulo, 'evento-' . $n . '-' . bin2hex(random_bytes(2)), $dados, $status, $meta);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function formEvento(string $titulo, array $extra = []): array
    {
        return $extra + ['classe' => (string) $this->evento->id, 'titulo' => $titulo, 'descricao' => '', 'url' => '', 'peso' => '0', 'dados' => []];
    }

    /** @return list<array{quando: string, ator: string, acao: string, objeto_id: ?int, detalhe: ?string}> */
    private function auditoria(string $acao): array
    {
        return array_values(array_filter($this->s->auditoria->recentes(200), static fn (array $l): bool => $l['acao'] === $acao));
    }

    /** Id do objeto criado, tirado do `Location` do 303. */
    private function idCriado(Response $r): int
    {
        if (preg_match('#/objeto/(\d+)#', (string) $r->headers->get('Location'), $m) !== 1) {
            self::fail('não criou: ' . $this->html($r));
        }

        return (int) $m[1];
    }

    private function publicarSite(): void
    {
        $this->s->publicador->publicarSite($this->s->sites->porId($this->site->id) ?? self::fail());
    }

    public function testSemLoginNenhumaRotaNovaAbreENenhumPostGrava(): void
    {
        $id = $this->sobre->id;
        foreach (['editar', 'novo', 'novo?classe=' . $this->evento->id, 'estado', 'mover', 'apagar', 'versoes'] as $sub) {
            $r = $this->req('GET', "/_pbl/objeto/{$id}/{$sub}");
            self::assertSame(303, $r->getStatusCode(), $sub);
            self::assertSame('/_pbl/entrar', $r->headers->get('Location'));
        }
        foreach (['novo', 'estado', 'mover', 'apagar', 'recuperar', 'restaurar'] as $sub) {
            self::assertSame(303, $this->req('POST', "/_pbl/objeto/{$id}/{$sub}", null, ['versao' => '1', 'estado' => 'privado', 'destino' => '1', 'alvo' => '1'] + $this->formEvento('X'), self::ORIGEM)->getStatusCode(), $sub);
        }
        $depois = $this->obj($id);
        self::assertSame($this->sobre->versao, $depois->versao);
        self::assertSame('publicado', $depois->status);
        self::assertCount(1, $this->s->objetos->todosDaSubarvore($this->site->id, $this->sobre->caminho), 'nenhum filho criado');
    }

    public function testMetodoErradoOuSubrotaDesconhecidaDa404(): void
    {
        $token = $this->token('admin');
        $id = $this->sobre->id;
        self::assertSame(404, $this->req('GET', "/_pbl/objeto/{$id}/recuperar", $token)->getStatusCode());
        self::assertSame(404, $this->req('GET', "/_pbl/objeto/{$id}/restaurar", $token)->getStatusCode());
        self::assertSame(404, $this->req('POST', "/_pbl/objeto/{$id}/versoes", $token)->getStatusCode());
        self::assertSame(404, $this->req('GET', "/_pbl/objeto/{$id}/apagar-de-vez", $token)->getStatusCode());
        self::assertSame(404, $this->req('GET', "/_pbl/objeto/{$id}/x", null)->getStatusCode(), 'rota desconhecida é 404 até sem sessão');
    }

    public function testListaDeFilhosOrdenaEFiltraPorTipoEEstado(): void
    {
        $this->evento('Zebra', null, [], 'publicado', ['peso' => 1]);
        $this->evento('Abacate', null, [], 'privado', ['peso' => 5]);
        $this->s->objetos->criar($this->site->id, $this->pagina, $this->sobre->id, 'Manga', 'manga', [], 'publicado', ['peso' => 3]);
        $this->evento('Lixo velho', null, [], 'apagado');
        $token = $this->token('editor');
        $base = '/_pbl/objeto/' . $this->sobre->id;

        $padrao = $this->html($this->req('GET', $base, $token));
        self::assertLessThan(strpos($padrao, 'Manga'), strpos($padrao, 'Zebra'), 'padrão: peso');
        self::assertLessThan(strpos($padrao, 'Abacate'), strpos($padrao, 'Manga'));
        self::assertStringNotContainsString('Lixo velho', $padrao, 'a lixeira fica fora da lista padrão');

        $titulo = $this->html($this->req('GET', $base . '?ordem=titulo', $token));
        self::assertLessThan(strpos($titulo, 'Manga'), strpos($titulo, 'Abacate'));
        self::assertLessThan(strpos($titulo, 'Zebra'), strpos($titulo, 'Manga'));
        self::assertStringContainsString('<option value="titulo" selected>', $titulo);

        $soEventos = $this->html($this->req('GET', $base . '?classe=' . $this->evento->id, $token));
        self::assertStringContainsString('Zebra', $soEventos);
        self::assertStringNotContainsString('Manga', $soEventos);

        $privados = $this->html($this->req('GET', $base . '?estado=privado', $token));
        self::assertStringContainsString('Abacate', $privados);
        self::assertStringNotContainsString('Zebra', $privados);

        $lixeira = $this->html($this->req('GET', $base . '?estado=apagado', $token));
        self::assertStringContainsString('Lixo velho', $lixeira);
        self::assertStringNotContainsString('Zebra', $lixeira);

        $estranho = $this->req('GET', $base . '?ordem=id;DROP&classe=99999&estado=%3Cx%3E&inicio=-1', $token);
        self::assertSame(200, $estranho->getStatusCode(), 'parâmetros desconhecidos são ignorados');
        self::assertStringContainsString('Zebra', $this->html($estranho));
        self::assertStringNotContainsString('<x>', $this->html($estranho));
    }

    public function testPaginacaoMantemOrdemEFiltros(): void
    {
        for ($i = 0; $i < 101; $i++) {
            $this->evento(sprintf('Item %03d', $i));
        }
        $html = $this->html($this->req('GET', '/_pbl/objeto/' . $this->sobre->id . '?ordem=titulo&classe=' . $this->evento->id, $this->token('admin')));

        self::assertStringContainsString('de 101', $html);
        self::assertStringContainsString('?ordem=titulo&amp;classe=' . $this->evento->id . '&amp;inicio=100', $html);
    }

    public function testCriarFilhoComPropriedadesDeCadaTipo(): void
    {
        $token = $this->token('admin');
        $base = '/_pbl/objeto/' . $this->sobre->id . '/novo';

        $escolha = $this->html($this->req('GET', $base, $token));
        self::assertStringContainsString('<option value="' . $this->evento->id . '">evento</option>', $escolha);
        $form = $this->html($this->req('GET', $base . '?classe=' . $this->evento->id, $token));
        foreach (['dados[local]', 'dados[vagas]', 'dados[quando]', 'dados[aberto]', 'dados[pagina_relacionada]', 'dados[corpo]'] as $campo) {
            self::assertStringContainsString('name="' . $campo . '"', $form);
        }
        self::assertStringContainsString('<label for="campo-dados-vagas"', $form);
        self::assertStringContainsString('name="_csrf" value="' . $this->csrf($token) . '"', $form);

        $r = $this->post($token, $base, $this->formEvento('Notícia de Hoje!', [
            'descricao' => "Linha 1\r\nLinha 2", 'peso' => '7',
            'dados' => ['local' => ' Auditório ', 'vagas' => '3,5', 'quando' => '2026-12-31 18:30', 'aberto' => '1', 'pagina_relacionada' => (string) $this->inicio->id, 'corpo' => '<p>Oi</p>'],
        ]));

        self::assertSame(303, $r->getStatusCode(), $this->html($r));
        self::assertMatchesRegularExpression('#^/_pbl/objeto/(\d+)\?salvo=criado$#', (string) $r->headers->get('Location'));
        $novo = $this->obj($this->idCriado($r));
        self::assertSame('Notícia de Hoje!', $novo->titulo);
        self::assertSame('noticia-de-hoje', $novo->urlAmigavel, 'endereço gerado a partir do título');
        self::assertSame('privado', $novo->status, 'sempre nasce fora do ar');
        self::assertSame($this->sobre->id, $novo->paiId);
        self::assertSame($this->ids['admin'], $novo->criadoPor);
        self::assertSame(7, $novo->peso);
        self::assertSame("Linha 1\nLinha 2", $novo->descricao);
        self::assertSame(['local' => 'Auditório', 'vagas' => 3.5, 'quando' => '2026-12-31 18:30:00', 'aberto' => 1, 'pagina_relacionada' => $this->inicio->id, 'corpo' => '<p>Oi</p>'], $novo->dados);
        self::assertStringContainsString('Conteúdo criado', $this->html($this->req('GET', (string) $r->headers->get('Location'), $token)));

        $outro = $this->post($token, $base, $this->formEvento('Notícia de hoje'));
        $segundo = $this->obj($this->idCriado($outro));
        self::assertSame('noticia-de-hoje-2', $segundo->urlAmigavel, 'endereço já usado ganha sufixo');
        self::assertSame([], $segundo->dados, 'campos vazios não gravam nada');

        $criar = $this->auditoria('objeto.criar');
        self::assertNotSame([], $criar);
        self::assertStringNotContainsString('Notícia', (string) $criar[0]['detalhe']);
        self::assertStringNotContainsString('Auditório', (string) $criar[1]['detalhe']);
    }

    public function testCriarValidaCadaCampoNoServidor(): void
    {
        $token = $this->token('admin');
        $base = '/_pbl/objeto/' . $this->sobre->id . '/novo';
        $outroEvento = $this->evento('Outro evento');
        $this->s->objetos->atualizar($outroEvento->id, ['url_amigavel' => 'endereco-novo']); // o antigo vira histórico
        $casos = [
            'número' => ['dados' => ['vagas' => 'muitas']],
            'data' => ['dados' => ['quando' => '2026-02-30']],
            'booleano' => ['dados' => ['aberto' => 'talvez']],
            'referência inexistente' => ['dados' => ['pagina_relacionada' => '999999']],
            'referência de outro tipo' => ['dados' => ['pagina_relacionada' => (string) $outroEvento->id]],
            'html longo' => ['dados' => ['corpo' => str_repeat('a', 200001)]],
            'controle' => ['dados' => ['local' => "a\x01b"]],
            'endereço inválido' => ['url' => 'Com Espaço'],
            'endereço reservado' => ['url' => 'index'],
            'endereço usado' => ['url' => 'sobre'],
            'endereço no histórico' => ['url' => $outroEvento->urlAmigavel],
            'peso' => ['peso' => '1.5'],
            'título vazio' => ['titulo' => ' '],
        ];
        $antes = count($this->s->objetos->todosDaSubarvore($this->site->id, '/'));
        foreach ($casos as $nome => $extra) {
            $r = $this->post($token, $base, $this->formEvento('Válido', $extra));
            self::assertSame(422, $r->getStatusCode(), $nome);
            self::assertStringContainsString('aria-invalid="true"', $this->html($r), $nome);
        }
        self::assertSame(400, $this->post($token, $base, $this->formEvento('X', ['dados' => ['local' => ['a']]]))->getStatusCode(), 'valor não textual');
        self::assertSame(400, $this->post($token, $base, $this->formEvento('X', ['classe' => '999999']))->getStatusCode(), 'classe inexistente');
        self::assertCount($antes, $this->s->objetos->todosDaSubarvore($this->site->id, '/'), 'nada foi criado');
    }

    public function testQuemCriaOndePode(): void
    {
        $base = '/_pbl/objeto/' . $this->inicio->id . '/novo';

        $leitor = $this->token('leitor');
        self::assertSame(403, $this->req('GET', $base, $leitor)->getStatusCode());
        self::assertSame(403, $this->post($leitor, $base, $this->formEvento('Do leitor'))->getStatusCode());
        $negado = $this->auditoria('objeto.criar.negado');
        self::assertCount(1, $negado);
        self::assertNull($negado[0]['detalhe']);

        self::assertSame(403, $this->post($this->token('editor'), $base, $this->formEvento('Fora da seção'))->getStatusCode(), 'editor de Sobre não cria no início');

        $autor = $this->token('autor');
        $r = $this->post($autor, $base, $this->formEvento('Do autor'));
        self::assertSame(303, $r->getStatusCode());
        $meu = $this->obj($this->idCriado($r));
        self::assertSame($this->ids['autor'], $meu->criadoPor);
        self::assertSame(200, $this->req('GET', '/_pbl/objeto/' . $meu->id . '/editar', $autor)->getStatusCode(), 'o autor edita o que criou');
        self::assertSame(403, $this->req('GET', '/_pbl/objeto/' . $meu->id . '/estado', $autor)->getStatusCode(), 'mas não publica');
    }

    public function testReferenciaSoParaConteudoQueAPessoaPodeVer(): void
    {
        $token = $this->token('editor'); // vê só a seção Sobre
        $base = '/_pbl/objeto/' . $this->sobre->id . '/novo';
        $naoPodeVer = $this->post($token, $base, $this->formEvento('X', ['dados' => ['pagina_relacionada' => (string) $this->inicio->id]]));
        $naoExiste = $this->post($token, $base, $this->formEvento('X', ['dados' => ['pagina_relacionada' => '999999']]));

        self::assertSame(422, $naoPodeVer->getStatusCode());
        self::assertSame(422, $naoExiste->getStatusCode());
        self::assertStringContainsString('que você possa ver', $this->html($naoPodeVer));
        self::assertStringContainsString('que você possa ver', $this->html($naoExiste), 'mesma mensagem: não revela ids alheios');
        self::assertSame(303, $this->post($token, $base, $this->formEvento('Ok', ['dados' => ['pagina_relacionada' => (string) $this->sobre->id]]))->getStatusCode());

        // Uma referência já gravada para algo que a pessoa não vê continua valendo ao salvar outros campos.
        $e = $this->evento('Com ref', null, ['pagina_relacionada' => $this->inicio->id]);
        self::assertStringNotContainsString('Meu Site', $this->html($this->req('GET', '/_pbl/objeto/' . $e->id, $token)), 'título do alvo invisível não aparece');
        $r = $this->post($token, '/_pbl/objeto/' . $e->id . '/editar', ['versao' => (string) $e->versao, 'titulo' => 'Com ref 2', 'descricao' => '', 'url' => $e->urlAmigavel, 'peso' => '0', 'dados' => ['pagina_relacionada' => (string) $this->inicio->id]]);
        self::assertSame(303, $r->getStatusCode());
    }

    public function testEditarTodasAsPropriedadesTrocaEnderecoERepublica(): void
    {
        $e = $this->evento('Palestra', null, ['local' => 'Sala 1', 'vagas' => 10]);
        $antigo = $e->urlAmigavel;
        $this->publicarSite();
        self::assertFileExists($this->caminhos->pagina($this->site->id, $antigo));
        $token = $this->token('editor');

        $form = $this->html($this->req('GET', '/_pbl/objeto/' . $e->id . '/editar', $token));
        self::assertStringContainsString('>Sala 1</textarea>', $form);
        self::assertStringContainsString('name="dados[vagas]" aria-describedby="ajuda-dados-vagas" inputmode="decimal" value="10"', $form);

        $r = $this->post($token, '/_pbl/objeto/' . $e->id . '/editar', [
            'versao' => (string) $e->versao, 'titulo' => 'Palestra nova', 'descricao' => '', 'url' => 'palestras/nova', 'peso' => '2',
            'dados' => ['local' => '', 'vagas' => '12', 'quando' => '', 'aberto' => '0', 'pagina_relacionada' => '', 'corpo' => ''],
        ]);

        self::assertSame(303, $r->getStatusCode(), $this->html($r));
        self::assertSame('/_pbl/objeto/' . $e->id . '?salvo=sim', $r->headers->get('Location'));
        $novo = $this->obj($e->id);
        self::assertSame(['vagas' => 12, 'aberto' => 0], $novo->dados, 'vazio remove; o resto é convertido pelo tipo');
        self::assertSame('palestras/nova', $novo->urlAmigavel);
        self::assertSame(2, $novo->peso);
        self::assertFileDoesNotExist($this->caminhos->pagina($this->site->id, $antigo), 'a página no endereço antigo sai do cache');
        self::assertFileExists($this->caminhos->pagina($this->site->id, 'palestras/nova'));
        $publico = $this->req('GET', '/' . $antigo);
        self::assertSame(301, $publico->getStatusCode(), 'o endereço antigo redireciona');
        self::assertStringEndsWith('/palestras/nova', (string) $publico->headers->get('Location'));

        $linhas = array_values(array_filter($this->auditoria('objeto.atualizar'), static fn (array $l): bool => $l['objeto_id'] === $e->id));
        self::assertCount(1, $linhas);
        $detalhe = (string) $linhas[0]['detalhe'];
        self::assertStringContainsString('dados.vagas', $detalhe);
        self::assertStringContainsString('dados.local', $detalhe);
        self::assertStringNotContainsString('Sala 1', $detalhe);
        self::assertStringNotContainsString('Palestra', $detalhe);
    }

    public function testEditarSemMudancaNaoGravaEPaginaInicialNaoTrocaDeEndereco(): void
    {
        $e = $this->evento('Igual', null, ['vagas' => 5]);
        $token = $this->token('admin');
        $r = $this->post($token, '/_pbl/objeto/' . $e->id . '/editar', ['versao' => (string) $e->versao, 'titulo' => 'Igual', 'descricao' => '', 'url' => $e->urlAmigavel, 'peso' => '0', 'dados' => ['vagas' => '5', 'local' => '']]);
        self::assertSame('/_pbl/objeto/' . $e->id . '?salvo=nada', $r->headers->get('Location'));
        self::assertSame($e->versao, $this->obj($e->id)->versao);

        $form = $this->html($this->req('GET', '/_pbl/objeto/' . $this->inicio->id . '/editar', $token));
        self::assertStringNotContainsString('name="url"', $form, 'a página inicial não mostra o campo de endereço');
        $this->post($token, '/_pbl/objeto/' . $this->inicio->id . '/editar', ['versao' => (string) $this->inicio->versao, 'titulo' => 'Início', 'descricao' => '', 'url' => 'tentativa']);
        self::assertSame('', $this->obj($this->inicio->id)->urlAmigavel);
    }

    public function testEdicaoConcorrenteDePropriedadesDa409SemPerderODigitado(): void
    {
        $e = $this->evento('Concorrido', null, ['local' => 'A']);
        $this->s->objetos->atualizar($e->id, ['dados' => ['local' => 'Salvo por outra']], 'outra', null, false, $this->ids['editor']);

        $r = $this->post($this->token('admin'), '/_pbl/objeto/' . $e->id . '/editar', ['versao' => (string) $e->versao, 'titulo' => 'Concorrido', 'descricao' => '', 'url' => $e->urlAmigavel, 'peso' => '0', 'dados' => ['local' => 'Minha versão']]);

        self::assertSame(409, $r->getStatusCode());
        self::assertStringContainsString('>Minha versão</textarea>', $this->html($r));
        self::assertStringContainsString('Salvo por outra', $this->html($r));
        self::assertStringContainsString('name="versao" value="' . ($e->versao + 1) . '"', $this->html($r));
        self::assertSame('Salvo por outra', $this->obj($e->id)->dados['local']);
    }

    public function testPublicarDespublicarEDatas(): void
    {
        $e = $this->evento('Rascunho', null, [], 'privado');
        $arquivo = $this->caminhos->pagina($this->site->id, $e->urlAmigavel);
        $token = $this->token('editor');
        $url = '/_pbl/objeto/' . $e->id . '/estado';

        self::assertStringContainsString('<option value="privado" selected>', $this->html($this->req('GET', $url, $token)));
        $r = $this->post($token, $url, ['versao' => (string) $e->versao, 'estado' => 'publicado', 'data_publicacao' => '', 'data_validade' => '2099-12-31T18:30']);
        self::assertSame('/_pbl/objeto/' . $e->id . '?salvo=estado', $r->headers->get('Location'));
        $pub = $this->obj($e->id);
        self::assertSame('publicado', $pub->status);
        self::assertSame('2099-12-31T21:30:00+00:00', $pub->dataValidade, 'horário de Brasília gravado em UTC');
        self::assertFileExists($arquivo, 'publicar grava a página na hora');
        self::assertStringContainsString('value="2099-12-31T18:30"', $this->html($this->req('GET', $url, $token)), 'e mostra de volta no mesmo fuso');

        $r = $this->post($token, $url, ['versao' => (string) $pub->versao, 'estado' => 'privado', 'data_publicacao' => '', 'data_validade' => '2099-12-31T18:30']);
        self::assertSame('/_pbl/objeto/' . $e->id . '?salvo=estado', $r->headers->get('Location'));
        self::assertSame('privado', $this->obj($e->id)->status);
        self::assertFileDoesNotExist($arquivo, 'despublicar tira a página do cache na hora');

        $atual = $this->obj($e->id);
        $r = $this->post($token, $url, ['versao' => (string) $atual->versao, 'estado' => 'publicado', 'data_publicacao' => '2099-01-01T00:00', 'data_validade' => '2099-12-31T18:30']);
        self::assertSame('/_pbl/objeto/' . $e->id . '?salvo=agendado', $r->headers->get('Location'));
        self::assertFileDoesNotExist($arquivo, 'publicação futura não vai ao ar antes da data');

        $atual = $this->obj($e->id);
        $mesma = $this->post($token, $url, ['versao' => (string) $atual->versao, 'estado' => 'publicado', 'data_publicacao' => '2099-01-01T00:00', 'data_validade' => '2099-12-31T18:30']);
        self::assertSame('/_pbl/objeto/' . $e->id . '?salvo=nada', $mesma->headers->get('Location'), 'as mesmas datas não criam versão');

        $estados = array_values(array_filter($this->auditoria('objeto.estado'), static fn (array $l): bool => $l['objeto_id'] === $e->id));
        self::assertCount(3, $estados);
    }

    public function testEdicaoSalvaEstadoEDatasJuntoEValida(): void
    {
        $e = $this->evento('Agendar na edição', null, [], 'privado');
        $token = $this->token('editor');
        $url = '/_pbl/objeto/' . $e->id . '/editar';
        $base = ['titulo' => 'Agendar na edição', 'descricao' => '', 'url' => $e->urlAmigavel, 'peso' => '0'];

        $form = $this->html($this->req('GET', $url, $token));
        foreach (['name="estado"', 'name="data_publicacao"', 'name="data_validade"', 'Vazio = assim que publicado.', 'Vazio = sem prazo.', 'name="peso"'] as $trecho) {
            self::assertStringContainsString($trecho, $form, $trecho);
        }
        self::assertStringContainsString('<option value="privado" selected>', $form);

        // datas invertidas: 422, nada gravado, o que foi digitado continua nos campos
        $r = $this->post($token, $url, $base + ['versao' => (string) $e->versao, 'estado' => 'publicado', 'data_publicacao' => '2099-12-31T10:00', 'data_validade' => '2099-12-30T10:00']);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('precisa ser depois da data de publicação', $this->html($r));
        self::assertStringContainsString('value="2099-12-31T10:00"', $this->html($r));
        self::assertSame('privado', $this->obj($e->id)->status);
        self::assertSame(422, $this->post($token, $url, $base + ['versao' => (string) $e->versao, 'estado' => 'publicado', 'data_publicacao' => '31/12/2026'])->getStatusCode());
        self::assertSame(422, $this->post($token, $url, $base + ['versao' => (string) $e->versao, 'estado' => 'qualquer'])->getStatusCode());

        // agendar pela edição, junto com outro campo
        $r = $this->post($token, $url, ['titulo' => 'Novo título', 'descricao' => '', 'url' => $e->urlAmigavel, 'peso' => '3', 'versao' => (string) $e->versao,
            'estado' => 'publicado', 'data_publicacao' => '2099-01-01T00:00', 'data_validade' => '2099-12-31T18:30']);
        self::assertSame('/_pbl/objeto/' . $e->id . '?salvo=agendado', $r->headers->get('Location'));
        $salvo = $this->obj($e->id);
        self::assertSame('publicado', $salvo->status);
        self::assertSame('Novo título', $salvo->titulo);
        self::assertSame(3, $salvo->peso);
        self::assertSame('2099-01-01T03:00:00+00:00', $salvo->dataPublicacao, 'Brasília gravado em UTC');
        self::assertSame('2099-12-31T21:30:00+00:00', $salvo->dataValidade);

        // a tela mostra de volta (mesmo fuso), o selo de agendamento e as datas nos metadados
        self::assertStringContainsString('value="2099-12-31T18:30"', $this->html($this->req('GET', $url, $token)));
        $ver = $this->html($this->req('GET', '/_pbl/objeto/' . $e->id, $token));
        self::assertStringContainsString('agendado para 01/01/2099 00:00', $ver);
        self::assertStringContainsString('Fora do ar a partir de', $ver);

        // sem mudança nenhuma: nada de versão nova; limpar as datas pela edição também funciona
        $mesma = $this->post($token, $url, ['titulo' => 'Novo título', 'descricao' => '', 'url' => $salvo->urlAmigavel, 'peso' => '3', 'versao' => (string) $salvo->versao,
            'estado' => 'publicado', 'data_publicacao' => '2099-01-01T00:00', 'data_validade' => '2099-12-31T18:30']);
        self::assertSame('/_pbl/objeto/' . $e->id . '?salvo=nada', $mesma->headers->get('Location'));
        $limpo = $this->post($token, $url, ['titulo' => 'Novo título', 'descricao' => '', 'url' => $salvo->urlAmigavel, 'peso' => '3', 'versao' => (string) $salvo->versao,
            'estado' => 'publicado', 'data_publicacao' => '', 'data_validade' => '']);
        self::assertSame('/_pbl/objeto/' . $e->id . '?salvo=sim', $limpo->headers->get('Location'));
        self::assertNull($this->obj($e->id)->dataPublicacao);
        self::assertNull($this->obj($e->id)->dataValidade);
    }

    public function testEdicaoSemPermissaoDePublicarNaoMostraNemGravaEstado(): void
    {
        $meu = $this->s->objetos->criar($this->site->id, $this->pagina, $this->inicio->id, 'Do autor 2', 'do-autor-2', [], 'privado', ['criado_por' => $this->ids['autor']]);
        $autor = $this->token('autor');
        $url = '/_pbl/objeto/' . $meu->id . '/editar';

        self::assertStringNotContainsString('name="estado"', $this->html($this->req('GET', $url, $autor)));
        $this->post($autor, $url, ['titulo' => 'Do autor 2', 'descricao' => 'x', 'url' => 'do-autor-2', 'peso' => '0', 'versao' => (string) $meu->versao, 'estado' => 'publicado']);
        self::assertSame('privado', $this->obj($meu->id)->status, 'o estado vindo no pedido é ignorado sem permissão de publicar');
    }

    public function testTelaDoConteudoUsaSelosGradeCartoesEMaisAcoes(): void
    {
        $pai = $this->evento('Pai da lista');
        $this->evento('Filho um', $pai);
        $html = $this->html($this->req('GET', '/_pbl/objeto/' . $pai->id, $this->token('editor')));

        foreach (['class="pbl-selos"', 'class="pbl-meta"', 'class="pbl-card pbl-conteudo"', '>Mais ações</button>', 'Filtrar e ordenar', 'data-bs-target="#filtros-lista"', 'class="list-group pbl-filhos d-md-none', 'pbl-filho-titulo'] as $trecho) {
            self::assertStringContainsString($trecho, $html, $trecho);
        }
        self::assertStringContainsString('class="btn btn-primary" href="/_pbl/objeto/' . $pai->id . '/editar"', $html, 'Editar sempre à vista');
        self::assertStringNotContainsString('<th scope="row">', $html, 'sem a tabela de propriedades de rótulo/valor');
        self::assertStringNotContainsString('style=', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testCorpoHtmlApareceRenderizadoELimpoComCodigoRecolhido(): void
    {
        $e = $this->evento('Com corpo', null, ['corpo' => '<p>Olá <strong>mundo</strong></p>']);
        $html = $this->html($this->req('GET', '/_pbl/objeto/' . $e->id, $this->token('editor')));

        self::assertStringContainsString('<div class="pbl-corpo"><p>Olá <strong>mundo</strong></p></div>', $html);
        self::assertStringContainsString('<details class="pbl-ver-html"><summary>Ver HTML</summary>', $html);
        self::assertStringContainsString('&lt;p&gt;Olá &lt;strong&gt;mundo', $html, 'o código aparece escapado, recolhido');
    }

    public function testEstadoValidaEConcorrencia(): void
    {
        $e = $this->evento('Validar', null, [], 'privado');
        $token = $this->token('admin');
        $url = '/_pbl/objeto/' . $e->id . '/estado';
        $v = (string) $e->versao;

        self::assertSame(422, $this->post($token, $url, ['versao' => $v, 'estado' => 'apagado'])->getStatusCode(), 'a lixeira tem tela própria');
        self::assertSame(422, $this->post($token, $url, ['versao' => $v, 'estado' => 'qualquer'])->getStatusCode());
        self::assertSame(422, $this->post($token, $url, ['versao' => $v, 'estado' => 'publicado', 'data_publicacao' => '31/12/2026'])->getStatusCode());
        self::assertSame(422, $this->post($token, $url, ['versao' => $v, 'estado' => 'publicado', 'data_publicacao' => '2026-12-31T10:00', 'data_validade' => '2026-12-30T10:00'])->getStatusCode());
        self::assertSame(400, $this->post($token, $url, ['versao' => 'x', 'estado' => 'publicado'])->getStatusCode());
        self::assertSame('privado', $this->obj($e->id)->status);

        $this->s->objetos->atualizar($e->id, ['titulo' => 'Mudou'], 'outra');
        $r = $this->post($token, $url, ['versao' => $v, 'estado' => 'publicado']);
        self::assertSame(409, $r->getStatusCode());
        self::assertStringContainsString('Outra pessoa alterou', $this->html($r));
        self::assertSame('privado', $this->obj($e->id)->status);
    }

    public function testQuemNaoPublicaLeva403ENegacaoAuditada(): void
    {
        $meu = $this->s->objetos->criar($this->site->id, $this->pagina, $this->inicio->id, 'Do autor', 'do-autor', [], 'privado', ['criado_por' => $this->ids['autor']]);
        $autor = $this->token('autor');

        self::assertSame(403, $this->req('GET', '/_pbl/objeto/' . $meu->id . '/estado', $autor)->getStatusCode());
        self::assertSame(403, $this->post($autor, '/_pbl/objeto/' . $meu->id . '/estado', ['versao' => (string) $meu->versao, 'estado' => 'publicado'])->getStatusCode());
        self::assertSame('privado', $this->obj($meu->id)->status);
        self::assertCount(1, $this->auditoria('objeto.estado.negado'));
        self::assertStringNotContainsString('/estado"', $this->html($this->req('GET', '/_pbl/objeto/' . $meu->id, $autor)), 'sem botão de publicar');
    }

    public function testMoverLevaASubarvoreEValidaODestino(): void
    {
        $a = $this->evento('Pasta A');
        $b = $this->evento('Pasta B');
        $filho = $this->evento('Filho de A', $a);
        $neto = $this->evento('Neto', $filho);
        $token = $this->token('editor');
        $url = '/_pbl/objeto/' . $filho->id . '/mover';

        self::assertSame(200, $this->req('GET', $url, $token)->getStatusCode());
        self::assertSame(422, $this->post($token, $url, ['versao' => (string) $filho->versao, 'destino' => (string) $neto->id])->getStatusCode(), 'para dentro de si mesmo');
        self::assertSame(422, $this->post($token, $url, ['versao' => (string) $filho->versao, 'destino' => (string) $this->inicio->id])->getStatusCode(), 'destino onde não pode criar');
        $semPermissao = $this->html($this->post($token, $url, ['versao' => (string) $filho->versao, 'destino' => (string) $this->inicio->id]));
        $inexistente = $this->html($this->post($token, $url, ['versao' => (string) $filho->versao, 'destino' => '999999']));
        self::assertStringContainsString('onde você possa criar', $semPermissao);
        self::assertStringContainsString('onde você possa criar', $inexistente, 'mesma mensagem');

        $r = $this->post($token, $url, ['versao' => (string) $filho->versao, 'destino' => (string) $b->id]);
        self::assertSame('/_pbl/objeto/' . $filho->id . '?salvo=movido', $r->headers->get('Location'));
        $movido = $this->obj($filho->id);
        self::assertSame($b->id, $movido->paiId);
        self::assertSame($b->caminho . $filho->id . '/', $movido->caminho);
        self::assertSame($movido->caminho . $neto->id . '/', $this->obj($neto->id)->caminho, 'a subárvore vai junto');
        self::assertSame($filho->urlAmigavel, $movido->urlAmigavel, 'o endereço não muda');

        $r = $this->post($token, $url, ['versao' => (string) $filho->versao, 'destino' => (string) $a->id]);
        self::assertSame(409, $r->getStatusCode(), 'versão velha');
        self::assertSame($b->id, $this->obj($filho->id)->paiId);
        self::assertCount(1, $this->auditoria('objeto.mover'));
    }

    public function testMoverPaginaInicialEQuemNaoPode(): void
    {
        $admin = $this->token('admin');
        self::assertSame(409, $this->req('GET', '/_pbl/objeto/' . $this->inicio->id . '/mover', $admin)->getStatusCode());
        self::assertSame(409, $this->post($admin, '/_pbl/objeto/' . $this->inicio->id . '/mover', ['versao' => (string) $this->inicio->versao, 'destino' => (string) $this->sobre->id])->getStatusCode());

        $leitor = $this->token('leitor');
        self::assertSame(403, $this->req('GET', '/_pbl/objeto/' . $this->sobre->id . '/mover', $leitor)->getStatusCode());
        self::assertSame(403, $this->post($leitor, '/_pbl/objeto/' . $this->sobre->id . '/mover', ['versao' => (string) $this->sobre->versao, 'destino' => (string) $this->inicio->id])->getStatusCode());
        self::assertCount(1, $this->auditoria('objeto.mover.negado'));
    }

    public function testLixeiraTiraDoArBloqueiaEdicaoERecupera(): void
    {
        $e = $this->evento('Para a lixeira');
        $this->publicarSite();
        $arquivo = $this->caminhos->pagina($this->site->id, $e->urlAmigavel);
        self::assertFileExists($arquivo);
        $token = $this->token('editor');

        $confirmacao = $this->html($this->req('GET', '/_pbl/objeto/' . $e->id . '/apagar', $token));
        self::assertStringContainsString('Nada é apagado de vez', $confirmacao);
        self::assertStringContainsString('action="/_pbl/objeto/' . $e->id . '/apagar"', $confirmacao);

        $r = $this->post($token, '/_pbl/objeto/' . $e->id . '/apagar', ['versao' => (string) $e->versao]);
        self::assertSame('/_pbl/objeto/' . $this->sobre->id . '?salvo=apagado', $r->headers->get('Location'));
        self::assertSame('apagado', $this->obj($e->id)->status);
        self::assertFileDoesNotExist($arquivo, 'sai do ar na hora');
        self::assertSame(404, $this->req('GET', '/' . $e->urlAmigavel)->getStatusCode());

        foreach (['editar', 'estado', 'mover', 'apagar', 'novo'] as $sub) {
            self::assertSame(409, $this->req('GET', '/_pbl/objeto/' . $e->id . '/' . $sub, $token)->getStatusCode(), $sub);
        }
        $naLixeira = $this->obj($e->id);
        self::assertSame(409, $this->post($token, '/_pbl/objeto/' . $e->id . '/editar', ['versao' => (string) $naLixeira->versao, 'titulo' => 'X', 'descricao' => ''])->getStatusCode());
        self::assertStringContainsString('Tirar da lixeira', $this->html($this->req('GET', '/_pbl/objeto/' . $e->id, $token)));

        $r = $this->post($token, '/_pbl/objeto/' . $e->id . '/recuperar', ['versao' => (string) $naLixeira->versao]);
        self::assertSame('/_pbl/objeto/' . $e->id . '?salvo=recuperado', $r->headers->get('Location'));
        self::assertSame('privado', $this->obj($e->id)->status, 'volta fora do ar');

        $trocas = array_values(array_filter($this->auditoria('objeto.estado'), static fn (array $l): bool => $l['objeto_id'] === $e->id));
        self::assertCount(2, $trocas);
        self::assertStringContainsString('apagado', (string) $trocas[1]['detalhe']);
        self::assertStringNotContainsString('lixeira', (string) $trocas[1]['detalhe']);
    }

    public function testLixeiraRecusaComFilhosPaginaInicialEQuemNaoPode(): void
    {
        $pai = $this->evento('Com filhos');
        $filho = $this->evento('Filho', $pai);
        $editor = $this->token('editor');

        self::assertStringContainsString('Mova ou mande para a lixeira o que está dentro antes', $this->html($this->req('GET', '/_pbl/objeto/' . $pai->id . '/apagar', $editor)));
        self::assertSame(409, $this->post($editor, '/_pbl/objeto/' . $pai->id . '/apagar', ['versao' => (string) $pai->versao])->getStatusCode());
        self::assertSame('publicado', $this->obj($pai->id)->status);

        $this->post($editor, '/_pbl/objeto/' . $filho->id . '/apagar', ['versao' => (string) $filho->versao]);
        self::assertSame(303, $this->post($editor, '/_pbl/objeto/' . $pai->id . '/apagar', ['versao' => (string) $pai->versao])->getStatusCode(), 'filho na lixeira não impede');
        self::assertSame(409, $this->post($editor, '/_pbl/objeto/' . $filho->id . '/recuperar', ['versao' => (string) $this->obj($filho->id)->versao])->getStatusCode(), 'pai ainda na lixeira');

        $admin = $this->token('admin');
        self::assertSame(409, $this->post($admin, '/_pbl/objeto/' . $this->inicio->id . '/apagar', ['versao' => (string) $this->inicio->versao])->getStatusCode(), 'página inicial');

        $meu = $this->s->objetos->criar($this->site->id, $this->pagina, $this->inicio->id, 'Do autor', 'do-autor', [], 'privado', ['criado_por' => $this->ids['autor']]);
        $autor = $this->token('autor');
        self::assertSame(403, $this->req('GET', '/_pbl/objeto/' . $meu->id . '/apagar', $autor)->getStatusCode());
        self::assertSame(403, $this->post($autor, '/_pbl/objeto/' . $meu->id . '/apagar', ['versao' => (string) $meu->versao])->getStatusCode());
        self::assertSame(403, $this->post($autor, '/_pbl/objeto/' . $pai->id . '/recuperar', ['versao' => '1'])->getStatusCode());
        self::assertSame('privado', $this->obj($meu->id)->status);
        self::assertCount(1, $this->auditoria('objeto.apagar.negado'));
        self::assertCount(1, $this->auditoria('objeto.recuperar.negado'));

        $this->post($editor, '/_pbl/objeto/' . $pai->id . '/recuperar', ['versao' => (string) $this->obj($pai->id)->versao]);
        $this->post($editor, '/_pbl/objeto/' . $pai->id . '/apagar', ['versao' => (string) $this->obj($pai->id)->versao]);
        $secao = $this->post($editor, '/_pbl/objeto/' . $this->sobre->id . '/apagar', ['versao' => (string) $this->sobre->versao]);
        self::assertSame('/_pbl/objeto/' . $this->sobre->id . '?salvo=apagado', $secao->headers->get('Location'), 'sem ver a página-mãe, volta para a própria');
        $this->post($editor, '/_pbl/objeto/' . $this->sobre->id . '/recuperar', ['versao' => (string) $this->obj($this->sobre->id)->versao]);

        $velha = $this->evento('Versão velha');
        $this->s->objetos->atualizar($velha->id, ['titulo' => 'Mudou'], 'outra');
        self::assertSame(409, $this->post($editor, '/_pbl/objeto/' . $velha->id . '/apagar', ['versao' => (string) $velha->versao])->getStatusCode());
        self::assertSame('publicado', $this->obj($velha->id)->status);
    }

    public function testHistoricoListaERestauraSemMudarOEstado(): void
    {
        $e = $this->evento('Primeiro título', null, ['local' => 'Sala 1'], 'privado');
        $this->s->objetos->atualizar($e->id, ['titulo' => 'Segundo título', 'dados' => ['local' => 'Sala 2']], 'administracao', null, false, $this->ids['editor']);
        $this->s->objetos->atualizar($e->id, ['status' => 'publicado'], 'administracao', null, false, $this->ids['editor']);
        $atual = $this->obj($e->id);
        $token = $this->token('editor');

        $html = $this->html($this->req('GET', '/_pbl/objeto/' . $e->id . '/versoes', $token));
        self::assertStringContainsString('Primeiro título', $html);
        self::assertStringContainsString('Editor', $html, 'quem salvou');
        self::assertStringContainsString('Restaurar a versão 1', $html);
        self::assertStringNotContainsString('Restaurar a versão ' . $atual->versao, $html, 'a atual não se restaura');

        $r = $this->post($token, '/_pbl/objeto/' . $e->id . '/restaurar', ['versao' => (string) $atual->versao, 'alvo' => '1']);
        self::assertSame('/_pbl/objeto/' . $e->id . '?salvo=restaurado', $r->headers->get('Location'));
        $restaurado = $this->obj($e->id);
        self::assertSame('Primeiro título', $restaurado->titulo);
        self::assertSame('Sala 1', $restaurado->dados['local']);
        self::assertSame('publicado', $restaurado->status, 'restaurar o texto não tira do ar (a versão 1 era privada)');
        self::assertSame($atual->versao + 1, $restaurado->versao);
        self::assertSame('restaurada a versão 1', $this->s->objetos->versoes($e->id)[0]['motivo']);

        self::assertSame(409, $this->post($token, '/_pbl/objeto/' . $e->id . '/restaurar', ['versao' => (string) $atual->versao, 'alvo' => '2'])->getStatusCode(), 'histórico desatualizado');
        self::assertSame(422, $this->post($token, '/_pbl/objeto/' . $e->id . '/restaurar', ['versao' => (string) $restaurado->versao, 'alvo' => (string) $restaurado->versao])->getStatusCode(), 'a atual');
        self::assertSame(422, $this->post($token, '/_pbl/objeto/' . $e->id . '/restaurar', ['versao' => (string) $restaurado->versao, 'alvo' => '999'])->getStatusCode(), 'inexistente');
    }

    public function testRestaurarEnderecoQueOutroUsaEQuemNaoPode(): void
    {
        $e = $this->evento('Muda de endereço');
        $antigo = $e->urlAmigavel;
        $this->s->objetos->atualizar($e->id, ['url_amigavel' => 'endereco-novo']);
        $this->s->objetos->criar($this->site->id, $this->pagina, $this->sobre->id, 'Tomou', $antigo, [], 'publicado', [], true);
        $atual = $this->obj($e->id);

        $r = $this->post($this->token('admin'), '/_pbl/objeto/' . $e->id . '/restaurar', ['versao' => (string) $atual->versao, 'alvo' => '1']);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('endereço dela já é de outro conteúdo', $this->html($r));
        self::assertSame('endereco-novo', $this->obj($e->id)->urlAmigavel);

        $leitor = $this->token('leitor');
        $lista = $this->req('GET', '/_pbl/objeto/' . $e->id . '/versoes', $leitor);
        self::assertSame(200, $lista->getStatusCode(), 'o leitor vê o histórico');
        self::assertStringNotContainsString('/restaurar', $this->html($lista), 'mas sem botão de restaurar');
        self::assertSame(403, $this->post($leitor, '/_pbl/objeto/' . $e->id . '/restaurar', ['versao' => (string) $atual->versao, 'alvo' => '1'])->getStatusCode());
        self::assertCount(1, $this->auditoria('objeto.restaurar.negado'));
        $fora = $this->s->sessoes()->criar($this->s->usuarios->criar('fora', 'fora@exemplo.org', 'Fora', self::SENHA)->id);
        self::assertSame(403, $this->req('GET', '/_pbl/objeto/' . $e->id . '/versoes', $fora)->getStatusCode(), 'quem não vê não lê o histórico');
    }

    public function testCsrfEOrigemSaoConferidosEmTodoPostNovo(): void
    {
        $e = $this->evento('Alvo');
        $this->s->objetos->atualizar($e->id, ['titulo' => 'Alvo 2']);
        $e = $this->obj($e->id);
        $token = $this->token('admin');
        $outro = $this->token('editor');
        $base = '/_pbl/objeto/' . $e->id;
        $pedidos = [
            '/novo' => $this->formEvento('Novo'),
            '/editar' => ['versao' => (string) $e->versao, 'titulo' => 'Mudado', 'descricao' => ''],
            '/estado' => ['versao' => (string) $e->versao, 'estado' => 'privado'],
            '/mover' => ['versao' => (string) $e->versao, 'destino' => (string) $this->inicio->id],
            '/apagar' => ['versao' => (string) $e->versao],
            '/recuperar' => ['versao' => (string) $e->versao],
            '/restaurar' => ['versao' => (string) $e->versao, 'alvo' => '1'],
        ];
        foreach ($pedidos as $sub => $campos) {
            self::assertSame(403, $this->post($token, $base . $sub, ['_csrf' => str_repeat('0', 64)] + $campos)->getStatusCode(), $sub . ' token errado');
            self::assertSame(403, $this->post($token, $base . $sub, ['_csrf' => $this->csrf($outro)] + $campos)->getStatusCode(), $sub . ' token de outra sessão');
            self::assertSame(403, $this->req('POST', $base . $sub, $token, $campos, self::ORIGEM)->getStatusCode(), $sub . ' sem token');
            self::assertSame(403, $this->post($token, $base . $sub, $campos, ['HTTP_ORIGIN' => 'https://evil.example'])->getStatusCode(), $sub . ' outra origem');
            self::assertSame(403, $this->post($token, $base . $sub, $campos, ['HTTP_REFERER' => 'https://evil.example/x'])->getStatusCode(), $sub . ' referer de fora');
        }
        $depois = $this->obj($e->id);
        self::assertSame($e->versao, $depois->versao, 'nada foi gravado');
        self::assertSame('publicado', $depois->status);
        self::assertSame($e->paiId, $depois->paiId);
        self::assertCount(1, $this->s->objetos->todosDaSubarvore($this->site->id, $e->caminho), 'nenhum filho criado');
    }

    public function testTodaSaidaNovaEhEscapada(): void
    {
        $hostil = '"><script>alert(1)</script><img src=x onerror=alert(2)>';
        $e = $this->evento($hostil, null, ['local' => $hostil], 'publicado', ['descricao' => $hostil]);
        // Conteúdo antigo, gravado antes da limpeza do HTML: a administração o mostra como texto, nunca interpretado.
        Conexao::sqlite($this->caminhos->banco())->executeStatement('UPDATE objeto SET dados = ? WHERE id = ?', [json_encode(['local' => $hostil, 'corpo' => '<script>alert(3)</script>'], JSON_THROW_ON_ERROR), $e->id]);
        $this->s->objetos->atualizar($e->id, ['titulo' => $hostil . ' 2'], $hostil);
        $token = $this->token('admin');
        $telas = [];
        foreach (['', '/editar', '/estado', '/mover', '/apagar', '/versoes'] as $sub) {
            $telas[$sub] = $this->req('GET', '/_pbl/objeto/' . $e->id . $sub, $token);
        }
        $telas['lista'] = $this->req('GET', '/_pbl/objeto/' . $this->sobre->id . '?ordem=titulo', $token);
        $telas['novo'] = $this->req('GET', '/_pbl/objeto/' . $e->id . '/novo?classe=' . $this->evento->id, $token);
        $telas['erro'] = $this->post($token, '/_pbl/objeto/' . $e->id . '/editar', ['versao' => (string) $this->obj($e->id)->versao, 'titulo' => $hostil, 'descricao' => '', 'url' => 'X Y', 'dados' => ['local' => $hostil]]);
        foreach ($telas as $nome => $r) {
            $html = $this->html($r);
            self::assertStringNotContainsString('<script>', $html, (string) $nome);
            self::assertStringNotContainsString('<img src=x', $html, (string) $nome);
        }
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $this->html($telas['']));
        self::assertStringContainsString('&lt;script&gt;alert(3)&lt;/script&gt;', $this->html($telas['']), 'o html guardado aparece como texto');
        self::assertSame(422, $telas['erro']->getStatusCode());
    }

    public function testTiposAceitosComoFilhosValemAoCriarEAoMover(): void
    {
        $agenda = $this->s->classes->criar('agenda', [], ['filhas' => ['evento']]);
        $folha = $this->s->classes->criar('folha', [], ['aceita_filhos' => false]);
        $pastaAgenda = $this->s->objetos->criar($this->site->id, $agenda, $this->sobre->id, 'Agenda', 'agenda', []);
        $pastaFolha = $this->s->objetos->criar($this->site->id, $folha, $this->sobre->id, 'Folha', 'folha', []);
        $pagina = $this->s->objetos->criar($this->site->id, $this->pagina, $this->sobre->id, 'Uma página', 'uma-pagina', []);
        $token = $this->token('editor');

        $escolha = $this->html($this->req('GET', '/_pbl/objeto/' . $pastaAgenda->id . '/novo', $token));
        self::assertStringContainsString('>evento</option>', $escolha);
        self::assertStringNotContainsString('>pagina</option>', $escolha, 'só os tipos aceitos aparecem');
        self::assertStringContainsString('>evento</option>', $this->html($this->req('GET', '/_pbl/objeto/' . $pastaAgenda->id . '/novo?classe=' . $this->pagina->id, $token)), 'tipo não aceito volta para a escolha');
        $recusado = $this->post($token, '/_pbl/objeto/' . $pastaAgenda->id . '/novo', ['classe' => (string) $this->pagina->id, 'titulo' => 'Página na agenda', 'descricao' => '']);
        self::assertSame(422, $recusado->getStatusCode(), 'formulário forjado com tipo não aceito');
        self::assertSame(303, $this->post($token, '/_pbl/objeto/' . $pastaAgenda->id . '/novo', $this->formEvento('Evento na agenda'))->getStatusCode());

        self::assertStringNotContainsString('/novo"', $this->html($this->req('GET', '/_pbl/objeto/' . $pastaFolha->id, $token)), 'tipo que não aceita filhos: sem botão de criar');
        self::assertStringContainsString('não aceita conteúdo dentro', $this->html($this->req('GET', '/_pbl/objeto/' . $pastaFolha->id . '/novo', $token)));
        self::assertSame(422, $this->post($token, '/_pbl/objeto/' . $pastaFolha->id . '/novo', $this->formEvento('Na folha'))->getStatusCode());

        $mover = $this->post($token, '/_pbl/objeto/' . $pagina->id . '/mover', ['versao' => (string) $pagina->versao, 'destino' => (string) $pastaAgenda->id]);
        self::assertSame(422, $mover->getStatusCode());
        self::assertStringContainsString('não aceita conteúdo do tipo pagina', $this->html($mover));
        self::assertSame($this->sobre->id, $this->obj($pagina->id)->paiId);
    }

    public function testCamposObrigatoriosArquivadosERotulos(): void
    {
        $ficha = $this->s->classes->criar('ficha', [
            ['nome' => 'codigo', 'tipo' => 'texto', 'rotulo' => 'Código <interno>', 'obrigatorio' => true],
            ['nome' => 'antigo', 'tipo' => 'texto'],
        ]);
        $o = $this->s->objetos->criar($this->site->id, $ficha, $this->sobre->id, 'Ficha', 'ficha', ['codigo' => 'A1', 'antigo' => 'valor guardado']);
        $ficha = $this->s->classes->atualizar($ficha->id, $ficha->versao, [
            ['nome' => 'codigo', 'tipo' => 'texto', 'rotulo' => 'Código <interno>', 'obrigatorio' => true],
            ['nome' => 'antigo', 'tipo' => 'texto', 'arquivada' => true],
        ], []);
        $token = $this->token('admin');

        $form = $this->html($this->req('GET', '/_pbl/objeto/' . $o->id . '/editar', $token));
        self::assertStringContainsString('Código &lt;interno&gt; (obrigatório)', $form, 'rótulo da classe, escapado');
        self::assertStringContainsString('name="dados[codigo]" aria-describedby="ajuda-dados-codigo" required', $form);
        self::assertStringNotContainsString('dados[antigo]', $form, 'campo arquivado fora do formulário');

        $vazio = $this->post($token, '/_pbl/objeto/' . $o->id . '/editar', ['versao' => (string) $o->versao, 'titulo' => 'Ficha', 'descricao' => '', 'url' => 'ficha', 'peso' => '0', 'dados' => ['codigo' => '']]);
        self::assertSame(422, $vazio->getStatusCode());
        self::assertStringContainsString('Este campo é obrigatório', $this->html($vazio));

        $forjado = $this->post($token, '/_pbl/objeto/' . $o->id . '/editar', ['versao' => (string) $o->versao, 'titulo' => 'Ficha 2', 'descricao' => '', 'url' => 'ficha', 'peso' => '0', 'dados' => ['codigo' => 'B2', 'antigo' => '']]);
        self::assertSame(303, $forjado->getStatusCode());
        self::assertSame(['codigo' => 'B2', 'antigo' => 'valor guardado'], $this->obj($o->id)->dados, 'o valor arquivado continua, mesmo com o campo forjado vazio');

        self::assertSame(422, $this->post($token, '/_pbl/objeto/' . $this->sobre->id . '/novo', ['classe' => (string) $ficha->id, 'titulo' => 'Sem código', 'descricao' => '', 'dados' => ['codigo' => ' ']])->getStatusCode(), 'obrigatório também ao criar');
    }

    /**
     * Simula a outra pessoa agindo no mesmo instante: um gatilho do SQLite faz a mudança concorrente **durante** a gravação,
     * depois da conferência que a tela e o repositório fazem antes. Só as reconferências depois da gravação podem pegá-la.
     */
    private function corrida(string $sql): void
    {
        Conexao::sqlite($this->caminhos->banco())->executeStatement($sql);
    }

    public function testCorridaCriarFilhoEnquantoOPaiVaiParaALixeira(): void
    {
        $pai = $this->evento('Pai da corrida');
        $this->corrida("CREATE TRIGGER corrida AFTER INSERT ON objeto WHEN NEW.titulo = 'Corrida' BEGIN UPDATE objeto SET status = 'apagado' WHERE id = NEW.pai_id; END");

        $r = $this->post($this->token('editor'), '/_pbl/objeto/' . $pai->id . '/novo', $this->formEvento('Corrida'));

        self::assertSame(409, $r->getStatusCode());
        self::assertSame('apagado', $this->obj($pai->id)->status);
        $filhos = array_values(array_filter($this->s->objetos->todosDaSubarvore($this->site->id, $pai->caminho), static fn (Objeto $o): bool => $o->id !== $pai->id));
        self::assertCount(1, $filhos);
        self::assertSame('apagado', $filhos[0]->status, 'o filho criado foi junto para a lixeira: nenhum fica fora dela sob pai apagado');
    }

    public function testCorridaMandarParaALixeiraEnquantoAlguemPoeUmFilho(): void
    {
        $pai = $this->evento('Pai na corrida');
        $filho = $this->evento('Filho', $pai, [], 'apagado');
        $this->corrida("CREATE TRIGGER corrida AFTER UPDATE OF status ON objeto WHEN NEW.id = {$pai->id} AND NEW.status = 'apagado' BEGIN UPDATE objeto SET status = 'privado' WHERE pai_id = NEW.id; END");

        $r = $this->post($this->token('editor'), '/_pbl/objeto/' . $pai->id . '/apagar', ['versao' => (string) $pai->versao]);

        self::assertSame(409, $r->getStatusCode());
        self::assertStringContainsString('Nada foi para a lixeira', $this->html($r));
        self::assertSame('publicado', $this->obj($pai->id)->status, 'voltou ao estado de antes');
        self::assertSame('privado', $this->obj($filho->id)->status);
    }

    public function testCorridaMoverParaUmDestinoQueVaiParaALixeira(): void
    {
        $origem = $this->evento('Origem');
        $destino = $this->evento('Destino');
        $item = $this->evento('Vai mover', $origem);
        $this->corrida("CREATE TRIGGER corrida AFTER UPDATE OF pai_id ON objeto WHEN NEW.id = {$item->id} AND NEW.pai_id = {$destino->id} BEGIN UPDATE objeto SET status = 'apagado' WHERE id = {$destino->id}; END");

        $r = $this->post($this->token('editor'), '/_pbl/objeto/' . $item->id . '/mover', ['versao' => (string) $item->versao, 'destino' => (string) $destino->id]);

        self::assertSame(409, $r->getStatusCode());
        self::assertSame($origem->id, $this->obj($item->id)->paiId, 'voltou para o lugar de antes');
        self::assertSame($origem->caminho . $item->id . '/', $this->obj($item->id)->caminho);
    }

    public function testCorridaTirarDaLixeiraEnquantoOPaiVaiParaEla(): void
    {
        $pai = $this->evento('Pai');
        $filho = $this->evento('Filho na lixeira', $pai, [], 'apagado');
        $this->corrida("CREATE TRIGGER corrida AFTER UPDATE OF status ON objeto WHEN NEW.id = {$filho->id} AND NEW.status = 'privado' BEGIN UPDATE objeto SET status = 'apagado' WHERE id = NEW.pai_id; END");

        $r = $this->post($this->token('editor'), '/_pbl/objeto/' . $filho->id . '/recuperar', ['versao' => (string) $filho->versao]);

        self::assertSame(409, $r->getStatusCode());
        self::assertSame('apagado', $this->obj($pai->id)->status);
        self::assertSame('apagado', $this->obj($filho->id)->status, 'voltou para a lixeira');
    }

    public function testRepositorioRecusaQuebrarARegraDaLixeiraDentroDaTransacao(): void
    {
        $o = $this->s->objetos;
        $pai = $this->evento('Pai');
        $vivo = $this->evento('Vivo', $pai);
        $lixo = $this->evento('Lixo', null, [], 'apagado');
        $dentroDoLixo = $this->evento('Dentro do lixo', $lixo, [], 'apagado');
        $casos = [
            'mandar para a lixeira com filho vivo' => fn () => $o->mandarParaLixeira($pai->id, $pai->versao),
            'criar dentro de pai apagado' => fn () => $o->criarDentro($this->site->id, $this->evento, $lixo->id, 'X', 'x-novo', []),
            'mover para destino apagado' => fn () => $o->mover($vivo->id, $lixo->id, 'mover', null, null, true),
            'tirar da lixeira com pai apagado' => fn () => $o->tirarDaLixeira($dentroDoLixo->id, $dentroDoLixo->versao),
        ];
        foreach ($casos as $nome => $acao) {
            try {
                $acao();
                self::fail($nome);
            } catch (ConflitoDeArvore) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('publicado', $this->obj($pai->id)->status);
        self::assertSame($pai->id, $this->obj($vivo->id)->paiId);
        self::assertSame('apagado', $this->obj($dentroDoLixo->id)->status);
        self::assertNull($o->porUrl($this->site->id, 'x-novo'));
        self::assertSame(1, $o->filhosForaDaLixeira($pai->id));

        $o->mandarParaLixeira($vivo->id, $vivo->versao);
        self::assertSame('apagado', $o->mandarParaLixeira($pai->id, $pai->versao)->status, 'sem filhos vivos, vai');
        self::assertSame('privado', $o->tirarDaLixeira($pai->id, $this->obj($pai->id)->versao)->status);
    }

    public function testRepositorioUrlDisponivelEClassesTodas(): void
    {
        $o = $this->s->objetos;
        self::assertFalse($o->urlDisponivel($this->site->id, 'sobre'));
        self::assertTrue($o->urlDisponivel($this->site->id, 'sobre', $this->sobre->id), 'o próprio dono pode manter');
        self::assertTrue($o->urlDisponivel($this->site->id, 'livre'));
        $o->atualizar($this->sobre->id, ['url_amigavel' => 'quem-somos']);
        self::assertFalse($o->urlDisponivel($this->site->id, 'sobre'), 'endereço antigo redireciona: não está livre');
        self::assertTrue($o->urlDisponivel($this->site->id, 'sobre', $this->sobre->id), 'mas o dono pode voltar a ele');
        self::assertSame(['arquivo', 'evento', 'formulario', 'imagem', 'link', 'mensagem_contato', 'noticia', 'pagina', 'pasta'], array_map(static fn (Classe $c): string => $c->nome, $this->s->classes->todas()));
    }
    public function testEditorDeTextoRicoSoCarregaEmTelaComCampoHtmlEOsScriptsNaoSaoInline(): void
    {
        $token = $this->token('admin');
        $base = '/_pbl/objeto/' . $this->sobre->id . '/novo';

        $comCampo = $this->req('GET', $base . '?classe=' . $this->evento->id, $token);
        $html = $this->html($comCampo);
        self::assertStringContainsString('data-pbl-editor="html" data-pbl-editor-base="' . Pagina::TINYMCE . '"', $html);
        self::assertStringContainsString('<script src="' . Pagina::TINYMCE_JS . '?v=', $html);
        self::assertStringContainsString('<script src="' . Pagina::EDITOR_JS . '?v=', $html);
        self::assertGreaterThan(strpos($html, Pagina::JS), strpos($html, Pagina::TINYMCE_JS), 'o editor vem depois do script geral');
        self::assertSame(0, preg_match('/<script(?![^>]*\ssrc=)/', $html), 'nenhum script inline');
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+=/i', $html);
        self::assertStringNotContainsString('//cdn', $html);
        self::assertStringContainsString('<textarea', $html, 'o textarea continua existindo (funciona sem JavaScript)');
        $csp = (string) $comCampo->headers->get('Content-Security-Policy');
        self::assertStringContainsString("script-src 'self';", $csp, 'scripts continuam só do próprio site');
        self::assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp, 'o editor aplica estilos em linha no conteúdo');
        self::assertStringContainsString("img-src 'self' data: https:", $csp);
        self::assertStringNotContainsString("script-src 'self' 'unsafe", $csp);

        // Telas sem campo html: nada do editor e a CSP estrita de antes.
        foreach ([$base, '/_pbl/objeto/' . $this->sobre->id, '/_pbl/objeto/' . $this->sobre->id . '/historico'] as $url) {
            $r = $this->req('GET', $url, $token);
            $semCampo = $this->html($r);
            self::assertStringNotContainsString('tinymce', $semCampo, $url);
            self::assertStringNotContainsString('pbl-editor', $semCampo, $url);
            self::assertStringNotContainsString('data-pbl-editor', $semCampo, $url);
            self::assertStringNotContainsString('unsafe-inline', (string) $r->headers->get('Content-Security-Policy'), $url);
        }
    }

    public function testHtmlColadoDoWordEhLimpoPeloServidorAoSalvar(): void
    {
        $token = $this->token('admin');
        $base = '/_pbl/objeto/' . $this->sobre->id . '/novo';
        $word = '<p class=MsoNormal style="mso-line-height-alt:12pt;text-align:center"><span style="mso-bidi-font-weight:normal">Aviso<o:p></o:p></span></p>'
            . '<!--[if gte mso 9]><xml><o:x/></xml><![endif]--><style>p{color:red}</style><script>alert(1)</script>';
        $r = $this->post($token, $base, $this->formEvento('Com Word', ['dados' => ['corpo' => $word]]));

        self::assertSame(303, $r->getStatusCode(), $this->html($r));
        self::assertSame('<p style="text-align: center">Aviso</p>', $this->obj($this->idCriado($r))->dados['corpo']);
    }
}
