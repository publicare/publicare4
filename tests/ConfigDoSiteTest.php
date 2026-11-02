<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Doctrine\DBAL\Connection;
use Pbl\Acesso\Perfil;
use Pbl\Acesso\SessaoRepositorio;
use Pbl\Aplicacao;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migracoes\M017ConfigDoSite;
use Pbl\Caminhos;
use Pbl\Conteudo\ConfigDoSite;
use Pbl\Conteudo\ConflitoDeConfig;
use Pbl\Conteudo\ErroDeConfig;
use Pbl\Conversao\Conversor;
use Pbl\Instalacao\Instalador;
use Pbl\Linguagem\ErroSintaxe;
use Pbl\Linguagem\ExprMacro;
use Pbl\Linguagem\ExpressaoParser;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Configurações do site (ADR 0017, fase 5m): catálogo, repositório, moldes, publicação, tela do admin e conversor. */
final class ConfigDoSiteTest extends TestCase
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
        $this->ids['admin'] = $this->s->usuarios->criar('admin', 'admin@exemplo.org', 'Admin', self::SENHA, true)->id;
        $this->ids['editor'] = $this->s->usuarios->criar('editor', 'editor@exemplo.org', 'Editor', self::SENHA)->id;
        $this->s->usuarios->conceder($this->ids['editor'], (int) $site->objetoRaizId, Perfil::Editor);
        $this->s->publicador->publicarSite($site);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function token(string $login): string
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
    private function post(string $token, array $campos, bool $comCsrf = true): Response
    {
        $csrf = $this->s->csrf()->daSessao(SessaoRepositorio::hash($token));

        return $this->req('POST', $this->url(), $token, ($comCsrf ? ['_csrf' => $csrf] : []) + $campos, self::ORIGEM);
    }

    private function url(): string
    {
        return '/_pbl/site/' . $this->siteId . '/configuracoes';
    }

    private function versao(): string
    {
        return $this->s->configs->versao($this->siteId);
    }

    private function pagina(string $url): string
    {
        return (string) (new Aplicacao($this->caminhos))->tratar(Request::create("http://www.exemplo.org{$url}"))->getContent();
    }

    private function conexao(): Connection
    {
        return Conexao::sqlite($this->caminhos->banco());
    }

    public function testCatalogoValidaPorTipoERejeitaDesconhecida(): void
    {
        self::assertNull(ConfigDoSite::normalizar('slogan', '   '));
        self::assertSame('pt-BR', ConfigDoSite::normalizar('idioma', 'pt-BR'));
        self::assertTrue(ConfigDoSite::normalizar('busca_ligada', '1'));
        self::assertFalse(ConfigDoSite::normalizar('busca_ligada', '0'));
        self::assertSame(20, ConfigDoSite::normalizar('itens_por_pagina', '20'));
        self::assertSame('G-ABC123', ConfigDoSite::normalizar('analytics', 'G-ABC123'));
        self::assertSame(['https://a.example/x', 'http://b.example'], ConfigDoSite::normalizar('redes_sociais', "https://a.example/x\n\nhttp://b.example"));
        foreach ([['idioma', 'xx_yy'], ['itens_por_pagina', '0'], ['itens_por_pagina', '51'], ['menu_profundidade', '6'], ['analytics', '<script>alert(1)</script>'],
            ['email_contato', 'nao-e-email'], ['telefone', 'abc'], ['redes_sociais', 'javascript:alert(1)'], ['redes_sociais', 'ftp://x.example'], ['busca_ligada', 'talvez'], ['slogan', "a\x00b"]] as [$chave, $valor]) {
            try {
                ConfigDoSite::normalizar($chave, $valor);
                self::fail("{$chave} aceitou {$valor}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        ConfigDoSite::normalizar('segredo', 'x');
    }

    public function testRodapeSaiLimpoPeloHtmlSeguro(): void
    {
        $limpo = ConfigDoSite::normalizar('rodape', '<p onclick="x()">Oi</p><script>alert(1)</script>');
        self::assertIsString($limpo);
        self::assertStringNotContainsString('script', $limpo);
        self::assertStringNotContainsString('onclick', $limpo);
    }

    public function testRepositorioGravaVersionaERejeitaSemGravarNada(): void
    {
        $repo = $this->s->configs;
        self::assertSame('Meu Site', $repo->valor($this->siteId, 'nome'), 'o instalador grava o nome');
        self::assertSame('pt-BR', $repo->valor($this->siteId, 'idioma'));
        self::assertTrue($repo->valor($this->siteId, 'busca_ligada'));

        $v0 = $this->versao();
        self::assertSame(['slogan'], $repo->definir($this->siteId, ['slogan' => 'Meu lema', 'nome' => 'Meu Site'], $v0));
        self::assertNotSame($v0, $this->versao());
        try {
            $repo->definir($this->siteId, ['slogan' => 'Outro'], $v0);
            self::fail('versão velha deveria conflitar');
        } catch (ConflitoDeConfig) {
            self::assertSame('Meu lema', $repo->valor($this->siteId, 'slogan'));
        }
        try {
            $repo->definir($this->siteId, ['slogan' => 'Novo', 'itens_por_pagina' => '99', 'chave_nova' => 'x']);
            self::fail('deveria recusar');
        } catch (ErroDeConfig $e) {
            self::assertArrayHasKey('itens_por_pagina', $e->erros);
            self::assertArrayHasKey('chave_nova', $e->erros);
            self::assertSame('Meu lema', $repo->valor($this->siteId, 'slogan'), 'nada é gravado quando algo é recusado');
        }
        $repo->definir($this->siteId, ['slogan' => '']);
        self::assertSame('', $repo->valor($this->siteId, 'slogan'), 'vazio volta ao padrão');
    }

    public function testArquivoPrecisaSerImagemDoMesmoSite(): void
    {
        $this->expectException(ErroDeConfig::class);
        $this->s->configs->definir($this->siteId, ['icone' => '99999']);
    }

    public function testMigracaoEIdempotente(): void
    {
        $con = $this->conexao();
        self::assertTrue($con->createSchemaManager()->tablesExist(['site_config']));
        $m = new M017ConfigDoSite();
        $m->aplicar($con);
        $m->aplicar($con);
        self::assertSame(17, $m->versao());
        self::assertTrue($con->createSchemaManager()->tablesExist(['site_config']));
    }

    public function testMoldeLeConfigEPaginaUsaOsValores(): void
    {
        $this->s->configs->definir($this->siteId, [
            'titulo_sufixo' => '| Meu Site',
            'descricao' => 'Descrição do site toda',
            'idioma' => 'en',
            'rodape' => '<p>Rodapé <strong>do site</strong></p>',
        ]);
        $this->s->publicador->publicarSite($this->s->sites->porId($this->siteId) ?? self::fail());
        $html = $this->pagina('/sobre');
        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('<title>Sobre | Meu Site</title>', $html);
        self::assertStringContainsString('<footer><p>Rodapé <strong>do site</strong></p></footer>', $html);
    }

    public function testRobotsEBuscaDesligados(): void
    {
        $this->s->configs->definir($this->siteId, ['robots_indexar' => '0', 'busca_ligada' => '0']);
        $this->s->publicador->publicarSite($this->s->sites->porId($this->siteId) ?? self::fail());
        $html = $this->pagina('/sobre');
        self::assertStringContainsString('<meta name="robots" content="noindex">', $html);
        self::assertStringNotContainsString('class="busca"', $html);
        self::assertSame(404, (new Aplicacao($this->caminhos))->tratar(Request::create('http://www.exemplo.org/busca?q=sobre'))->getStatusCode());
    }

    public function testBuscaUsaItensPorPaginaEMenuRespeitaProfundidadeDoSite(): void
    {
        $this->s->configs->definir($this->siteId, ['itens_por_pagina' => '3']);
        $res = (new Aplicacao($this->caminhos))->tratar(Request::create('http://www.exemplo.org/busca?q=a'));
        self::assertSame(200, $res->getStatusCode());

        $site = $this->s->sites->porId($this->siteId) ?? self::fail();
        self::assertSame($this->s->menu->profundidade(), $this->s->menu->profundidadeDe($site));
        $this->s->configs->definir($this->siteId, ['menu_profundidade' => '1']);
        self::assertSame(1, $this->s->menu->profundidadeDe($site));
        $this->s->configs->definir($this->siteId, ['menu_profundidade' => '5']);
        self::assertSame(min(5, $this->s->menu->profundidade()), $this->s->menu->profundidadeDe($site), 'o teto global vence');
    }

    public function testMoldeRecusaChaveForaDoCatalogo(): void
    {
        $parser = new ExpressaoParser();
        self::assertInstanceOf(ExprMacro::class, $parser->expressao('%SITE.config.nome', 1));
        $this->expectException(ErroSintaxe::class);
        $parser->expressao('%SITE.config.segredo', 1);
    }

    public function testTelaExigeLoginPermissaoECsrf(): void
    {
        self::assertSame(303, $this->req('GET', $this->url())->getStatusCode());
        self::assertSame(403, $this->req('GET', $this->url(), $this->token('editor'))->getStatusCode());
        self::assertSame(403, $this->post($this->token('editor'), ['slogan' => 'x'])->getStatusCode());
        self::assertSame(403, $this->post($this->token('admin'), ['slogan' => 'x', 'versao' => $this->versao()], false)->getStatusCode());
        self::assertSame('', $this->s->configs->valor($this->siteId, 'slogan'));
        $res = $this->req('GET', $this->url(), $this->token('admin'));
        self::assertSame(200, $res->getStatusCode(), 'não exige modo elevado');
        self::assertStringContainsString('name="versao"', (string) $res->getContent());
        self::assertSame(404, $this->req('GET', $this->url() . '/x', $this->token('admin'))->getStatusCode());
    }

    public function testTelaSalvaAuditaSoNomesERepublica(): void
    {
        $t = $this->token('admin');
        $campos = ['nome' => 'Meu Site', 'idioma' => 'pt-BR', 'slogan' => 'Segredo-de-teste-XYZ', 'itens_por_pagina' => '10', 'busca_ligada' => '1', 'robots_indexar' => '1', 'titulo_sufixo' => '| Meu Site', 'versao' => $this->versao()];
        $res = $this->post($t, $campos);
        self::assertSame(303, $res->getStatusCode());
        self::assertStringContainsString('ok=salvo', (string) $res->headers->get('Location'));
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        self::assertSame('Segredo-de-teste-XYZ', $this->s->configs->valor($this->siteId, 'slogan'));
        self::assertStringContainsString('<title>Sobre | Meu Site</title>', $this->pagina('/sobre'));

        $log = array_values(array_filter($this->s->auditoria->recentes(500), static fn (array $l): bool => $l['acao'] === 'site.config'));
        self::assertCount(1, $log);
        self::assertStringContainsString('slogan', (string) $log[0]['detalhe']);
        self::assertStringNotContainsString('Segredo-de-teste-XYZ', (string) $log[0]['detalhe']);
    }

    public function testTelaMostraErroPorCampoEConflitoDeVersao(): void
    {
        $t = $this->token('admin');
        $res = $this->post($t, ['itens_por_pagina' => '999', 'analytics' => '<script>x</script>', 'versao' => $this->versao()]);
        self::assertSame(422, $res->getStatusCode());
        self::assertStringContainsString('cfg-itens_por_pagina-erro', (string) $res->getContent());
        self::assertStringNotContainsString('<script>x</script>', (string) $res->getContent());

        $velha = $this->versao();
        $this->s->configs->definir($this->siteId, ['slogan' => 'Mudou por outra pessoa']);
        $res = $this->post($t, ['slogan' => 'Minha', 'versao' => $velha]);
        self::assertSame(409, $res->getStatusCode());
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        self::assertSame('Mudou por outra pessoa', $this->s->configs->valor($this->siteId, 'slogan'));
    }

    public function testConversorMapeiaConfigDoCatalogo(): void
    {
        $r = (new Conversor())->converter('<@config nome="slogan"@>');
        self::assertStringContainsString('<@eco %SITE.config.slogan@>', $r->saida);
        $r = (new Conversor())->converter('<@config nome="nao_existe"@>');
        self::assertStringNotContainsString('%SITE.config', $r->saida);
        self::assertStringContainsString('nao_existe', $r->saida);
    }
}
