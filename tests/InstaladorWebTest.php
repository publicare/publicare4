<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\Segredo;
use Pbl\Aplicacao;
use Pbl\Banco\Conexao;
use Pbl\Caminhos;
use Pbl\Instalacao\Instalador;
use Pbl\Instalacao\InstaladorWeb;
use Pbl\Instalacao\Requisitos;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Assistente de instalação pelo navegador: prova de posse, CSRF, atomicidade e fechamento depois de instalar. */
final class InstaladorWebTest extends TestCase
{
    private const SENHA = 'uma senha bem longa 2026';
    private string $raiz;
    private Caminhos $caminhos;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    /**
     * @param array<string, string> $campos
     * @param array<string, string> $cabecalhos
     */
    private function req(string $metodo, string $caminho = '/_pbl/instalar', array $campos = [], array $cabecalhos = []): Response
    {
        return (new Aplicacao($this->caminhos))->tratar(Request::create('https://novo.exemplo.org' . $caminho, $metodo, $campos, [], [], $cabecalhos));
    }

    private function darPosse(): void
    {
        $this->caminhos->garantirDados();
        touch($this->caminhos->dados() . '/' . InstaladorWeb::ARQUIVO_DE_POSSE);
    }

    private function token(): string
    {
        $this->caminhos->garantirDados();

        return (new Csrf(Segredo::obter($this->caminhos)))->paraEntrar();
    }

    /**
     * @param array<string, string> $sobrescrever
     * @return array<string, string>
     */
    private function formulario(array $sobrescrever = []): array
    {
        return $sobrescrever + [
            '_csrf' => $this->token(), 'site' => 'Meu Portal', 'dominio' => 'novo.exemplo.org', 'login' => 'admin',
            'nome' => 'Ana Admin', 'email' => 'ana@exemplo.org', 'senha' => self::SENHA, 'senha2' => self::SENHA,
        ];
    }

    public function testSemPosseMostraRequisitosEAsInstrucoesSemFormulario(): void
    {
        $r = $this->req('GET');
        $html = (string) $r->getContent();

        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('Requisitos do servidor', $html);
        self::assertStringContainsString(InstaladorWeb::ARQUIVO_DE_POSSE, $html);
        self::assertStringNotContainsString('<form', $html, 'sem a prova de posse não há formulário');
        self::assertStringContainsString("default-src 'none'", (string) $r->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
    }

    public function testComPosseMostraOFormularioComODominioDetectado(): void
    {
        $this->darPosse();

        $html = (string) $this->req('GET')->getContent();

        self::assertStringContainsString('<form', $html);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringContainsString('value="novo.exemplo.org"', $html);
        self::assertStringContainsString('Arquivo encontrado', $html);
    }

    public function testInstalarDeVerdadeCriaSiteAdministradorPaginasEFechaOInstalador(): void
    {
        $this->darPosse();

        $r = $this->req('POST', '/_pbl/instalar', $this->formulario(), ['HTTP_ORIGIN' => 'https://novo.exemplo.org']);

        self::assertSame(200, $r->getStatusCode(), (string) $r->getContent());
        self::assertStringContainsString('PBL4 instalado', (string) $r->getContent());
        self::assertTrue((new Instalador($this->caminhos))->instalado());
        self::assertFileDoesNotExist($this->caminhos->dados() . '/' . InstaladorWeb::ARQUIVO_DE_POSSE, 'a prova de posse é consumida');
        $s = Servicos::doBancoInstalado($this->caminhos);
        $admin = $s->usuarios->autenticar('admin', self::SENHA);
        self::assertTrue($admin?->administrador);
        self::assertSame('Meu Portal', $s->sites->porHost('novo.exemplo.org')?->nome);
        self::assertSame(200, $this->req('GET', '/')->getStatusCode(), 'o site já está publicado');
        self::assertSame(['usuario.criar'], array_column($s->auditoria->recentes(), 'acao'));

        // fechado: nem GET nem POST funcionam mais
        self::assertSame(404, $this->req('GET')->getStatusCode());
        $this->darPosse();
        self::assertSame(404, $this->req('POST', '/_pbl/instalar', $this->formulario())->getStatusCode(), 'não reinstala por cima');
        self::assertNotNull($s->usuarios->autenticar('admin', self::SENHA));
    }

    public function testSemPosseOuSemCsrfOuComOrigemErradaORequisicaoEhRecusadaENadaSeInstala(): void
    {
        $this->darPosse();
        $semToken = $this->req('POST', '/_pbl/instalar', array_diff_key($this->formulario(), ['_csrf' => 1]));
        $tokenFalso = $this->req('POST', '/_pbl/instalar', $this->formulario(['_csrf' => str_repeat('0', 64)]));
        $origem = $this->req('POST', '/_pbl/instalar', $this->formulario(), ['HTTP_ORIGIN' => 'https://evil.example']);
        unlink($this->caminhos->dados() . '/' . InstaladorWeb::ARQUIVO_DE_POSSE);
        $semPosse = $this->req('POST', '/_pbl/instalar', $this->formulario());

        foreach ([$semToken, $tokenFalso, $origem, $semPosse] as $r) {
            self::assertSame(403, $r->getStatusCode());
        }
        self::assertFalse((new Instalador($this->caminhos))->instalado());
        self::assertFileDoesNotExist($this->caminhos->banco());
    }

    public function testErrosMostramMensagemENaoDeixamNadaPelaMetadeEPermitemTentarDeNovo(): void
    {
        $this->darPosse();
        $casos = [
            ['senha2' => 'outra coisa bem longa 2026', 'As duas senhas não são iguais'],
            ['senha' => 'curta', 'senha2' => 'curta', 'pelo menos 10'],
            ['dominio' => 'Domínio Inválido!', 'Domínio inválido'],
            ['site' => '   ', 'nome do site'],
            ['login' => 'A', 'Login inválido'],
            ['email' => 'sem-arroba', 'E-mail inválido'],
        ];
        foreach ($casos as $caso) {
            $mensagem = array_pop($caso);
            $r = $this->req('POST', '/_pbl/instalar', $this->formulario($caso));

            self::assertSame(422, $r->getStatusCode(), $mensagem);
            self::assertStringContainsString($mensagem, (string) $r->getContent());
            self::assertFalse((new Instalador($this->caminhos))->instalado());
        }

        $certo = $this->req('POST', '/_pbl/instalar', $this->formulario());
        self::assertSame(200, $certo->getStatusCode(), 'depois dos erros, a instalação correta funciona');
        self::assertSame(8, (int) Conexao::sqlite($this->caminhos->banco())->fetchOne('SELECT COUNT(*) FROM classe'), 'nada duplicado pelas tentativas');
    }

    public function testValoresDigitadosVoltamEscapadosNoFormulario(): void
    {
        $this->darPosse();

        $r = $this->req('POST', '/_pbl/instalar', $this->formulario(['site' => '"><script>alert(1)</script>', 'senha2' => 'diferente']));
        $html = (string) $r->getContent();

        self::assertSame(422, $r->getStatusCode());
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
        self::assertStringNotContainsString(self::SENHA, $html, 'a senha nunca volta na página');
    }

    public function testRequisitosFalhandoImpedemOFormulario(): void
    {
        $this->darPosse();
        $somenteLeitura = $this->raiz . '/dados';
        chmod($somenteLeitura, 0555);
        try {
            if (is_writable($somenteLeitura)) {
                self::markTestSkipped('executando como root: não dá para tirar a permissão de escrita');
            }
            $r = $this->req('GET');

            self::assertStringContainsString('Corrija os itens marcados', (string) $r->getContent());
            self::assertStringNotContainsString('<form', (string) $r->getContent());
        } finally {
            chmod($somenteLeitura, 0775);
        }
    }

    public function testRequisitosListamPhpExtensoesEPastaGravavel(): void
    {
        $itens = (new Requisitos($this->caminhos))->verificar();
        $nomes = implode(' | ', array_column($itens, 'nome'));

        self::assertStringContainsString('PHP 8.2.0', $nomes);
        self::assertStringContainsString('pdo_sqlite', $nomes);
        self::assertStringContainsString('sodium', $nomes);
        self::assertStringContainsString('dados/', $nomes);
        self::assertTrue((new Requisitos($this->caminhos))->tudoCerto());
    }

    public function testOpenBasedirQueExcluiDadosExplicaSemVazarCaminhos(): void
    {
        $outra = sys_get_temp_dir() . '/pbl4-fora-' . bin2hex(random_bytes(4));
        $itens = (new Requisitos($this->caminhos, $outra))->verificar();
        $item = array_values(array_filter($itens, static fn (array $i): bool => str_contains($i['nome'], 'open_basedir')));

        self::assertCount(1, $item);
        self::assertFalse($item[0]['ok']);
        self::assertTrue($item[0]['obrigatorio']);
        self::assertStringContainsString('private/', $item[0]['detalhe']);
        $tudo = json_encode($itens, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString($this->raiz, $tudo, 'a mensagem não revela o caminho do projeto');
        self::assertStringNotContainsString($outra, $tudo, 'nem a lista do open_basedir');
        self::assertFalse((new Requisitos($this->caminhos, $outra))->tudoCerto());
    }

    public function testOpenBasedirQueIncluiOProjetoOuVazioNaoReclama(): void
    {
        foreach (['', $this->raiz, '/nao/existe' . PATH_SEPARATOR . $this->raiz . '/'] as $limite) {
            $nomes = implode('|', array_column((new Requisitos($this->caminhos, $limite))->verificar(), 'nome'));
            self::assertStringNotContainsString('open_basedir', $nomes, "limite: {$limite}");
        }
        self::assertTrue(Requisitos::dentroDoOpenBasedir($this->raiz . '/dados/ainda/nao/existe', $this->raiz));
        self::assertFalse(Requisitos::dentroDoOpenBasedir($this->raiz . '-irmao/dados', $this->raiz), 'prefixo de nome não conta');
    }

    public function testFaltaDeSqliteOrientaOPacoteDoServidor(): void
    {
        $r = new \ReflectionMethod(Requisitos::class, 'comoAtivar');
        $texto = (string) $r->invoke(new Requisitos($this->caminhos), 'pdo_sqlite');

        self::assertStringContainsString('sqlite3', $texto);
        self::assertStringContainsString('PHP-FPM', $texto);
        self::assertStringNotContainsString('sqlite3', (string) $r->invoke(new Requisitos($this->caminhos), 'mbstring'));
    }

    public function testOutrasRotasEmServidorNaoInstaladoContinuamIndisponiveis(): void
    {
        self::assertSame(503, $this->req('GET', '/')->getStatusCode());
        self::assertSame(503, $this->req('GET', '/_pbl/entrar')->getStatusCode(), 'só o instalador responde antes da instalação');
        self::assertSame(503, $this->req('GET', '/_pbl/instalar/outra')->getStatusCode());
    }

    public function testOCliCriaOAdministradorQuandoPedido(): void
    {
        $t = new \Symfony\Component\Console\Tester\CommandTester(new \Pbl\Console\InstalarComando($this->caminhos));
        $t->setInputs([self::SENHA, self::SENHA]);

        self::assertSame(0, $t->execute(['--nome' => 'CLI', '--host' => 'cli.exemplo.org', '--admin-login' => 'root', '--admin-email' => 'root@exemplo.org']));

        self::assertStringContainsString('/_pbl/entrar', $t->getDisplay());
        self::assertTrue(Servicos::doBancoInstalado($this->caminhos)->usuarios->autenticar('root', self::SENHA)?->administrador);
    }

    public function testAtrasDeProxyQueTerminaOHttpsOInstaladorAindaFunciona(): void
    {
        $this->darPosse();
        // O PHP recebe em http; o navegador (e o Origin) são https. Ainda não existe rede.json.
        $r = (new Aplicacao($this->caminhos))->tratar(Request::create('http://novo.exemplo.org/_pbl/instalar', 'POST', $this->formulario(), [], [], ['HTTP_ORIGIN' => 'https://novo.exemplo.org']));

        self::assertSame(200, $r->getStatusCode(), (string) $r->getContent());
        self::assertTrue((new Instalador($this->caminhos))->instalado());
    }

    public function testOrigemDeOutroHostContinuaRecusadaNoInstalador(): void
    {
        $this->darPosse();
        $r = (new Aplicacao($this->caminhos))->tratar(Request::create('http://novo.exemplo.org/_pbl/instalar', 'POST', $this->formulario(), [], [], ['HTTP_ORIGIN' => 'https://evil.example']));

        self::assertSame(403, $r->getStatusCode());
    }

    public function testAvisoDeConfiguracaoDoServidorNaoViraUmFalsoCertoENemBloqueia(): void
    {
        $itens = (new Requisitos($this->caminhos))->verificar();
        $aviso = array_values(array_filter($itens, static fn (array $i): bool => str_starts_with($i['nome'], 'Confira')));

        self::assertCount(1, $aviso);
        self::assertFalse($aviso[0]['ok'], 'não finge ter conferido');
        self::assertFalse($aviso[0]['obrigatorio']);
        self::assertTrue((new Requisitos($this->caminhos))->tudoCerto(), 'aviso não bloqueia a instalação');
        $this->darPosse();
        self::assertStringContainsString('⚠', (string) $this->req('GET')->getContent());
    }

    public function testPastaInternaSemPermissaoImpedeInstalarAntesDeFecharOInstalador(): void
    {
        $this->caminhos->garantirDados();
        $cache = $this->caminhos->cache();
        chmod($cache, 0555);
        try {
            if (is_writable($cache)) {
                self::markTestSkipped('executando como root: não dá para tirar a permissão de escrita');
            }
            try {
                (new Instalador($this->caminhos))->instalar('Site', 'novo.exemplo.org');
                self::fail('deveria recusar');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('não consegue gravar', $e->getMessage());
            }
            self::assertFalse((new Instalador($this->caminhos))->instalado());
        } finally {
            chmod($cache, 0775);
        }
        (new Instalador($this->caminhos))->instalar('Site', 'novo.exemplo.org');
        self::assertTrue((new Instalador($this->caminhos))->instalado(), 'corrigida a permissão, instala');
    }

    public function testFalhaAoPublicarDepoisDeInstalarViraAvisoESiteContinuaInstalado(): void
    {
        $this->darPosse();
        file_put_contents($this->caminhos->cache() . '/paginas', 'um arquivo onde deveria haver uma pasta');

        $r = $this->req('POST', '/_pbl/instalar', $this->formulario());

        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('foi instalado, mas não conseguiu publicar', (string) $r->getContent());
        self::assertTrue((new Instalador($this->caminhos))->instalado());
        self::assertNotNull(Servicos::doBancoInstalado($this->caminhos)->usuarios->autenticar('admin', self::SENHA), 'o administrador existe');
    }
}
