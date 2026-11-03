<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Perfil;
use Pbl\Acesso\SessaoRepositorio;
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

/** Telas de objetos na fase 5g: mover navegando pela árvore, estado agendado, HTML limpo e mensagens das regras de integridade. */
final class AdminIntegridadeTest extends TestCase
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
        $this->s->classes->criar('agenda', [], ['filhas' => ['evento']]);
        $n = $this->s->classes->porNome('noticia') ?? self::fail();
        $this->s->classes->atualizar($n->id, $n->versao, array_map(
            static fn (array $p): array => $p['nome'] === 'resumo' ? $p + ['obrigatorio' => true] : $p,
            $n->propriedades,
        ), ['filhas' => $n->filhas]);
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

    /** Id do objeto criado, tirado do `Location` do 303. */
    private function idCriado(Response $r): int
    {
        if (preg_match('#/objeto/(\d+)#', (string) $r->headers->get('Location'), $m) !== 1) {
            self::fail('não criou: ' . $this->html($r));
        }

        return (int) $m[1];
    }

    private function pastaDeEventos(string $titulo, ?Objeto $pai = null): Objeto
    {
        $agenda = $this->s->classes->porNome('agenda') ?? self::fail();

        return $this->s->objetos->criar($this->site->id, $agenda, ($pai ?? $this->sobre)->id, $titulo, 'agenda-' . bin2hex(random_bytes(3)), []);
    }

    public function testMoverNavegaPelaArvoreSemDigitarNumero(): void
    {
        $a = $this->evento('Pasta A');
        $filho = $this->evento('Filho de A', $a);
        $neto = $this->evento('Neto', $filho);
        $b = $this->evento('Pasta B');
        $bfilho = $this->evento('Dentro de B', $b);
        $token = $this->token('editor');
        $base = '/_pbl/objeto/' . $filho->id . '/mover';

        $tela = $this->html($this->req('GET', $base, $token));
        self::assertStringNotContainsString('name="destino" type="number"', $tela, 'não se digita número');
        self::assertStringNotContainsString('type="number"', $tela);
        self::assertStringContainsString('Onde você está: Pasta A', $tela, 'começa no pai atual');
        self::assertStringContainsString('href="' . $base . '?em=' . $this->sobre->id . '"', $tela, 'link para subir');
        self::assertStringNotContainsString('>Neto<', $tela, 'a própria subárvore não é oferecida');
        self::assertStringContainsString('É onde já está.', $tela);
        self::assertSame(0, preg_match('/<script(?![^>]*\ssrc=)/', $tela), 'nenhum script inline');

        // Subindo até Sobre: aparecem as duas pastas e os botões.
        $tela = $this->html($this->req('GET', $base . '?em=' . $this->sobre->id, $token));
        self::assertStringContainsString('Onde você está: Sobre', $tela);
        self::assertStringContainsString('href="' . $base . '?em=' . $b->id . '"', $tela);
        self::assertStringContainsString('name="destino" value="' . $b->id . '"', $tela);
        self::assertStringContainsString('name="_csrf"', $tela);
        self::assertStringContainsString('name="versao" value="' . $filho->versao . '"', $tela);

        // Entrando em B: dá para mover para dentro dele, e o filho dele aparece para navegar.
        $tela = $this->html($this->req('GET', $base . '?em=' . $b->id, $token));
        self::assertStringContainsString('Mover para dentro de &quot;Pasta B&quot;', $tela);
        self::assertStringContainsString('Dentro de B', $tela);

        // O botão é um POST comum com o destino escolhido.
        $r = $this->post($token, $base, ['versao' => (string) $filho->versao, 'destino' => (string) $b->id]);
        self::assertSame('/_pbl/objeto/' . $filho->id . '?salvo=movido', $r->headers->get('Location'));
        self::assertSame($b->id, $this->obj($filho->id)->paiId);
        self::assertSame($this->obj($filho->id)->caminho . $neto->id . '/', $this->obj($neto->id)->caminho);
        self::assertSame($b->id, $this->obj($bfilho->id)->paiId);
    }

    public function testMoverTelaTrataLugaresInvalidosComoPaiAtualENaoRevelaNada(): void
    {
        $a = $this->evento('Pasta A');
        $filho = $this->evento('Filho de A', $a);
        $neto = $this->evento('Neto', $filho);
        $lixo = $this->evento('Lixo');
        $this->s->objetos->atualizar($lixo->id, ['status' => 'apagado']);
        $token = $this->token('editor');
        $base = '/_pbl/objeto/' . $filho->id . '/mover';
        foreach (['em=999999', 'em=abc', 'em=-1', 'em=' . $neto->id, 'em=' . $filho->id, 'em=' . $lixo->id, 'em=' . $this->inicio->id, 'em[]=1', 'em='] as $consulta) {
            $r = $this->req('GET', $base . '?' . $consulta, $token);
            self::assertSame(200, $r->getStatusCode(), $consulta);
            self::assertStringContainsString('Onde você está: Pasta A', $this->html($r), $consulta);
        }
        // O editor só tem papel em Sobre: nunca vê a página inicial do site na navegação.
        $tela = $this->html($this->req('GET', $base . '?em=' . $this->sobre->id, $token));
        self::assertStringNotContainsString('Meu Site', $tela);
        self::assertStringNotContainsString('>Lixo<', $tela, 'a lixeira não é destino');
    }

    public function testMoverTelaExplicaPorQueNaoPodeEscolherUmLugar(): void
    {
        $pasta = $this->pastaDeEventos('Só eventos');
        $pagina = $this->s->objetos->criar($this->site->id, $this->pagina, $this->sobre->id, 'Uma página', 'uma-pagina-x', []);
        $token = $this->token('editor');
        $tela = $this->html($this->req('GET', '/_pbl/objeto/' . $pagina->id . '/mover?em=' . $this->sobre->id, $token));
        self::assertStringContainsString('href="/_pbl/objeto/' . $pagina->id . '/mover?em=' . $pasta->id . '"', $tela, 'dá para entrar');
        self::assertStringContainsString('O tipo deste lugar não aceita conteúdo do tipo pagina.', $tela);
        self::assertStringNotContainsString('name="destino" value="' . $pasta->id . '"', $tela, 'sem botão para o tipo recusado');
        // Forjar o POST também é recusado.
        $r = $this->post($token, '/_pbl/objeto/' . $pagina->id . '/mover', ['versao' => (string) $pagina->versao, 'destino' => (string) $pasta->id]);
        self::assertSame(422, $r->getStatusCode());
        self::assertSame($this->sobre->id, $this->obj($pagina->id)->paiId);
    }

    public function testMoverPermissaoCsrfEOrigemContinuamEmToda(): void
    {
        $filho = $this->evento('Filho');
        $b = $this->evento('B');
        self::assertSame(303, $this->req('GET', '/_pbl/objeto/' . $filho->id . '/mover')->getStatusCode(), 'sem sessão');
        self::assertSame(403, $this->req('GET', '/_pbl/objeto/' . $filho->id . '/mover', $this->token('leitor'))->getStatusCode());
        $token = $this->token('editor');
        $campos = ['versao' => (string) $filho->versao, 'destino' => (string) $b->id];
        self::assertSame(403, $this->req('POST', '/_pbl/objeto/' . $filho->id . '/mover', $token, $campos, self::ORIGEM)->getStatusCode(), 'sem CSRF');
        self::assertSame(403, $this->post($token, '/_pbl/objeto/' . $filho->id . '/mover', $campos, ['HTTP_ORIGIN' => 'https://evil.example'])->getStatusCode(), 'origem errada');
        self::assertSame($this->sobre->id, $this->obj($filho->id)->paiId);
    }

    public function testMoverTelaEscapaTitulosHostis(): void
    {
        $hostil = '"><script>alert(1)</script>';
        $this->evento($hostil);
        $filho = $this->evento('Filho');
        $tela = $this->html($this->req('GET', '/_pbl/objeto/' . $filho->id . '/mover?em=' . $this->sobre->id, $this->token('editor')));
        self::assertStringContainsString('&lt;script&gt;', $tela);
        self::assertSame(0, preg_match('/<script(?![^>]*\ssrc=)/', $tela), 'nenhum script inline');
    }

    public function testObjetoAgendadoApareceComoAgendadoNaTelaENaLista(): void
    {
        $quando = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+3 days');
        $e = $this->evento('Futuro', null, [], 'publicado', []);
        $e = $this->s->objetos->atualizar($e->id, ['data_publicacao' => $quando->format('c')]);
        $token = $this->token('admin');
        $tela = $this->html($this->req('GET', '/_pbl/objeto/' . $e->id, $token));
        self::assertStringContainsString('publicado, agendado para ' . $quando->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i'), $tela);
        self::assertStringContainsString('fora do ar até lá', $tela);
        $lista = $this->html($this->req('GET', '/_pbl/objeto/' . $this->sobre->id, $token));
        self::assertStringContainsString('agendado para', $lista);
        $estado = $this->html($this->req('GET', '/_pbl/objeto/' . $e->id . '/estado', $token));
        self::assertStringContainsString('agendado para', $estado);
        self::assertStringContainsString('bin/pbl agendados', $estado);

        $vencido = $this->evento('Vencido');
        $vencido = $this->s->objetos->atualizar($vencido->id, ['data_validade' => (new \DateTimeImmutable('-1 day', new \DateTimeZone('UTC')))->format('c')]);
        self::assertStringContainsString('publicado, vencido em', $this->html($this->req('GET', '/_pbl/objeto/' . $vencido->id, $token)));
        $normal = $this->evento('Normal');
        self::assertStringContainsString('publicado (visível ao público)', $this->html($this->req('GET', '/_pbl/objeto/' . $normal->id, $token)));
    }

    public function testSalvarEstadoComDataFuturaAvisaDoAgendamento(): void
    {
        $e = $this->evento('Agendar', null, [], 'privado');
        $token = $this->token('admin');
        $futuro = (new \DateTimeImmutable('+2 days', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d\TH:i');
        $r = $this->post($token, '/_pbl/objeto/' . $e->id . '/estado', ['versao' => (string) $e->versao, 'estado' => 'publicado', 'data_publicacao' => $futuro, 'data_validade' => '']);
        self::assertSame('/_pbl/objeto/' . $e->id . '/previa?salvo=agendado', $r->headers->get('Location'));
        $aviso = $this->html($this->req('GET', (string) $r->headers->get('Location'), $token));
        self::assertStringContainsString('bin/pbl agendados', $aviso);
    }

    public function testHtmlEhLimpoPeloFormularioEMostradoLimpoNoErroENaEdicao(): void
    {
        $e = $this->evento('Com HTML');
        $token = $this->token('editor');
        $sujo = '<p onclick="x()">Oi <b>você</b></p><script>alert(1)</script><a href="javascript:alert(2)">l</a><img src=x onerror=alert(3)>';
        $r = $this->post($token, '/_pbl/objeto/' . $e->id . '/editar', ['versao' => (string) $e->versao, 'titulo' => 'Com HTML', 'descricao' => '', 'url' => $e->urlAmigavel, 'peso' => '0', 'dados' => ['corpo' => $sujo]]);
        self::assertSame(303, $r->getStatusCode());
        $guardado = (string) $this->obj($e->id)->dados['corpo'];
        self::assertSame('<p>Oi <b>você</b></p>l<img src="x">', $guardado);

        // Salvar de novo o que já está limpo não cria versão nova.
        $versao = $this->obj($e->id)->versao;
        $r = $this->post($token, '/_pbl/objeto/' . $e->id . '/editar', ['versao' => (string) $versao, 'titulo' => 'Com HTML', 'descricao' => '', 'url' => $e->urlAmigavel, 'peso' => '0', 'dados' => ['corpo' => $guardado]]);
        self::assertSame('/_pbl/objeto/' . $e->id . '?salvo=nada', $r->headers->get('Location'));
        // Mesmo o texto sujo de novo (que limpa para o mesmo valor) não cria versão.
        $r = $this->post($token, '/_pbl/objeto/' . $e->id . '/editar', ['versao' => (string) $versao, 'titulo' => 'Com HTML', 'descricao' => '', 'url' => $e->urlAmigavel, 'peso' => '0', 'dados' => ['corpo' => $sujo]]);
        self::assertSame('/_pbl/objeto/' . $e->id . '?salvo=nada', $r->headers->get('Location'));
        self::assertSame($versao, $this->obj($e->id)->versao);

        // Só scripts: nada sobra, e a tela explica (sem gravar).
        $r = $this->post($token, '/_pbl/objeto/' . $e->id . '/editar', ['versao' => (string) $versao, 'titulo' => 'Com HTML', 'descricao' => '', 'url' => $e->urlAmigavel, 'peso' => '0', 'dados' => ['corpo' => '<script>alert(1)</script>']]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('Nada deste HTML é permitido', $this->html($r));
        self::assertStringNotContainsString('<script>', $this->html($r));
        self::assertSame($guardado, $this->obj($e->id)->dados['corpo']);
        self::assertStringContainsString('o HTML é limpo', $this->html($this->req('GET', '/_pbl/objeto/' . $e->id . '/editar', $token)));
    }

    public function testHtmlTambemELimpoAoCriarPelaTela(): void
    {
        $token = $this->token('editor');
        $r = $this->post($token, '/_pbl/objeto/' . $this->sobre->id . '/novo', $this->formEvento('Novo com HTML', ['dados' => ['corpo' => '<iframe src="https://evil.example"></iframe><em onmouseover="x()">ok</em>']]));
        $id = $this->idCriado($r);
        self::assertSame('<em>ok</em>', $this->obj($id)->dados['corpo']);
    }

    public function testCampoObrigatorioETipoNaoAceitoMostramMensagemClara(): void
    {
        $noticia = $this->s->classes->porNome('noticia') ?? self::fail();
        $token = $this->token('editor');
        $r = $this->post($token, '/_pbl/objeto/' . $this->sobre->id . '/novo', ['classe' => (string) $noticia->id, 'titulo' => 'Sem resumo', 'descricao' => '', 'url' => '', 'peso' => '0', 'dados' => ['resumo' => '']]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('Este campo é obrigatório.', $this->html($r));

        $pasta = $this->pastaDeEventos('Agenda');
        $r = $this->post($token, '/_pbl/objeto/' . $pasta->id . '/novo', ['classe' => (string) $noticia->id, 'titulo' => 'Notícia', 'descricao' => '', 'url' => '', 'peso' => '0', 'dados' => ['resumo' => 'x']]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('não aceita conteúdo do tipo noticia', $this->html($r));
        self::assertCount(0, $this->s->objetos->todosDaSubarvore($this->site->id, $pasta->caminho) === [] ? [] : array_filter($this->s->objetos->todosDaSubarvore($this->site->id, $pasta->caminho), static fn (Objeto $o): bool => $o->titulo === 'Notícia'));
    }

    public function testRepositorioRecusaOQueATelaNaoViu(): void
    {
        // Se a classe mudar entre abrir e salvar (ou o pedido for forjado), o repositório é a última barreira e a tela mostra a mensagem.
        $noticia = $this->s->classes->porNome('noticia') ?? self::fail();
        $n = $this->s->objetos->criar($this->site->id, $noticia, $this->sobre->id, 'N', 'n-x', ['resumo' => 'x']);
        $token = $this->token('editor');
        $viaCli = null;
        try {
            $this->s->objetos->atualizar($n->id, ['dados' => ['resumo' => '']]);
        } catch (\Pbl\Conteudo\ViolacaoDeRegra $e) {
            $viaCli = $e->getMessage();
        }
        self::assertSame('O campo Resumo é obrigatório.', $viaCli);
        self::assertSame(200, $this->req('GET', '/_pbl/objeto/' . $n->id . '/editar', $token)->getStatusCode());
        self::assertSame('x', $this->obj($n->id)->dados['resumo']);
    }
}
