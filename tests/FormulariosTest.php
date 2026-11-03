<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\LimiteDeEnvios;
use Pbl\Acesso\Perfil;
use Pbl\Aplicacao;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migracoes\M011ClasseFormulario;
use Pbl\Caminhos;
use Pbl\Conteudo\Classe;
use Pbl\Conteudo\Objeto;
use Pbl\Formularios\EnvioDeFormulario;
use Pbl\Formularios\FormularioHtml;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Formulários públicos declarativos (fase 6a, ADR 0013): envio anônimo, defesas e tela de envios. */
final class FormulariosTest extends TestCase
{
    private const ORIGEM = ['HTTP_ORIGIN' => 'https://www.exemplo.org'];
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;
    private int $siteId;
    private int $inicioId;
    private int $destinoId;
    private int $formId;
    private Classe $inscricao;

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
        $this->inscricao = $this->s->classes->criar('inscricao', [
            ['nome' => 'nome', 'tipo' => 'texto', 'indexavel' => true, 'rotulo' => 'Nome completo', 'obrigatorio' => true],
            ['nome' => 'email', 'tipo' => 'texto', 'indexavel' => false],
            ['nome' => 'idade', 'tipo' => 'numero', 'indexavel' => false, 'rotulo' => 'Idade'],
            ['nome' => 'nascimento', 'tipo' => 'data', 'indexavel' => false, 'rotulo' => 'Nascimento'],
            ['nome' => 'aceite', 'tipo' => 'booleano', 'indexavel' => false, 'rotulo' => 'Aceito os termos', 'obrigatorio' => true],
            ['nome' => 'bio', 'tipo' => 'html', 'indexavel' => false, 'rotulo' => 'Sobre você'],
            ['nome' => 'interno', 'tipo' => 'texto', 'indexavel' => false],
        ]);
        $pagina = $this->s->classes->porNome('pagina');
        self::assertNotNull($pagina);
        $this->destinoId = $this->s->objetos->criar($this->siteId, $pagina, $this->inicioId, 'Inscrições', 'inscricoes', [])->id;
        $this->formId = $this->criarFormulario('inscreva-se', ['campos' => 'nome,email,idade,nascimento,aceite,bio']);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    /** @param array<string, string|int> $extra */
    private function criarFormulario(string $url, array $extra = [], string $status = 'publicado'): int
    {
        $tipo = $this->s->classes->porNome('formulario');
        self::assertNotNull($tipo);
        $dados = $extra + ['destino' => $this->destinoId, 'classe_envio' => 'inscricao', 'campos' => 'nome', 'mensagem' => 'Obrigado, <b>recebido</b>!'];

        return $this->s->objetos->criar($this->siteId, $tipo, $this->inicioId, 'Inscreva-se', $url, $dados, $status)->id;
    }

    private function token(?int $id = null): string
    {
        return $this->s->csrf()->paraFormulario($id ?? $this->formId);
    }

    /**
     * @param array<string, mixed> $campos
     * @param array<string, string> $cabecalhos
     */
    private function enviar(array $campos, bool $comToken = true, string $ip = '198.51.100.7', array $cabecalhos = self::ORIGEM, ?int $id = null): Response
    {
        $id ??= $this->formId;
        $campos = ($comToken ? ['_csrf' => $this->token($id)] : []) + $campos;
        $r = Request::create('https://www.exemplo.org/_pbl/enviar/' . $id, 'POST', $campos, [], [], ['REMOTE_ADDR' => $ip] + $cabecalhos);

        return (new Aplicacao($this->caminhos))->tratar($r);
    }

    private function pegar(string $caminho): Response
    {
        return (new Aplicacao($this->caminhos))->tratar(Request::create('https://www.exemplo.org' . $caminho, 'GET', [], [], [], ['REMOTE_ADDR' => '198.51.100.7']));
    }

    /** @return list<Objeto> */
    private function envios(): array
    {
        return array_values(array_filter($this->s->objetos->todosDaSubarvore($this->siteId, '/'), fn (Objeto $o): bool => $o->classeId === $this->inscricao->id));
    }

    /** @return array<string, string> */
    private function valido(): array
    {
        return ['nome' => 'Ana Souza', 'email' => 'ana@exemplo.org', 'idade' => '31', 'nascimento' => '1994-05-17', 'aceite' => '1', 'bio' => '<p>Oi</p>'];
    }

    public function testEnvioValidoViraObjetoPrivadoAnonimoEFazPrg(): void
    {
        $r = $this->enviar($this->valido());

        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/_pbl/enviado/' . $this->formId, $r->headers->get('Location'));
        $envios = $this->envios();
        self::assertCount(1, $envios);
        $e = $envios[0];
        self::assertSame('privado', $e->status);
        self::assertSame($this->destinoId, $e->paiId);
        self::assertNull($e->criadoPor);
        self::assertSame('Ana Souza', $e->dados['nome']);
        self::assertSame(31, $e->dados['idade']);
        self::assertSame(1, $e->dados['aceite']);
        self::assertSame('1994-05-17', $e->dados['nascimento']);
        // privado: nunca aparece para o público
        self::assertFalse($e->visivel(gmdate('c')));
        self::assertSame(404, $this->pegar('/' . $e->urlAmigavel)->getStatusCode());
    }

    public function testMensagemDeSucessoEscapadaEComCabecalhosDeSeguranca(): void
    {
        $r = $this->pegar('/_pbl/enviado/' . $this->formId);

        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('Obrigado, &lt;b&gt;recebido&lt;/b&gt;!', (string) $r->getContent());
        self::assertStringNotContainsString('<b>recebido', (string) $r->getContent());
        self::assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $r->headers->get('X-Frame-Options'));
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        self::assertStringContainsString("default-src 'none'", (string) $r->headers->get('Content-Security-Policy'));
    }

    public function testAuditoriaGuardaAnonimoFormularioEObjetoSemValores(): void
    {
        $this->enviar(['nome' => 'Segredo Pessoal Único', 'aceite' => '1', 'email' => 'x@y.z']);

        $achou = null;
        foreach ($this->s->auditoria->recentes(50) as $linha) {
            if ($linha['acao'] === 'formulario.envio') {
                $achou = $linha;
            }
        }
        self::assertNotNull($achou);
        self::assertSame('anonimo', $achou['ator']);
        self::assertSame($this->envios()[0]->id, $achou['objeto_id']);
        self::assertStringContainsString('"formulario":' . $this->formId, (string) $achou['detalhe']);
        foreach ($this->s->auditoria->recentes(100) as $linha) {
            self::assertStringNotContainsString('Segredo Pessoal', (string) $linha['detalhe']);
            self::assertStringNotContainsString('x@y.z', (string) $linha['detalhe']);
        }
    }

    public function testSemTokenOuComTokenErradoOuDeOutroFormularioERecusado(): void
    {
        $outro = $this->criarFormulario('outro', ['campos' => 'nome,aceite']);

        self::assertSame(403, $this->enviar($this->valido(), false)->getStatusCode());
        self::assertSame(403, $this->enviar(['_csrf' => 'errado'] + $this->valido(), false)->getStatusCode());
        self::assertSame(403, $this->enviar(['_csrf' => $this->token($outro)] + $this->valido(), false)->getStatusCode());
        self::assertSame(403, $this->enviar(['_csrf' => ['x']] + $this->valido(), false)->getStatusCode());
        self::assertSame([], $this->envios());
    }

    public function testOrigemDeOutroSiteERecusadaMesmoComToken(): void
    {
        $r = $this->enviar($this->valido(), true, '198.51.100.7', ['HTTP_ORIGIN' => 'https://malvado.example']);

        self::assertSame(403, $r->getStatusCode());
        self::assertSame([], $this->envios());
    }

    public function testCampoArmadilhaPreenchidoFingeSucessoENaoGrava(): void
    {
        $r = $this->enviar(['_mel' => 'http://spam.example'] + $this->valido());

        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/_pbl/enviado/' . $this->formId, $r->headers->get('Location'));
        self::assertSame([], $this->envios());
    }

    public function testLimiteDeEnviosPorIpEPorFormulario(): void
    {
        for ($i = 0; $i < LimiteDeEnvios::MAXIMO; $i++) {
            self::assertSame(303, $this->enviar($this->valido())->getStatusCode());
        }
        $bloqueado = $this->enviar($this->valido());
        self::assertSame(429, $bloqueado->getStatusCode());
        self::assertGreaterThan(0, (int) $bloqueado->headers->get('Retry-After'));
        self::assertCount(LimiteDeEnvios::MAXIMO, $this->envios());

        self::assertSame(303, $this->enviar($this->valido(), true, '203.0.113.9')->getStatusCode(), 'outro IP não é afetado');
        $outro = $this->criarFormulario('outro', ['campos' => 'nome,aceite']);
        self::assertSame(303, $this->enviar($this->valido(), true, '198.51.100.7', self::ORIGEM, $outro)->getStatusCode(), 'outro formulário não é afetado');
    }

    public function testLimiteContaTentativasQueFalhamEOIpNaoFicaEmClaroNoBanco(): void
    {
        for ($i = 0; $i < LimiteDeEnvios::MAXIMO; $i++) {
            $this->enviar([], false);
        }

        self::assertSame(429, $this->enviar($this->valido())->getStatusCode());
        $con = Conexao::sqlite($this->caminhos->banco());
        foreach ($con->fetchFirstColumn('SELECT chave_hash FROM tentativa_login') as $chave) {
            self::assertStringNotContainsString('198.51.100.7', (string) $chave);
        }
    }

    public function testLimiteLiberaDepoisDaJanela(): void
    {
        $agora = 1_000_000;
        $limite = new LimiteDeEnvios(Conexao::sqlite($this->caminhos->banco()), 'segredo', function () use (&$agora): int {
            return $agora;
        });
        for ($i = 0; $i < LimiteDeEnvios::MAXIMO; $i++) {
            self::assertNull($limite->bloqueadoPor(5, '1.1.1.1'));
            $limite->registrar(5, '1.1.1.1');
        }
        self::assertNotNull($limite->bloqueadoPor(5, '1.1.1.1'));
        self::assertNull($limite->bloqueadoPor(6, '1.1.1.1'));
        $agora += LimiteDeEnvios::JANELA + 1;
        self::assertNull($limite->bloqueadoPor(5, '1.1.1.1'));
    }

    public function testObrigatoriosFaltandoDao422ComRotulosEPreservamOQueFoiDigitado(): void
    {
        $r = $this->enviar(['nome' => '', 'email' => '"><script>alert(1)</script>', 'idade' => '31']);
        $corpo = (string) $r->getContent();

        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('Preencha o campo «Nome completo»', $corpo);
        self::assertStringContainsString('Marque «Aceito os termos»', $corpo);
        self::assertStringContainsString('value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $corpo, 'o valor digitado volta escapado');
        self::assertStringNotContainsString('<script>alert(1)', $corpo);
        self::assertStringContainsString('name="_csrf"', $corpo);
        self::assertSame([], $this->envios());
    }

    public function testTiposInvalidosSaoRecusadosSemExporDetalhesTecnicos(): void
    {
        $r = $this->enviar(['idade' => 'abc', 'nascimento' => '2026-02-31'] + $this->valido());
        $corpo = (string) $r->getContent();

        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('«Idade» precisa ser um número', $corpo);
        self::assertSame([], $this->envios());

        $r = $this->enviar(['nascimento' => '2026-02-31'] + $this->valido());
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('«Nascimento» não é válido', (string) $r->getContent());
        self::assertStringNotContainsString('Propriedade', (string) $r->getContent());
        self::assertSame([], $this->envios());
    }

    public function testNumeroComVirgulaEDecimalSaoAceitosENotacaoCientificaNao(): void
    {
        self::assertSame(303, $this->enviar(['idade' => '31,5'] + $this->valido())->getStatusCode());
        self::assertSame(31.5, $this->envios()[0]->dados['idade']);
        self::assertSame(422, $this->enviar(['idade' => '1e9'] + $this->valido())->getStatusCode());
        self::assertSame(422, $this->enviar(['idade' => '0x1A'] + $this->valido())->getStatusCode());
    }

    public function testCamposDesconhecidosOuDeSistemaSaoIgnorados(): void
    {
        $r = $this->enviar($this->valido() + [
            'interno' => 'não deveria entrar', 'status' => 'publicado', 'titulo' => 'Hackeado', 'pai' => '1',
            'classe_id' => '1', 'url_amigavel' => 'minha-url', 'criado_por' => '1', 'peso' => '99', 'inexistente' => 'x',
        ]);

        self::assertSame(303, $r->getStatusCode());
        $e = $this->envios()[0];
        self::assertSame('privado', $e->status);
        self::assertArrayNotHasKey('interno', $e->dados);
        self::assertArrayNotHasKey('inexistente', $e->dados);
        self::assertNotSame('Hackeado', $e->titulo);
        self::assertStringStartsWith('envio-', $e->urlAmigavel);
        self::assertSame($this->destinoId, $e->paiId);
        self::assertNull($e->criadoPor);
        self::assertSame(0, $e->peso);
    }

    public function testValorEmListaEContadoComoVazio(): void
    {
        $r = $this->enviar(['nome' => ['a', 'b']] + $this->valido());

        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('Preencha o campo «Nome completo»', (string) $r->getContent());
        self::assertSame([], $this->envios());
    }

    public function testHtmlDoEnvioEhSanitizadoEOTextoNuncaViraMarcacaoNaTelaDeErro(): void
    {
        $sujo = '<p onclick="x()">oi</p><script>alert(1)</script><img src="x" onerror="y()"><a href="javascript:alert(2)">a</a><iframe src="//e"></iframe>';
        self::assertSame(303, $this->enviar(['bio' => $sujo] + $this->valido())->getStatusCode());
        $bio = (string) $this->envios()[0]->dados['bio'];

        self::assertStringContainsString('oi', $bio);
        foreach (['<script', 'onclick', 'onerror', 'javascript:', '<iframe'] as $proibido) {
            self::assertStringNotContainsString($proibido, $bio);
        }

        $r = $this->enviar(['nome' => '', 'bio' => '</textarea><script>alert(3)</script>'] + $this->valido());
        self::assertStringNotContainsString('<script>alert(3)', (string) $r->getContent());
        self::assertStringContainsString('&lt;/textarea&gt;&lt;script&gt;', (string) $r->getContent());
    }

    public function testTextoComControlesInvalidosOuMuitoLongoERecusado(): void
    {
        self::assertSame(422, $this->enviar(['nome' => str_repeat('a', 2001)] + $this->valido())->getStatusCode());
        self::assertSame(422, $this->enviar(['nome' => "ab\xC3\x28cd"] + $this->valido())->getStatusCode(), 'UTF-8 inválido');
        self::assertSame(303, $this->enviar(['nome' => "A\x00na\x07"] + $this->valido())->getStatusCode());
        self::assertSame('Ana', $this->envios()[0]->dados['nome']);
    }

    public function testCorpoGrandeDemaisDa413ESemGravar(): void
    {
        $r = $this->enviar($this->valido() + ['lixo' => str_repeat('x', EnvioDeFormulario::MAX_CORPO + 10)]);
        self::assertSame(413, $r->getStatusCode());

        $r = $this->enviar($this->valido(), true, '203.0.113.50', self::ORIGEM + ['CONTENT_LENGTH' => (string) (EnvioDeFormulario::MAX_CORPO + 1)]);
        self::assertSame(413, $r->getStatusCode());
        self::assertSame([], $this->envios());
    }

    public function testFormularioInexistenteForaDoArFechadoOuMalConfiguradoDa404IgualParaTodos(): void
    {
        $privado = $this->criarFormulario('privado', ['campos' => 'nome,aceite'], 'privado');
        $fechado = $this->criarFormulario('fechado', ['campos' => 'nome,aceite', 'fechado' => 1]);
        $campoRuim = $this->criarFormulario('ruim1', ['campos' => 'nome,aceite,naoexiste']);
        $faltaObrigatorio = $this->criarFormulario('ruim2', ['campos' => 'nome']);
        $classeRuim = $this->criarFormulario('ruim3', ['classe_envio' => 'nao_existe', 'campos' => 'nome']);
        $comoFormulario = $this->criarFormulario('ruim4', ['classe_envio' => 'formulario', 'campos' => 'campos']);

        $referencia = $this->s->classes->criar('com_ref', [['nome' => 'alvo', 'tipo' => 'ref', 'indexavel' => true]]);
        $this->s->classes->criar('so_aceita_x', [], ['filhas' => ['pagina']]);
        $comRef = $this->criarFormulario('ruim5', ['classe_envio' => $referencia->nome, 'campos' => 'alvo']);

        $corpos = [];
        foreach ([9999, $privado, $fechado, $campoRuim, $faltaObrigatorio, $classeRuim, $comoFormulario, $comRef, $this->inicioId] as $id) {
            $r = $this->enviar($this->valido(), true, '203.0.113.' . random_int(1, 250), self::ORIGEM, $id);
            self::assertSame(404, $r->getStatusCode(), "formulário {$id}");
            $corpos[] = (string) $r->getContent();
            self::assertSame(404, $this->pegar('/_pbl/enviado/' . $id)->getStatusCode());
        }
        self::assertCount(1, array_unique($corpos), 'a resposta não diferencia o motivo');
        self::assertSame([], $this->envios());
    }

    public function testFormularioDeOutroSiteNaoRecebeEnvio(): void
    {
        $outro = $this->s->sites->criar('Outro', 'outro.exemplo.org');
        $tipo = $this->s->classes->porNome('formulario');
        self::assertNotNull($tipo);
        $pagina = $this->s->classes->porNome('pagina');
        self::assertNotNull($pagina);
        $raiz = $this->s->objetos->criar($outro->id, $pagina, null, 'Raiz', '', []);
        $destino = $this->s->objetos->criar($outro->id, $pagina, $raiz->id, 'Dest', 'dest', []);
        $f = $this->s->objetos->criar($outro->id, $tipo, $raiz->id, 'F', 'f', ['destino' => $destino->id, 'classe_envio' => 'inscricao', 'campos' => 'nome,aceite']);

        // no domínio do primeiro site, o formulário do segundo não existe
        self::assertSame(404, $this->enviar($this->valido(), true, '198.51.100.7', self::ORIGEM, $f->id)->getStatusCode());
    }

    public function testMetodosErradosEPathsMalformados(): void
    {
        self::assertSame(405, $this->pegar('/_pbl/enviar/' . $this->formId)->getStatusCode());
        $post = (new Aplicacao($this->caminhos))->tratar(Request::create('https://www.exemplo.org/_pbl/enviado/' . $this->formId, 'POST'));
        self::assertSame(405, $post->getStatusCode());
        // ids que não são número comum nunca chegam ao formulário (caem na rota padrão da administração)
        foreach (['/_pbl/enviar/0', '/_pbl/enviar/01', '/_pbl/enviar/abc', '/_pbl/enviar/1/2', '/_pbl/enviar/99999999999'] as $caminho) {
            $r = (new Aplicacao($this->caminhos))->tratar(Request::create('https://www.exemplo.org' . $caminho, 'POST'));
            self::assertSame(404, $r->getStatusCode(), $caminho);
        }
    }

    public function testEnviosNaoPassamPelaLixeiraDoDestino(): void
    {
        $this->s->objetos->mandarParaLixeira($this->destinoId, 1);
        // com o destino na lixeira o formulário deixa de existir
        self::assertSame(404, $this->enviar($this->valido())->getStatusCode());
        self::assertSame([], $this->envios());
    }

    public function testComandoFormularioNoMoldeGeraFormularioComTokenEArmadilha(): void
    {
        $site = $this->s->sites->porId($this->siteId);
        $f = $this->s->objetos->porId($this->formId);
        self::assertNotNull($site);
        self::assertNotNull($f);
        $arquivo = $this->s->publicador->publicar($site, $f);
        $html = (string) file_get_contents($arquivo);

        self::assertStringContainsString('<form method="post" action="/_pbl/enviar/' . $this->formId . '"', $html);
        self::assertStringContainsString('name="_csrf" value="' . $this->token() . '"', $html);
        self::assertStringContainsString('name="_mel"', $html);
        self::assertStringContainsString('name="nome" value="" maxlength="2000" required', $html);
        self::assertStringContainsString('type="checkbox"', $html);
        self::assertStringContainsString('<textarea', $html);
        self::assertStringNotContainsString('name="interno"', $html);
        self::assertStringNotContainsString('<script>', $html, 'sem script inline (só o arquivo da barra de edição, com src)');
        // o formulário publicado funciona de ponta a ponta
        self::assertSame(303, $this->enviar($this->valido())->getStatusCode());
    }

    public function testComandoFormularioDeOutroIdOuFechadoNaoImprimeNada(): void
    {
        $this->s->objetos->atualizar($this->formId, ['dados' => ['fechado' => 1]]);
        $site = $this->s->sites->porId($this->siteId);
        $f = $this->s->objetos->porId($this->formId);
        self::assertNotNull($site);
        self::assertNotNull($f);
        $html = (string) file_get_contents($this->s->publicador->publicar($site, $f));

        self::assertStringNotContainsString('action="/_pbl/enviar/', $html);
    }

    public function testRotulosENomesNoHtmlDoFormularioSaoEscapados(): void
    {
        $perigosa = $this->s->classes->criar('perigosa', [['nome' => 'x', 'tipo' => 'texto', 'indexavel' => false, 'rotulo' => '"><img src=x onerror=alert(1)>']]);
        $id = $this->criarFormulario('perigo', ['classe_envio' => $perigosa->nome, 'campos' => 'x']);
        $site = $this->s->sites->porId($this->siteId);
        $f = $this->s->objetos->porId($id);
        self::assertNotNull($site);
        self::assertNotNull($f);
        $html = (string) file_get_contents($this->s->publicador->publicar($site, $f));

        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&quot;&gt;&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function testClasseFormularioExisteAposMigracaoENaoSobrescreveExistente(): void
    {
        $tipo = $this->s->classes->porNome('formulario');
        self::assertNotNull($tipo);
        self::assertFalse($tipo->aceitaFilhos);

        $con = Conexao::memoria();
        $con->executeStatement('CREATE TABLE classe (id INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, propriedades TEXT, descricao TEXT NULL, aceita_filhos INTEGER, filhas TEXT NULL, versao INTEGER)');
        $con->insert('classe', ['nome' => 'formulario', 'propriedades' => '[]', 'aceita_filhos' => 1, 'versao' => 1]);
        (new M011ClasseFormulario())->aplicar($con);
        (new M011ClasseFormulario())->aplicar($con);
        self::assertSame(1, (int) $con->fetchOne('SELECT COUNT(*) FROM classe'));
        self::assertSame('[]', $con->fetchOne('SELECT propriedades FROM classe'));
    }

    public function testCsrfDoFormularioEPorFormularioESemMisturarComOutrosTokens(): void
    {
        $csrf = $this->s->csrf();

        self::assertTrue($csrf->conferirFormulario(5, $csrf->paraFormulario(5)));
        self::assertFalse($csrf->conferirFormulario(6, $csrf->paraFormulario(5)));
        self::assertFalse($csrf->conferirFormulario(5, $csrf->paraEntrar()));
        self::assertFalse($csrf->conferirEntrar($csrf->paraFormulario(5)));
        self::assertFalse($csrf->conferirFormulario(5, ''));
    }

    public function testHtmlDoFormularioMostraCampoObrigatorioEEscapaTudo(): void
    {
        $f = $this->s->formularios->porId($this->siteId, $this->formId);
        self::assertNotNull($f);
        $html = FormularioHtml::renderizar($f, 'tok"en', ['nome' => '<x>'], ['Erro <b>']);

        self::assertStringContainsString('value="tok&quot;en"', $html);
        self::assertStringContainsString('value="&lt;x&gt;"', $html);
        self::assertStringContainsString('<li>Erro &lt;b&gt;</li>', $html);
        self::assertStringContainsString('Nome completo <span aria-hidden="true">*</span>', $html);
    }

    // ---------------------------------------------------------------- administração

    private function sessao(string $login, bool $admin, ?Perfil $perfil = null): string
    {
        $u = $this->s->usuarios->criar($login, $login . '@exemplo.org', ucfirst($login), 'uma senha bem longa 2026', $admin);
        if ($perfil !== null) {
            $this->s->usuarios->conceder($u->id, $this->inicioId, $perfil);
        }

        return $this->s->sessoes()->criar($u->id);
    }

    private function admin(string $caminho, string $token): Response
    {
        return (new Aplicacao($this->caminhos))->tratar(Request::create('https://www.exemplo.org' . $caminho, 'GET', [], ['__Host-pbl4' => $token], [], ['REMOTE_ADDR' => '198.51.100.7']));
    }

    public function testTelaDeEnviosListaOsEnviosEMostraOLinkNoFormulario(): void
    {
        $this->enviar($this->valido());
        $this->enviar(['nome' => 'Bruno <b>Dois</b>', 'aceite' => '1']);
        $token = $this->sessao('admin', true);

        $r = $this->admin('/_pbl/objeto/' . $this->formId . '/envios', $token);
        $corpo = (string) $r->getContent();
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('2 envio(s)', $corpo);
        self::assertStringContainsString('privado', $corpo);
        self::assertStringContainsString('/estado">Publicar ou recusar', $corpo);
        self::assertStringNotContainsString('Bruno', $corpo, 'a lista não despeja os valores; cada envio abre na tela de conteúdo');

        $tela = (string) $this->admin('/_pbl/objeto/' . $this->formId, $token)->getContent();
        self::assertStringContainsString('/envios">Envios recebidos', $tela);
        $semLink = (string) $this->admin('/_pbl/objeto/' . $this->destinoId, $token)->getContent();
        self::assertStringNotContainsString('Envios recebidos</a>', $semLink);
    }

    public function testTelaDeEnviosExigeSessaoPermissaoEUmFormulario(): void
    {
        $this->enviar($this->valido());

        self::assertSame(303, $this->admin('/_pbl/objeto/' . $this->formId . '/envios', 'sem-sessao')->getStatusCode());
        $leitor = $this->sessao('leitor', false, Perfil::Leitor);
        self::assertSame(200, $this->admin('/_pbl/objeto/' . $this->formId . '/envios', $leitor)->getStatusCode());
        $semPerfil = $this->sessao('semperfil', false);
        self::assertSame(403, $this->admin('/_pbl/objeto/' . $this->formId . '/envios', $semPerfil)->getStatusCode());
        $admin = $this->sessao('admin', true);
        self::assertSame(403, $this->admin('/_pbl/objeto/' . $this->destinoId . '/envios', $admin)->getStatusCode(), 'objeto que não é formulário');
        self::assertSame(403, $this->admin('/_pbl/objeto/9999/envios', $admin)->getStatusCode());
    }

    public function testAprovarEnvioEPublicaloPelaTelaDeEstadoJaExistente(): void
    {
        $this->enviar($this->valido());
        $e = $this->envios()[0];
        $this->s->objetos->atualizar($e->id, ['status' => 'publicado'], 'aprovado');

        $site = $this->s->sites->porId($this->siteId);
        self::assertNotNull($site);
        $this->s->publicador->publicarSite($site);
        self::assertSame(200, $this->pegar('/' . $e->urlAmigavel)->getStatusCode());
    }
}
