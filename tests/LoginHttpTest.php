<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Aplicacao;
use Pbl\Banco\Conexao;
use Pbl\Caminhos;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Entrar, sair e a página inicial da administração, de ponta a ponta (ADR 0011). */
final class LoginHttpTest extends TestCase
{
    private const SENHA = 'uma senha bem longa 2026';
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Site', 'www.exemplo.org');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $this->s->usuarios->criar('ana', 'ana@exemplo.org', 'Ana <b>Souza</b>', self::SENHA);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    /**
     * @param array<string, string> $campos
     * @param array<string, string> $cookies
     * @param array<string, string> $cabecalhos
     */
    private function req(string $metodo, string $caminho, array $campos = [], array $cookies = [], array $cabecalhos = [], bool $https = true, string $ip = '198.51.100.7'): Response
    {
        $servidor = ['REMOTE_ADDR' => $ip] + $cabecalhos;
        $r = Request::create(($https ? 'https' : 'http') . '://www.exemplo.org' . $caminho, $metodo, $campos, $cookies, [], $servidor);

        return (new Aplicacao($this->caminhos))->tratar($r);
    }

    private function entrar(string $login = 'ana', string $senha = self::SENHA, bool $https = true, string $ip = '198.51.100.7'): Response
    {
        return $this->req('POST', '/_pbl/entrar', ['login' => $login, 'senha' => $senha, '_csrf' => $this->s->csrf()->paraEntrar()], [], [], $https, $ip);
    }

    private function cookieDe(Response $r, string $nome): ?Cookie
    {
        foreach ($r->headers->getCookies() as $c) {
            if ($c->getName() === $nome) {
                return $c;
            }
        }

        return null;
    }

    public function testFormularioDeEntrarTemCsrfEscudosEHeadersDeSeguranca(): void
    {
        $r = $this->req('GET', '/_pbl/entrar');

        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('name="_csrf"', (string) $r->getContent());
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        self::assertSame('DENY', $r->headers->get('X-Frame-Options'));
        self::assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        self::assertSame('same-origin', $r->headers->get('Referrer-Policy'));
        self::assertStringContainsString("default-src 'none'", (string) $r->headers->get('Content-Security-Policy'));
        self::assertStringContainsString("frame-ancestors 'none'", (string) $r->headers->get('Content-Security-Policy'));
        self::assertNotNull($r->headers->get('Strict-Transport-Security'));
        self::assertNull($this->req('GET', '/_pbl/entrar', [], [], [], false)->headers->get('Strict-Transport-Security'), 'HSTS só em HTTPS');
    }

    public function testSemSessaoAAdministracaoMandaParaEntrar(): void
    {
        $r = $this->req('GET', '/_pbl/');

        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/_pbl/entrar', $r->headers->get('Location'));
    }

    public function testEntrarComSucessoCriaSessaoComCookieSeguro(): void
    {
        $r = $this->entrar();

        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/_pbl/', $r->headers->get('Location'));
        $c = $this->cookieDe($r, '__Host-pbl4');
        self::assertNotNull($c);
        self::assertTrue($c->isHttpOnly());
        self::assertTrue($c->isSecure());
        self::assertSame('lax', $c->getSameSite());
        self::assertSame('/', $c->getPath());
        self::assertNull($c->getDomain(), 'o prefixo __Host- proíbe Domain');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $c->getValue());
        self::assertSame(0, (int) Conexao::sqlite($this->caminhos->banco())->fetchOne('SELECT COUNT(*) FROM sessao WHERE id_hash = ?', [$c->getValue()]), 'o token não está no banco');
    }

    public function testEmHttpPuroOCookieNaoTemPrefixoNemSecure(): void
    {
        $r = $this->entrar('ana', self::SENHA, false);
        $c = $this->cookieDe($r, 'pbl4');

        self::assertNotNull($c);
        self::assertFalse($c->isSecure());
        self::assertTrue($c->isHttpOnly());
        self::assertNull($this->cookieDe($r, '__Host-pbl4'));
    }

    public function testComASessaoAPaginaInicialMostraONomeEscapado(): void
    {
        $token = (string) $this->cookieDe($this->entrar(), '__Host-pbl4')?->getValue();

        $r = $this->req('GET', '/_pbl/', [], ['__Host-pbl4' => $token]);

        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('Olá, <strong>Ana &lt;b&gt;Souza&lt;/b&gt;</strong>', (string) $r->getContent());
        self::assertStringNotContainsString('<b>Souza</b>', (string) $r->getContent());
        self::assertStringContainsString('name="_csrf"', (string) $r->getContent());
    }

    public function testLoginInexistenteESenhaErradaRespondemIgual(): void
    {
        $errada = $this->entrar('ana', 'senha completamente errada');
        $inexistente = $this->entrar('ninguem', 'senha completamente errada', true, '198.51.100.8');

        self::assertSame(401, $errada->getStatusCode());
        self::assertSame(401, $inexistente->getStatusCode());
        self::assertSame($errada->getContent(), $inexistente->getContent(), 'mesmo corpo: não revela se o login existe');
        self::assertStringContainsString('Login ou senha inválidos', (string) $errada->getContent());
        self::assertCount(0, $errada->headers->getCookies());
    }

    public function testSemTokenCsrfOuComTokenFalsoOPedidoEhRecusado(): void
    {
        $sem = $this->req('POST', '/_pbl/entrar', ['login' => 'ana', 'senha' => self::SENHA]);
        $falso = $this->req('POST', '/_pbl/entrar', ['login' => 'ana', 'senha' => self::SENHA, '_csrf' => str_repeat('0', 64)]);

        self::assertSame(403, $sem->getStatusCode());
        self::assertSame(403, $falso->getStatusCode());
        self::assertCount(0, $sem->headers->getCookies());
    }

    public function testOrigemDeOutroSiteERecusadaMesmoComTokenValido(): void
    {
        $r = $this->req('POST', '/_pbl/entrar', ['login' => 'ana', 'senha' => self::SENHA, '_csrf' => $this->s->csrf()->paraEntrar()], [], ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertSame(403, $r->getStatusCode());
        self::assertCount(0, $r->headers->getCookies());
        $certa = $this->req('POST', '/_pbl/entrar', ['login' => 'ana', 'senha' => self::SENHA, '_csrf' => $this->s->csrf()->paraEntrar()], [], ['HTTP_ORIGIN' => 'https://www.exemplo.org']);
        self::assertSame(303, $certa->getStatusCode());
    }

    public function testCincoFalhasBloqueiamMesmoComASenhaCertaERetryAfter(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(401, $this->entrar('ana', "errada numero {$i} !!", true, "198.51.100.{$i}")->getStatusCode());
        }

        $bloqueado = $this->entrar('ana', self::SENHA, true, '198.51.100.99');

        self::assertSame(429, $bloqueado->getStatusCode());
        self::assertGreaterThan(0, (int) $bloqueado->headers->get('Retry-After'));
        self::assertCount(0, $bloqueado->headers->getCookies());

        // passada a janela, volta a funcionar
        Conexao::sqlite($this->caminhos->banco())->executeStatement('UPDATE tentativa_login SET quando = quando - 1000');
        self::assertSame(303, $this->entrar('ana', self::SENHA, true, '198.51.100.99')->getStatusCode());
    }

    public function testBloqueioNaoSeContornaDescartandoOCookie(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->entrar('ana', "errada numero {$i} !!");
        }

        // o PBL3 guardava o contador na sessão; aqui cada tentativa chega sem cookie nenhum e continua bloqueada
        self::assertSame(429, $this->req('POST', '/_pbl/entrar', ['login' => 'ana', 'senha' => self::SENHA, '_csrf' => $this->s->csrf()->paraEntrar()])->getStatusCode());
    }

    public function testLoginNovoGeraTokenNovoEEncerraASessaoAntigaQueVeioNoCookie(): void
    {
        $antigo = (string) $this->cookieDe($this->entrar(), '__Host-pbl4')?->getValue();

        $r = $this->req('POST', '/_pbl/entrar', ['login' => 'ana', 'senha' => self::SENHA, '_csrf' => $this->s->csrf()->paraEntrar()], ['__Host-pbl4' => $antigo]);
        $novo = (string) $this->cookieDe($r, '__Host-pbl4')?->getValue();

        self::assertNotSame($antigo, $novo);
        self::assertSame(303, $this->req('GET', '/_pbl/', [], ['__Host-pbl4' => $antigo])->getStatusCode(), 'a sessão antiga não vale mais (volta para entrar)');
        self::assertSame(200, $this->req('GET', '/_pbl/', [], ['__Host-pbl4' => $novo])->getStatusCode());
    }

    public function testTokenFixadoPeloAtacanteNaoViraSessao(): void
    {
        $fixado = str_repeat('a', 64);
        $r = $this->req('GET', '/_pbl/', [], ['__Host-pbl4' => $fixado]);

        self::assertSame(303, $r->getStatusCode());
        $login = $this->req('POST', '/_pbl/entrar', ['login' => 'ana', 'senha' => self::SENHA, '_csrf' => $this->s->csrf()->paraEntrar()], ['__Host-pbl4' => $fixado]);
        self::assertNotSame($fixado, $this->cookieDe($login, '__Host-pbl4')?->getValue());
        self::assertSame(303, $this->req('GET', '/_pbl/', [], ['__Host-pbl4' => $fixado])->getStatusCode(), 'o token escolhido por outra pessoa continua sem valer');
    }

    public function testSairExigeCsrfEApagaASessao(): void
    {
        $token = (string) $this->cookieDe($this->entrar(), '__Host-pbl4')?->getValue();
        $hash = hash('sha256', $token);
        $csrf = $this->s->csrf()->daSessao($hash);

        $sem = $this->req('POST', '/_pbl/sair', [], ['__Host-pbl4' => $token]);
        self::assertSame(403, $sem->getStatusCode());
        self::assertSame(200, $this->req('GET', '/_pbl/', [], ['__Host-pbl4' => $token])->getStatusCode(), 'sem CSRF a sessão segue ativa');

        $outroOrigem = $this->req('POST', '/_pbl/sair', ['_csrf' => $csrf], ['__Host-pbl4' => $token], ['HTTP_ORIGIN' => 'https://evil.example']);
        self::assertSame(403, $outroOrigem->getStatusCode());

        $ok = $this->req('POST', '/_pbl/sair', ['_csrf' => $csrf], ['__Host-pbl4' => $token]);
        self::assertSame(303, $ok->getStatusCode());
        self::assertSame('/_pbl/entrar', $ok->headers->get('Location'));
        self::assertSame(303, $this->req('GET', '/_pbl/', [], ['__Host-pbl4' => $token])->getStatusCode(), 'depois de sair, não entra mais');
        self::assertSame(0, (int) Conexao::sqlite($this->caminhos->banco())->fetchOne('SELECT COUNT(*) FROM sessao'));
    }

    public function testSairPorGetNaoExisteECsrfDeUmaSessaoNaoServeParaOutra(): void
    {
        $this->s->usuarios->criar('bia', 'bia@exemplo.org', 'Bia', self::SENHA);
        $tokenAna = (string) $this->cookieDe($this->entrar(), '__Host-pbl4')?->getValue();
        $tokenBia = (string) $this->cookieDe($this->entrar('bia', self::SENHA, true, '198.51.100.20'), '__Host-pbl4')?->getValue();

        self::assertSame(404, $this->req('GET', '/_pbl/sair', [], ['__Host-pbl4' => $tokenAna])->getStatusCode(), 'sair por GET (link ou imagem) não é possível');
        $csrfDaBia = $this->s->csrf()->daSessao(hash('sha256', $tokenBia));
        self::assertSame(403, $this->req('POST', '/_pbl/sair', ['_csrf' => $csrfDaBia], ['__Host-pbl4' => $tokenAna])->getStatusCode());
    }

    public function testUsuarioDesativadoOuComSenhaTrocadaPerdeAAdministracaoNaHora(): void
    {
        $token = (string) $this->cookieDe($this->entrar(), '__Host-pbl4')?->getValue();
        $ana = $this->s->usuarios->porLogin('ana');
        self::assertNotNull($ana);

        $this->s->usuarios->definirSenha($ana->id, 'outra senha igualmente longa');

        self::assertSame(303, $this->req('GET', '/_pbl/', [], ['__Host-pbl4' => $token])->getStatusCode());
        self::assertSame(401, $this->entrar('ana', self::SENHA, true, '198.51.100.40')->getStatusCode(), 'a senha antiga não serve');
    }

    public function testRotasDesconhecidasDaAdministracaoDao404ComOsMesmosCabecalhos(): void
    {
        $r = $this->req('GET', '/_pbl/qualquer-coisa');

        self::assertSame(404, $r->getStatusCode());
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        self::assertSame(404, $this->req('PUT', '/_pbl/entrar')->getStatusCode());
        self::assertSame(404, $this->req('GET', '/_pblx/entrar')->getStatusCode(), 'só o prefixo exato é da administração');
    }

    public function testDominioQueRedirecionaMandaParaOPrincipalAntesDeQualquerLogin(): void
    {
        $this->s->dominios->adicionar($this->s->sites->todos()[0]->id, 'antigo.exemplo.org', \Pbl\Conteudo\Dominio::REDIRECIONAR);

        $r = (new Aplicacao($this->caminhos))->tratar(Request::create('https://antigo.exemplo.org/_pbl/entrar'));

        self::assertSame(301, $r->getStatusCode());
        self::assertSame('https://www.exemplo.org/_pbl/entrar', $r->headers->get('Location'));
    }

    public function testAuditoriaRegistraEntradasFalhasESaidasSemSenhaLoginDigitadoNemIp(): void
    {
        $this->entrar('fantasma', 'senha completamente errada');
        $token = (string) $this->cookieDe($this->entrar(), '__Host-pbl4')?->getValue();
        $this->req('POST', '/_pbl/sair', ['_csrf' => $this->s->csrf()->daSessao(hash('sha256', $token))], ['__Host-pbl4' => $token]);

        $acoes = array_column($this->s->auditoria->recentes(), 'acao');
        self::assertSame(['logout', 'login.ok', 'login.falha', 'usuario.criar'], $acoes);
        $tudo = json_encode(Conexao::sqlite($this->caminhos->banco())->fetchAllAssociative('SELECT * FROM auditoria'), JSON_THROW_ON_ERROR);
        foreach (['fantasma', 'senha completamente errada', self::SENHA, '198.51.100.7'] as $proibido) {
            self::assertStringNotContainsString($proibido, $tudo);
        }
    }

    public function testPaginaPublicaNaoDependeDoSegredoNemDasSessoes(): void
    {
        $this->s->publicador->publicarSite($this->s->sites->todos()[0]);
        @unlink($this->caminhos->config() . '/segredo');

        $r = $this->req('GET', '/sobre');

        self::assertSame(200, $r->getStatusCode());
        self::assertFileDoesNotExist($this->caminhos->config() . '/segredo', 'servir página pública não toca no segredo');
    }

    public function testSegredoNasceNaInstalacaoCom0600ESeRecriaSeFaltar(): void
    {
        $arquivo = $this->caminhos->config() . '/segredo';

        self::assertFileExists($arquivo);
        self::assertSame('0600', substr(sprintf('%o', fileperms($arquivo)), -4));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', trim((string) file_get_contents($arquivo)));
        $antes = trim((string) file_get_contents($arquivo));
        self::assertSame($antes, Servicos::doBancoInstalado($this->caminhos)->segredo(), 'estável entre execuções');

        unlink($arquivo);
        $novo = Servicos::doBancoInstalado($this->caminhos)->segredo();
        self::assertNotSame($antes, $novo);
        self::assertFileExists($arquivo);
    }
}
