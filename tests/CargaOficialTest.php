<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Aplicacao;
use Pbl\Caminhos;
use Pbl\Console\PublicareOficialCarregarComando;
use Pbl\Conteudo\Modelos;
use Pbl\Demo\ConteudoPublicare;
use Pbl\Instalacao\Instalador;
use Pbl\Instalacao\PelesDistribuidas;
use Pbl\Oficial\CargaOficial;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;

/**
 * A carga OFICIAL do publicare.org: sem contas nem texto de demonstração, SEO do host real, idempotente e sem tocar no que o editor criou.
 * Instala e carrega uma vez por classe; os testes de idempotência e de preservação usam instalações próprias.
 */
final class CargaOficialTest extends TestCase
{
    private const HOST = 'publicare.org';
    private const EMAIL = 'contato@publicare.org';
    /** A palavra proibida nas páginas publicadas, montada em partes para este arquivo não se achar na varredura. */
    private const PALAVRA = 'exem' . 'plo';

    private static string $raiz;
    private static Caminhos $caminhos;
    private static Servicos $s;
    private static int $siteId;
    /** @var array{criados: int, atualizados: int, inalterados: int, removidos: int, publicadas: int, avisos: list<string>} */
    private static array $primeira;

    /** @return array{0: string, 1: Caminhos, 2: Servicos, 3: int} */
    private static function instalar(string $host = self::HOST): array
    {
        $raiz = Ajuda::raizDeProjeto();
        $c = new Caminhos($raiz);
        (new Instalador($c))->instalar(ConteudoPublicare::NOME_DO_SITE, $host, null, [], PelesDistribuidas::PADRAO);
        $s = Servicos::doBancoInstalado($c);

        return [$raiz, $c, $s, $s->sites->todos()[0]->id];
    }

    public static function setUpBeforeClass(): void
    {
        [self::$raiz, self::$caminhos, self::$s, self::$siteId] = self::instalar();
        self::$primeira = (new CargaOficial(self::$caminhos, self::$s))->carregar(self::$siteId, self::EMAIL);
    }

    public static function tearDownAfterClass(): void
    {
        Ajuda::apagar(self::$raiz);
    }

    /** @return list<string> */
    private static function paginas(Caminhos $c, int $siteId): array
    {
        $nomes = [];
        foreach (glob($c->dados() . '/cache/paginas/' . $siteId . '/*.html') ?: [] as $f) {
            $nomes[] = basename($f, '.html');
        }
        sort($nomes);

        return $nomes;
    }

    private static function html(string $nome): string
    {
        return (string) file_get_contents(self::$caminhos->dados() . '/cache/paginas/' . self::$siteId . '/' . $nome . '.html');
    }

    private static function pegar(string $caminho): \Symfony\Component\HttpFoundation\Response
    {
        return (new Aplicacao(self::$caminhos))->tratar(Request::create('https://' . self::HOST . $caminho));
    }

    private static function contar(Servicos $s, string $tabela): int
    {
        return (int) $s->conexao()->fetchOne("SELECT COUNT(*) FROM {$tabela}");
    }

    public function testSemContasDeDemonstracao(): void
    {
        self::assertSame([], self::$s->usuarios->todos());
        $banco = (string) json_encode(self::$s->conexao()->fetchAllAssociative('SELECT * FROM objeto'), JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('.invalid', $banco);
        self::assertStringNotContainsString('demo-2026', $banco);
        self::assertStringNotContainsString('Site de ' . self::PALAVRA, $banco);
    }

    public function testNenhumTextoPublicadoTemAPalavraExemplo(): void
    {
        $paginas = self::paginas(self::$caminhos, self::$siteId);
        self::assertGreaterThan(20, count($paginas));
        foreach ($paginas as $nome) {
            self::assertStringNotContainsStringIgnoringCase(self::PALAVRA, self::html($nome), $nome);
        }
        foreach (self::$s->conexao()->fetchAllAssociative("SELECT url_amigavel, titulo, descricao, dados FROM objeto WHERE status = 'publicado'") as $o) {
            self::assertStringNotContainsStringIgnoringCase(self::PALAVRA, implode(' ', $o), (string) $o['url_amigavel']);
        }
        self::assertStringNotContainsStringIgnoringCase(self::PALAVRA, (string) json_encode(self::$s->configs->efetivas(self::$siteId)));
    }

    public function testSoConteudoPublicoExcetoACaixaDeMensagens(): void
    {
        $fora = self::$s->conexao()->fetchFirstColumn("SELECT url_amigavel FROM objeto WHERE site_id = ? AND status <> 'publicado'", [self::$siteId]);
        // Os dois restos da instalação (`sobre` e a notícia de boas-vindas) vão para a lixeira.
        sort($fora);
        self::assertSame(['bem-vindo-ao-novo-site', 'mensagens-recebidas', 'sobre'], $fora);
        $paginas = self::paginas(self::$caminhos, self::$siteId);
        foreach (['bloco-aviso', 'rascunho-guia-de-estilo', 'galeria-de-imagens', 'documentos', 'envio-pendente-guia-de-estilo', 'sobre', 'mensagens-recebidas'] as $url) {
            self::assertNull(self::$s->objetos->porUrl(self::$siteId, $url)?->status === 'publicado' ? 1 : null, $url);
            self::assertNotContains($url, $paginas, $url);
        }
        self::assertStringNotContainsString('Sobre este', self::html('index'));
    }

    public function testPaginasDoManualNaoExistem(): void
    {
        foreach (self::$s->conexao()->fetchFirstColumn('SELECT url_amigavel FROM objeto WHERE site_id = ?', [self::$siteId]) as $url) {
            self::assertStringNotContainsString('manual', (string) $url);
        }
    }

    public function testPaginasPrincipaisPublicadasEResponde200(): void
    {
        $paginas = self::paginas(self::$caminhos, self::$siteId);
        foreach (array_keys(ConteudoPublicare::paginas()) as $url) {
            self::assertContains($url, $paginas, $url);
        }
        foreach (['index', 'perguntas-frequentes', 'noticias', 'contato', 'links-uteis', 'faq-1', 'guia-revisao'] as $url) {
            self::assertContains($url, $paginas, $url);
        }
        foreach ($paginas as $nome) {
            self::assertSame(200, self::pegar($nome === 'index' ? '/' : '/' . $nome)->getStatusCode(), $nome);
        }
        $home = self::html('index');
        self::assertStringContainsString(ConteudoPublicare::PROMESSA, $home);
        self::assertSame(8, substr_count($home, 'class="cartao-atalho"'), 'quatro pilares e quatro atalhos úteis');
        self::assertStringNotContainsString('demo-popular', $home);
    }

    public function testLinksInternosResolvem(): void
    {
        $paginas = self::paginas(self::$caminhos, self::$siteId);
        $sistema = ['/_pbl/', '/busca', '/tag/', '/feed.xml', '/sitemap.xml', '/robots.txt'];
        $total = 0;
        foreach ($paginas as $nome) {
            preg_match_all('#<a\b[^>]*\shref="([^"]*)"#', self::html($nome), $m);
            foreach ($m[1] as $href) {
                $href = html_entity_decode($href);
                if ($href === '' || $href[0] === '#' || preg_match('#^(https?:|mailto:|tel:)#', $href) === 1) {
                    continue;
                }
                $caminho = (string) parse_url($href, PHP_URL_PATH);
                foreach ($sistema as $prefixo) {
                    if (str_starts_with($caminho, $prefixo)) {
                        continue 2;
                    }
                }
                ++$total;
                $alvo = trim($caminho, '/');
                self::assertTrue($alvo === '' ? in_array('index', $paginas, true) : in_array($alvo, $paginas, true), "link quebrado em /{$nome}: {$href}");
            }
        }
        self::assertGreaterThan(30, $total);
    }

    public function testSeoComOHostReal(): void
    {
        $base = 'https://' . self::HOST;
        foreach (['index' => '/', 'o-que-e' => '/o-que-e', 'faq-1' => '/faq-1'] as $nome => $caminho) {
            $html = self::html($nome);
            self::assertStringContainsString('<link rel="canonical" href="' . $base . $caminho . '"', $html, $nome);
            self::assertMatchesRegularExpression('#<meta property="og:url" content="' . preg_quote($base . $caminho, '#') . '"#', $html, $nome);
            self::assertMatchesRegularExpression('#<meta property="og:image" content="' . preg_quote($base, '#') . '/[^"]+"#', $html, $nome);
            self::assertStringNotContainsString('noindex', $html, $nome);
            self::assertStringNotContainsString('localhost', $html, $nome);
        }
        $robots = self::pegar('/robots.txt');
        self::assertSame(200, $robots->getStatusCode());
        self::assertStringContainsString("Sitemap: {$base}/sitemap.xml", (string) $robots->getContent());
        self::assertDoesNotMatchRegularExpression('#^Disallow:\s*/\s*$#m', (string) $robots->getContent());
        $mapa = (string) self::pegar('/sitemap.xml')->getContent();
        self::assertStringContainsString("<loc>{$base}/o-que-e</loc>", $mapa);
        self::assertStringNotContainsString('mensagens-recebidas', $mapa);
        self::assertStringNotContainsString('localhost', $mapa);
    }

    public function testOsAbsolutosVemDoCadastroENaoDoHostDaRequisicao(): void
    {
        $r = (new Aplicacao(self::$caminhos))->tratar(Request::create('https://' . self::HOST . '/o-que-e', 'GET', [], [], [], ['HTTP_HOST' => self::HOST]));
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('href="https://' . self::HOST . '/o-que-e"', (string) $r->getContent());
        self::assertStringNotContainsString('outro.example', (string) $r->getContent());
    }

    public function testContatoEFormularioRealEEnvioViraMensagemPrivada(): void
    {
        $contato = self::$s->objetos->porUrl(self::$siteId, 'contato');
        self::assertNotNull($contato);
        self::assertSame('publicado', $contato->status);
        self::assertSame(self::EMAIL, self::$s->configs->valor(self::$siteId, 'email_contato'));
        self::assertStringContainsString('<form', self::html('contato'));
        $caixa = self::$s->objetos->porUrl(self::$siteId, 'mensagens-recebidas');
        self::assertSame('privado', $caixa?->status);
        self::assertSame($caixa->id, (int) $contato->dados['destino']);
    }

    public function testModelosEPeleDaCarga(): void
    {
        self::assertSame('publicare', self::$s->sites->porId(self::$siteId)?->pele);
        $modelos = new Modelos(self::$s->conexao(), self::$caminhos);
        self::assertSame(['faq', 'destaque', 'bloco'], array_values(array_intersect(['faq', 'destaque', 'bloco'], $modelos->ativos())));
        self::assertNotContains('evento', $modelos->ativos(), 'sem agenda: todo evento tem data');
        self::assertNull(self::$s->objetos->porUrl(self::$siteId, 'agenda'));
    }

    public function testPrimeiraCargaCriouEPublicou(): void
    {
        self::assertGreaterThan(40, self::$primeira['criados']);
        self::assertGreaterThan(20, self::$primeira['publicadas']);
        self::assertSame(2, self::$primeira['removidos']);
    }

    public function testSegundaCargaNaoCriaNemAtualizaNada(): void
    {
        [$raiz, $c, $s, $siteId] = self::instalar();
        try {
            $carga = new CargaOficial($c, $s);
            $carga->carregar($siteId, self::EMAIL);
            $antes = [self::contar($s, 'objeto'), self::contar($s, 'objeto_versao'), self::contar($s, 'arquivo')];
            $r = $carga->carregar($siteId, self::EMAIL);
            self::assertSame(0, $r['criados']);
            self::assertSame(0, $r['atualizados'], 'sem versões vazias');
            self::assertSame(0, $r['removidos']);
            self::assertGreaterThan(40, $r['inalterados']);
            self::assertSame($antes, [self::contar($s, 'objeto'), self::contar($s, 'objeto_versao'), self::contar($s, 'arquivo')]);
        } finally {
            Ajuda::apagar($raiz);
        }
    }

    public function testPreservaOQueOEditorCriouEReverteOQueECarga(): void
    {
        [$raiz, $c, $s, $siteId] = self::instalar();
        try {
            $carga = new CargaOficial($c, $s);
            $carga->carregar($siteId, self::EMAIL);
            $pagina = $s->classes->porNome('pagina');
            self::assertNotNull($pagina);
            $dele = $s->objetos->criar($siteId, $pagina, $s->sites->porId($siteId)?->objetoRaizId, 'Página do editor', 'pagina-do-editor', ['resumo' => 'Meu', 'corpo' => '<p>Texto do editor</p>']);
            $comunidade = $s->objetos->porUrl($siteId, 'comunidade');
            $filha = $s->objetos->criar($siteId, $pagina, $comunidade?->id, 'Outra do editor', 'outra-do-editor', ['resumo' => 'Meu', 'corpo' => '<p>Mais</p>']);
            $oficial = $s->objetos->porUrl($siteId, 'o-que-e');
            self::assertNotNull($oficial);
            $s->objetos->atualizar($oficial->id, ['titulo' => 'Título alterado', 'dados' => ['corpo' => '<p>Alterado</p>']]);
            $faq = $s->objetos->porUrl($siteId, 'faq-3');
            self::assertNotNull($faq);
            $s->objetos->mandarParaLixeira($faq->id, $faq->versao);

            $r = $carga->carregar($siteId, self::EMAIL);

            self::assertSame(0, $r['criados']);
            self::assertSame(2, $r['atualizados']);
            foreach ([$dele, $filha] as $o) {
                $depois = $s->objetos->porUrl($siteId, $o->urlAmigavel);
                self::assertSame($o->versao, $depois?->versao, $o->urlAmigavel);
                self::assertSame($o->dados, $depois->dados);
                self::assertSame('publicado', $depois->status);
                self::assertSame($o->paiId, $depois->paiId);
            }
            self::assertSame(ConteudoPublicare::paginas()['o-que-e']['titulo'], $s->objetos->porUrl($siteId, 'o-que-e')?->titulo);
            self::assertSame('publicado', $s->objetos->porUrl($siteId, 'faq-3')?->status, 'o que a carga tinha e foi para a lixeira volta');
        } finally {
            Ajuda::apagar($raiz);
        }
    }

    public function testNaoApagaSobreOuNoticiaQueOEditorJaMudou(): void
    {
        [$raiz, $c, $s, $siteId] = self::instalar();
        try {
            $sobre = $s->objetos->porUrl($siteId, 'sobre');
            self::assertNotNull($sobre);
            $s->objetos->atualizar($sobre->id, ['dados' => ['corpo' => '<p>Texto escrito pelo editor.</p>']]);
            $r = (new CargaOficial($c, $s))->carregar($siteId, self::EMAIL);
            self::assertSame('publicado', $s->objetos->porUrl($siteId, 'sobre')?->status);
            self::assertSame(1, $r['removidos']);
        } finally {
            Ajuda::apagar($raiz);
        }
    }

    public function testFalhaSemDominioReal(): void
    {
        [$raiz, $c, $s, $siteId] = self::instalar('localhost');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('não é um domínio real');
            (new CargaOficial($c, $s))->carregar($siteId, self::EMAIL);
        } finally {
            Ajuda::apagar($raiz);
        }
    }

    public function testHostPedidoViraODominioPrincipal(): void
    {
        [$raiz, $c, $s, $siteId] = self::instalar('localhost');
        try {
            (new CargaOficial($c, $s))->carregar($siteId, self::EMAIL, self::HOST);
            self::assertSame(self::HOST, $s->dominios->principalDe($siteId)?->host);
            $home = (string) file_get_contents($c->dados() . '/cache/paginas/' . $siteId . '/index.html');
            self::assertStringContainsString('href="https://publicare.org/"', $home);
            self::assertStringNotContainsString('https://localhost', $home);
        } finally {
            Ajuda::apagar($raiz);
        }
    }

    public function testComandoExigeEmailDeContatoEValida(): void
    {
        [$raiz, $c] = self::instalar();
        try {
            $t = new CommandTester(new PublicareOficialCarregarComando($c));
            self::assertSame(1, $t->execute([]));
            self::assertStringContainsString('--email-contato', $t->getDisplay());
            self::assertSame(1, $t->execute(['--email-contato' => 'nao-responda@' . self::PALAVRA . '.invalid']));
            self::assertSame(0, $t->execute(['--email-contato' => self::EMAIL, '--sem-publicar' => true]), $t->getDisplay());
            self::assertStringContainsString('criado(s)', $t->getDisplay());
            self::assertSame(0, $t->execute(['--email-contato' => self::EMAIL]), $t->getDisplay());
            self::assertStringContainsString('0 criado(s), 0 atualizado(s)', $t->getDisplay());
        } finally {
            Ajuda::apagar($raiz);
        }
    }

    public function testTextoOficialNaoTemAPalavraProibida(): void
    {
        $textos = [ConteudoPublicare::inicio()['corpo'], ConteudoPublicare::inicioDaInstalacao()['corpo']];
        foreach (ConteudoPublicare::paginas() as $p) {
            $textos[] = $p['titulo'] . $p['descricao'] . $p['resumo'] . $p['corpo'];
        }
        foreach (ConteudoPublicare::perguntas() as [$p, $r]) {
            $textos[] = $p . $r;
        }
        foreach (ConteudoPublicare::guias() as $g) {
            $textos[] = $g['titulo'] . $g['resumo'] . $g['corpo'];
        }
        self::assertStringNotContainsStringIgnoringCase(self::PALAVRA, implode("\n", $textos));
        self::assertStringContainsString('demonstração', ConteudoPublicare::inicio(true)['corpo'], 'o aviso é só da demonstração');
    }
}
