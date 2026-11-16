<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\Segredo;
use Pbl\Aplicacao;
use Pbl\Caminhos;
use Pbl\Instalacao\InstaladorWeb;
use Pbl\Instalacao\PassosDoInstalador;
use Pbl\Instalacao\ProximosPassos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Etapa 2: o assistente web em passos (barra de progresso, acessibilidade, erros que ensinam e tela final com os próximos passos). */
final class InstaladorPassosTest extends TestCase
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

    /** @param array<string, string> $campos */
    private function req(string $metodo, array $campos = [], string $docroot = ''): Response
    {
        $servidor = ['HTTP_ORIGIN' => 'https://novo.exemplo.org'] + ($docroot === '' ? [] : ['DOCUMENT_ROOT' => $docroot]);

        return (new Aplicacao($this->caminhos))->tratar(Request::create('https://novo.exemplo.org/_pbl/instalar', $metodo, $campos, [], [], $servidor));
    }

    private function darPosse(): void
    {
        $this->caminhos->garantirDados();
        touch($this->caminhos->dados() . '/' . InstaladorWeb::ARQUIVO_DE_POSSE);
    }

    /**
     * @param array<string, string> $sobrescrever
     * @return array<string, string>
     */
    private function formulario(array $sobrescrever = []): array
    {
        $this->caminhos->garantirDados();

        return $sobrescrever + ['_csrf' => (new Csrf(Segredo::obter($this->caminhos)))->paraEntrar(), 'site' => 'Meu Portal', 'dominio' => 'novo.exemplo.org', 'login' => 'admin',
            'nome' => 'Ana Admin', 'email' => 'ana@exemplo.org', 'senha' => self::SENHA, 'senha2' => self::SENHA];
    }

    public function testFormularioTemOsSeisPassosComBarraDeProgressoAcessivel(): void
    {
        $this->darPosse();
        $html = (string) $this->req('GET')->getContent();

        foreach (['Boas-vindas e requisitos', 'Site: nome e domínio', 'Administrador', 'Modelos opcionais', 'Revisão e instalar', 'Pronto'] as $titulo) {
            self::assertStringContainsString($titulo, $html);
        }
        self::assertStringContainsString('<progress class="pbl-progresso" max="6" value="1"', $html);
        self::assertStringContainsString('aria-current="step"', $html);
        self::assertStringContainsString('aria-label="Etapas da instalação"', $html);
        self::assertSame(5, substr_count($html, '<section class="pbl-painel"'), 'cinco painéis dentro do formulário (o sexto é a tela final)');
        self::assertStringContainsString('aria-live="polite"', $html, 'a força da senha é anunciada');
        self::assertStringContainsString('tabindex="-1"', $html, 'o título do passo recebe o foco');
        self::assertStringContainsString('autocomplete="new-password"', $html);
        self::assertStringContainsString('<noscript>', $html);
    }

    public function testSemJavascriptTodosOsPassosAparecemEOsBotoesDoJsFicamEscondidos(): void
    {
        $this->darPosse();
        $html = (string) $this->req('GET')->getContent();

        self::assertDoesNotMatchRegularExpression('/<section class="pbl-painel"[^>]*\shidden/', $html, 'sem JS nenhum painel nasce escondido');
        self::assertSame(4, substr_count($html, 'class="pbl-nav-js'));
        self::assertSame(4, preg_match_all('/class="pbl-nav-js[^"]*" hidden/', $html), 'Voltar/Continuar só existem para quem tem JS');
        self::assertStringContainsString('type="submit" class="btn btn-success btn-lg">Instalar', $html);
    }

    public function testNaoHaScriptNemEstiloEmLinhaESoOScriptDoProprioSite(): void
    {
        $this->darPosse();
        $r = $this->req('GET');
        $html = (string) $r->getContent();

        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)/i', $html);
        self::assertDoesNotMatchRegularExpression('/\sstyle="/i', $html, 'a CSP do painel não aceita estilo em linha');
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+="/i', $html);
        self::assertStringContainsString('/_pbl/assets/pbl-instalar.js', $html);
        self::assertStringContainsString("script-src 'self'", (string) $r->headers->get('Content-Security-Policy'));
        $js = (string) file_get_contents(__DIR__ . '/../public/_pbl/assets/pbl-instalar.js');
        self::assertStringNotContainsString('innerHTML', $js, 'o que foi digitado nunca vira HTML');
        self::assertStringNotContainsString('eval(', $js);
        self::assertStringNotContainsString('.style.', $js);
    }

    public function testPaginasSemFormularioNaoCarregamOJsDoAssistente(): void
    {
        self::assertStringNotContainsString('pbl-instalar.js', (string) $this->req('GET')->getContent());
    }

    public function testErroLevaAoPassoCertoEExplicaComoCorrigir(): void
    {
        $this->darPosse();
        $casos = [
            [['dominio' => 'https://x.org/'], 'Domínio inválido', 'Escreva só o endereço', '2'],
            [['senha2' => 'outra frase muito longa 2026'], 'não são iguais', 'mesma senha', '3'],
            [['senha' => 'curta', 'senha2' => 'curta'], 'pelo menos 10', 'frase longa', '3'],
            [['login' => 'A'], 'Login inválido', 'minúsculas', '3'],
            [['email' => 'sem-arroba'], 'E-mail inválido', 'e-mail completo', '3'],
            [['site' => '  '], 'nome do site', 'topo do site', '2'],
        ];
        foreach ($casos as [$campos, $mensagem, $dica, $passo]) {
            $r = $this->req('POST', $this->formulario($campos));
            $html = (string) $r->getContent();

            self::assertSame(422, $r->getStatusCode(), $mensagem);
            self::assertStringContainsString($mensagem, $html);
            self::assertStringContainsString($dica, $html, "como corrigir: {$mensagem}");
            self::assertStringContainsString('data-passo-inicial="' . $passo . '"', $html, "volta ao passo {$passo}");
            self::assertStringContainsString('role="alert"', $html);
            self::assertStringContainsString('aria-describedby="erro-formulario"', $html);
            self::assertStringContainsString('value="' . $passo . '"', $html, 'a barra de progresso aponta para o passo do erro');
            self::assertStringNotContainsString(self::SENHA, $html);
        }
    }

    public function testTelaFinalMostraCronBackupAtualizacaoELogin(): void
    {
        $this->darPosse();

        $r = $this->req('POST', $this->formulario());
        $html = (string) $r->getContent();

        self::assertSame(200, $r->getStatusCode(), $html);
        self::assertStringContainsString('<progress class="pbl-progresso" max="6" value="6"', $html);
        self::assertStringContainsString('* * * * * php ' . $this->raiz . '/bin/pbl cron &gt;/dev/null 2&gt;&amp;1', $html);
        self::assertStringContainsString('dados/backups/', $html);
        self::assertStringContainsString('php bin/pbl atualizar', $html);
        self::assertStringContainsString('href="/_pbl/entrar"', $html);
        self::assertStringContainsString('<strong>admin</strong>', $html);
        self::assertStringContainsString('conta-senha admin', $html);
        self::assertStringNotContainsString(self::SENHA, $html);
    }

    public function testTelaFinalNaoAfirmaQuePublicEARaizQuandoNaoEh(): void
    {
        $c = new Caminhos($this->raiz);

        $raiz = ProximosPassos::html($c, 'ana', '', $this->raiz, true);
        $publica = ProximosPassos::html($c, 'ana', '', $this->raiz . '/public', true);
        $desconhecida = ProximosPassos::html($c, 'ana', '', null, false);

        self::assertStringContainsString('aponte a raiz do site para a pasta <code>public/</code>', $raiz);
        self::assertStringContainsString('só no Apache', $raiz);
        self::assertStringNotContainsString('já é a pasta', $raiz);
        self::assertStringContainsString('A raiz do site já é a pasta <code>public/</code>', $publica);
        self::assertStringNotContainsString('só no Apache', $publica);
        self::assertStringContainsString('Confirme que só a pasta', $desconhecida);
        self::assertStringContainsString('HTTPS', $desconhecida);
        self::assertStringNotContainsString('<strong>HTTPS</strong>', $raiz, 'em HTTPS não repete o aviso');
        self::assertStringContainsString('ana&lt;', ProximosPassos::html($c, 'ana<', '', null, true));
    }

    public function testMapaDeErrosEBarra(): void
    {
        self::assertSame(2, PassosDoInstalador::passoDoErro('Domínio inválido: x'));
        self::assertSame(2, PassosDoInstalador::passoDoErro('O nome do site não pode ser vazio'));
        self::assertSame(3, PassosDoInstalador::passoDoErro('A senha é longa demais'));
        self::assertSame(3, PassosDoInstalador::passoDoErro('E-mail inválido'));
        self::assertSame(4, PassosDoInstalador::passoDoErro('Modelo desconhecido: x'));
        self::assertSame(5, PassosDoInstalador::passoDoErro('algo inesperado'));
        self::assertStringContainsString('Nada foi instalado pela metade', PassosDoInstalador::comoCorrigir('algo inesperado'));
        $barra = PassosDoInstalador::barra(3);
        self::assertSame(1, substr_count($barra, 'aria-current="step"'));
        self::assertSame(2, substr_count($barra, '(concluído)'));
        self::assertStringContainsString('Passo <span data-pbl-passo-numero>3</span> de 6', $barra);
    }

    public function testPaginaDeNaoInstaladoApontaOInstalarPhp(): void
    {
        $html = (string) (new Aplicacao($this->caminhos))->tratar(Request::create('https://novo.exemplo.org/'))->getContent();

        self::assertStringContainsString('instalar.php', $html);
        self::assertStringContainsString('bin/pbl instalar', $html);
    }
}
