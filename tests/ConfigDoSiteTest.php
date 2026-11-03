<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Doctrine\DBAL\Connection;
use Pbl\Acesso\Perfil;
use Pbl\Acesso\SessaoRepositorio;
use Pbl\Aplicacao;
use Pbl\Arquivos\ArmazenamentoDeArquivos;
use Pbl\Arquivos\LimpadorDeUploads;
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
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
        $res = $this->post($t, ['aba' => 'identidade', 'nome' => 'Meu Site', 'idioma' => 'pt-BR', 'slogan' => 'Segredo-de-teste-XYZ', 'versao' => $this->versao()]);
        self::assertSame(303, $res->getStatusCode());
        self::assertStringContainsString('ok=salvo', (string) $res->headers->get('Location'));
        self::assertStringContainsString('aba=identidade', (string) $res->headers->get('Location'));
        $res = $this->post($t, ['aba' => 'busca', 'titulo_sufixo' => '| Meu Site', 'robots_indexar' => '1', 'versao' => $this->versao()]);
        self::assertSame(303, $res->getStatusCode());
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        self::assertSame('Segredo-de-teste-XYZ', $this->s->configs->valor($this->siteId, 'slogan'));
        self::assertStringContainsString('<title>Sobre | Meu Site</title>', $this->pagina('/sobre'));

        $log = array_values(array_filter($this->s->auditoria->recentes(500), static fn (array $l): bool => $l['acao'] === 'site.config'));
        self::assertCount(2, $log);
        $detalhes = implode(' ', array_map(static fn (array $l): string => (string) $l['detalhe'], $log));
        self::assertStringContainsString('slogan', $detalhes);
        self::assertStringNotContainsString('Segredo-de-teste-XYZ', $detalhes);
    }

    public function testTelaMostraErroPorCampoEConflitoDeVersao(): void
    {
        $t = $this->token('admin');
        $res = $this->post($t, ['aba' => 'comportamento', 'itens_por_pagina' => '999', 'versao' => $this->versao()]);
        self::assertSame(422, $res->getStatusCode());
        self::assertStringContainsString('cfg-itens_por_pagina-erro', (string) $res->getContent());
        $res = $this->post($t, ['aba' => 'rodape', 'analytics' => '<script>x</script>', 'versao' => $this->versao()]);
        self::assertSame(422, $res->getStatusCode());
        self::assertStringNotContainsString('<script>x</script>', (string) $res->getContent());

        $velha = $this->versao();
        $this->s->configs->definir($this->siteId, ['slogan' => 'Mudou por outra pessoa']);
        $res = $this->post($t, ['aba' => 'identidade', 'slogan' => 'Minha', 'versao' => $velha]);
        self::assertSame(409, $res->getStatusCode());
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        self::assertSame('Mudou por outra pessoa', $this->s->configs->valor($this->siteId, 'slogan'));
    }

    public function testAbasEGravacaoSoDoProprioGrupo(): void
    {
        $t = $this->token('admin');
        $html = (string) $this->req('GET', $this->url() . '?aba=contato', $t)->getContent();
        foreach (['identidade', 'busca', 'contato', 'rodape', 'comportamento'] as $aba) {
            self::assertStringContainsString('id="painel-' . $aba . '"', $html);
        }
        self::assertStringContainsString('id="painel-contato" class="pbl-aba-painel mb-4 active show"', $html);
        self::assertSame(5, substr_count($html, 'name="versao"'), 'cada aba é um formulário próprio');
        self::assertSame(5, substr_count($html, 'name="aba"'));
        self::assertStringContainsString('enctype="multipart/form-data"', $html);
        self::assertStringContainsString('id="painel-raros-identidade"', $html, 'imagem de abertura num bloco recolhido');
        self::assertStringContainsString('aria-controls="cfg-nome-ajuda"', $html);
        self::assertSame(0, preg_match('/<script(?![^>]*\\ssrc=)/', $html), 'nenhum script inline');
        self::assertStringNotContainsString('onclick=', $html);

        // Gravar a aba Contato com um valor que pertence a outra aba não o grava.
        $res = $this->post($t, ['aba' => 'contato', 'telefone' => '(61) 99999-0000', 'slogan' => 'Intruso', 'versao' => $this->versao()]);
        self::assertSame(303, $res->getStatusCode());
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        self::assertSame('(61) 99999-0000', $this->s->configs->valor($this->siteId, 'telefone'));
        self::assertSame('', $this->s->configs->valor($this->siteId, 'slogan'));
        // E gravar uma aba não zera o que é das outras.
        $this->s->configs->definir($this->siteId, ['rodape' => '<p>Rodapé</p>']);
        $this->post($t, ['aba' => 'contato', 'telefone' => '(61) 3333-0000', 'versao' => $this->versao()]);
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        self::assertSame('<p>Rodapé</p>', $this->s->configs->valor($this->siteId, 'rodape'));

        self::assertSame(400, $this->post($t, ['aba' => 'inexistente', 'versao' => $this->versao()])->getStatusCode());
        self::assertSame(400, $this->post($t, ['versao' => $this->versao()])->getStatusCode());
    }

    public function testCheckboxDesmarcadoGravaFalsoSoNaAbaDele(): void
    {
        $t = $this->token('admin');
        $this->post($t, ['aba' => 'comportamento', 'itens_por_pagina' => '10', 'menu_profundidade' => '0', 'versao' => $this->versao()]);
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        self::assertFalse($this->s->configs->valor($this->siteId, 'busca_ligada'));
        self::assertTrue($this->s->configs->valor($this->siteId, 'robots_indexar'), 'robots é da aba Busca e compartilhamento');
    }

    /** @param array<string, mixed> $campos */
    private function postComArquivo(string $token, array $campos, ?string $nomeDoCampo, ?string $conteudo, string $nomeEnviado = 'logo.png'): Response
    {
        $arquivos = [];
        if ($nomeDoCampo !== null && $conteudo !== null) {
            $tmp = tempnam(sys_get_temp_dir(), 'pbl4up');
            file_put_contents($tmp, $conteudo);
            $arquivos[$nomeDoCampo] = new UploadedFile($tmp, $nomeEnviado, null, \UPLOAD_ERR_OK, true);
        }
        $csrf = $this->s->csrf()->daSessao(SessaoRepositorio::hash($token));
        $r = Request::create('https://www.exemplo.org' . $this->url(), 'POST', ['_csrf' => $csrf] + $campos, ['__Host-pbl4' => $token], $arquivos, ['REMOTE_ADDR' => '198.51.100.7'] + self::ORIGEM);

        return (new Aplicacao($this->caminhos))->tratar($r);
    }

    /** PNG 1x1 válido (fixo, para não depender do GD no teste). */
    private function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==', true);
    }

    public function testEnvioDeLogoGuardaImagemMostraMiniaturaERemove(): void
    {
        $t = $this->token('admin');
        $res = $this->postComArquivo($t, ['aba' => 'identidade', 'versao' => $this->versao()], 'arquivo_logo', $this->png());
        self::assertSame(303, $res->getStatusCode(), (string) $res->getContent());
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $id = $this->s->configs->valor($this->siteId, 'logo');
        self::assertIsInt($id);
        $arq = $this->s->arquivos->porId($id) ?? self::fail();
        self::assertSame('png', $arq->extensao);
        self::assertSame($this->siteId, $arq->siteId);
        self::assertSame('config_logo', $arq->propriedade);

        $html = (string) $this->req('GET', $this->url(), $t)->getContent();
        self::assertStringContainsString('/_pbl/arquivo/' . $id . '/miniatura/150', $html);
        self::assertStringContainsString('name="remover_logo"', $html);
        self::assertStringNotContainsString('dados/uploads', $html, 'o caminho em disco não vaza');
        self::assertStringNotContainsString($arq->chave, $html);

        // Salvar de novo sem escolher arquivo mantém a imagem.
        $this->post($t, ['aba' => 'identidade', 'slogan' => 'x', 'versao' => $this->versao()]);
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        self::assertSame($id, $this->s->configs->valor($this->siteId, 'logo'));

        // Remover.
        $this->post($t, ['aba' => 'identidade', 'remover_logo' => '1', 'versao' => $this->versao()]);
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        self::assertSame('', $this->s->configs->valor($this->siteId, 'logo'));
    }

    public function testEnvioRecusaTipoFalsoPorConteudoEMaiorQueOLimite(): void
    {
        $t = $this->token('admin');
        // Extensão .png, conteúdo PHP/HTML.
        $res = $this->postComArquivo($t, ['aba' => 'identidade', 'versao' => $this->versao()], 'arquivo_logo', '<?php echo 1;', 'logo.png');
        self::assertSame(422, $res->getStatusCode());
        self::assertStringContainsString('cfg-logo-erro', (string) $res->getContent());
        // GIF verdadeiro: o formato existe no PBL4, mas a configuração aceita só PNG, JPG e WebP.
        $gif = (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
        $res = $this->postComArquivo($t, ['aba' => 'identidade', 'versao' => $this->versao()], 'arquivo_icone', $gif, 'icone.png');
        self::assertSame(422, $res->getStatusCode());
        // SVG não é aceito.
        $res = $this->postComArquivo($t, ['aba' => 'identidade', 'versao' => $this->versao()], 'arquivo_logo', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.svg');
        self::assertSame(422, $res->getStatusCode());
        // Acima do limite do campo (ícone: 1 MB): PNG válido com enchimento no fim.
        $res = $this->postComArquivo($t, ['aba' => 'identidade', 'versao' => $this->versao()], 'arquivo_icone', $this->png() . str_repeat("\0", 1024 * 1024 + 10), 'icone.png');
        self::assertSame(422, $res->getStatusCode());
        self::assertStringContainsString('MB', (string) $res->getContent());
        $html = (string) $res->getContent();
        self::assertStringNotContainsString(sys_get_temp_dir(), $html, 'o caminho temporário não vaza');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        self::assertSame('', $this->s->configs->valor($this->siteId, 'logo'));
        self::assertSame('', $this->s->configs->valor($this->siteId, 'icone'));
    }

    public function testEnvioComVersaoVelhaConflitaSemGuardarArquivo(): void
    {
        $t = $this->token('admin');
        $velha = $this->versao();
        $this->s->configs->definir($this->siteId, ['slogan' => 'Mudou']);
        $antes = (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM arquivo');
        $res = $this->postComArquivo($t, ['aba' => 'identidade', 'versao' => $velha], 'arquivo_logo', $this->png());
        self::assertSame(409, $res->getStatusCode());
        self::assertSame($antes, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM arquivo'));
    }

    public function testLimpadorNaoApagaImagemUsadaPelaConfiguracao(): void
    {
        $t = $this->token('admin');
        $this->postComArquivo($t, ['aba' => 'identidade', 'versao' => $this->versao()], 'arquivo_logo', $this->png());
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $id = $this->s->configs->valor($this->siteId, 'logo');
        self::assertIsInt($id);
        $this->s->conexao()->executeStatement('UPDATE arquivo SET criado_em = ? WHERE id = ?', [gmdate('c', time() - 7200), $id]);
        $l = new LimpadorDeUploads($this->s->conexao(), $this->s->classes, $this->s->arquivos, new ArmazenamentoDeArquivos($this->caminhos));
        self::assertSame(0, $l->executar(true)['orfaos']);
        // Sem a referência na configuração, passa a ser órfã.
        $this->s->configs->definir($this->siteId, ['logo' => '']);
        self::assertSame(1, $l->executar(true)['orfaos']);
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
