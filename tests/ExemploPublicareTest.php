<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Aplicacao;
use Pbl\Caminhos;
use Pbl\Console\DemoPopularComando;
use Pbl\Conteudo\Modelos;
use Pbl\Demo\ConteudoPublicare;
use Pbl\Instalacao\Instalador;
use Pbl\Instalacao\PelesDistribuidas;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * O site de exemplo do PBL4 (apresentação do Publicare 4 na pele `publicare`): instalação limpa leve e demonstração completa.
 * Instala uma vez por classe de teste; cada teste só lê.
 */
final class ExemploPublicareTest extends TestCase
{
    private const HOST = 'www.exemplo.org';
    /** O nome do exemplo antigo, montado em partes para este arquivo não se achar a si mesmo na varredura. */
    private const ANTIGO = 'Instituto ' . 'Exemplo';

    private static string $raizLeve;
    private static string $raizDemo;
    private static Caminhos $leve;
    private static Caminhos $demo;
    private static int $siteLeve;
    private static int $siteDemo;

    public static function setUpBeforeClass(): void
    {
        self::$raizLeve = Ajuda::raizDeProjeto();
        self::$leve = new Caminhos(self::$raizLeve);
        (new Instalador(self::$leve))->instalar(ConteudoPublicare::NOME_DO_SITE, self::HOST, null, [], PelesDistribuidas::PADRAO);
        $s = Servicos::doBancoInstalado(self::$leve);
        $site = $s->sites->todos()[0];
        self::$siteLeve = $site->id;
        $s->publicador->publicarSite($site);

        self::$raizDemo = Ajuda::raizDeProjeto();
        self::$demo = new Caminhos(self::$raizDemo);
        (new Instalador(self::$demo))->instalar(ConteudoPublicare::NOME_DO_SITE, self::HOST, null, [], PelesDistribuidas::PADRAO);
        $t = new CommandTester(new DemoPopularComando(self::$demo));
        self::assertSame(0, $t->execute([]), $t->getDisplay());
        self::$siteDemo = Servicos::doBancoInstalado(self::$demo)->sites->todos()[0]->id;
    }

    public static function tearDownAfterClass(): void
    {
        Ajuda::apagar(self::$raizLeve);
        Ajuda::apagar(self::$raizDemo);
    }

    private static function pegar(Caminhos $c, string $url): Response
    {
        return (new Aplicacao($c))->tratar(Request::create('https://' . self::HOST . $url));
    }

    private static function arquivoPublicado(Caminhos $c, int $siteId, string $nome): string
    {
        $arquivo = $c->dados() . '/cache/paginas/' . $siteId . '/' . $nome . '.html';
        self::assertFileExists($arquivo);

        return (string) file_get_contents($arquivo);
    }

    /** @return list<string> nomes dos arquivos publicados (sem a extensão), ex.: `index`, `o-que-e` */
    private static function paginasPublicadas(): array
    {
        $nomes = [];
        foreach (glob(self::$demo->dados() . '/cache/paginas/' . self::$siteDemo . '/*.html') ?: [] as $f) {
            $nomes[] = basename($f, '.html');
        }
        sort($nomes);

        return $nomes;
    }

    // --- Instalação limpa (leve) --------------------------------------------------------------------------------------

    public function testInstalacaoLimpaUsaPublicareMasContinuaLeve(): void
    {
        $s = Servicos::doBancoInstalado(self::$leve);
        self::assertSame('publicare', $s->sites->porId(self::$siteLeve)?->pele);
        self::assertSame([], (new Modelos($s->conexao(), self::$leve))->ativos(), 'a instalação limpa não liga modelos opcionais');
        self::assertFileExists(self::$leve->peles() . '/publicare/padrao.pbl');
        self::assertFileExists(self::$leve->peles() . '/basica/padrao.pbl', 'a básica continua instalada e selecionável');
        $objetos = (int) $s->conexao()->fetchOne('SELECT COUNT(*) FROM objeto');
        self::assertLessThan(16, $objetos, 'instalação leve: poucos objetos');
        $home = self::arquivoPublicado(self::$leve, self::$siteLeve, 'index');
        self::assertStringContainsString(ConteudoPublicare::PROMESSA, $home);
        self::assertStringContainsString('class="hero"', $home);
        self::assertSame(4, substr_count($home, 'class="cartao-atalho"'), 'os quatro pilares');
        self::assertStringContainsString('Como instalar', $home);
        self::assertStringContainsString('Ver a demonstração', $home);
        self::assertStringContainsString('php bin/pbl demo-popular', $home);
        self::assertStringContainsString('/_pbl/peles/publicare/assets/pele.css', $home);
        self::assertStringNotContainsString(self::ANTIGO, $home);
        self::assertStringNotContainsString('Tudo funcionando', $home);
    }

    public function testInstalacaoComPeleDesconhecidaERecusada(): void
    {
        $raiz = Ajuda::raizDeProjeto();
        try {
            $this->expectException(\InvalidArgumentException::class);
            (new Instalador(new Caminhos($raiz)))->instalar('Site', self::HOST, null, [], 'nao-existe');
        } finally {
            Ajuda::apagar($raiz);
        }
    }

    // --- Demonstração completa ----------------------------------------------------------------------------------------

    public function testDemoPublicaTodasAsPaginasEResponde200(): void
    {
        $paginas = self::paginasPublicadas();
        foreach (array_keys(ConteudoPublicare::paginas()) as $url) {
            self::assertContains($url, $paginas, $url);
        }
        foreach (['index', 'perguntas-frequentes', 'noticias', 'agenda', 'contato', 'sobre', 'onepage', 'portal', 'documentos', 'links-uteis'] as $url) {
            self::assertContains($url, $paginas, $url);
        }
        foreach ($paginas as $nome) {
            $caminho = $nome === 'index' ? '/' : '/' . $nome;
            self::assertSame(200, self::pegar(self::$demo, $caminho)->getStatusCode(), $caminho);
        }
        self::assertContains('faq-1', $paginas);
        self::assertNotContains('rascunho-guia-de-estilo', $paginas, 'rascunho (privado) não é publicado');
        self::assertNotContains('balanco-do-semestre', $paginas, 'conteúdo agendado só sai na data');
    }

    public function testNenhumLinkInternoQuebrado(): void
    {
        $paginas = self::paginasPublicadas();
        $sistema = ['/_pbl/', '/busca', '/tag/', '/feed.xml', '/sitemap.xml', '/robots.txt'];
        $total = 0;
        foreach ($paginas as $nome) {
            $html = self::arquivoPublicado(self::$demo, self::$siteDemo, $nome);
            preg_match_all('#<a\b[^>]*\shref="([^"]*)"#', $html, $m);
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
                $existe = $alvo === '' ? in_array('index', $paginas, true) : in_array($alvo, $paginas, true) || is_file(self::$demo->dados() . '/cache/paginas/' . self::$siteDemo . '/' . $alvo);
                self::assertTrue($existe, "link quebrado em /{$nome}: {$href}");
                if ($alvo !== '' && str_contains($alvo, '/') === false) {
                    self::assertSame(200, self::pegar(self::$demo, $caminho)->getStatusCode(), "{$href} em /{$nome}");
                }
            }
        }
        self::assertGreaterThan(60, $total, 'o teste precisa ter varrido links de verdade');
    }

    public function testNaoSobraNadaDoInstitutoExemplo(): void
    {
        foreach (self::paginasPublicadas() as $nome) {
            $html = self::arquivoPublicado(self::$demo, self::$siteDemo, $nome);
            self::assertStringNotContainsStringIgnoringCase(self::ANTIGO, $html, $nome);
            self::assertStringNotContainsString('Tudo funcionando', $html, $nome);
        }
        $s = Servicos::doBancoInstalado(self::$demo);
        foreach ($s->usuarios->todos() as $conta) {
            self::assertStringNotContainsString('exemplo.org', $conta->email, $conta->login);
        }
        $raiz = dirname(__DIR__);
        $achados = [];
        foreach (['app', 'previa', 'tests', 'docs/usuario', 'docs/tecnica', 'docs/adr', 'bin'] as $pasta) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz . '/' . $pasta, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $arquivo) {
                if (!$arquivo->isFile() || !in_array($arquivo->getExtension(), ['php', 'md', 'pbl', 'css', 'json', 'txt', ''], true) || str_contains($arquivo->getPathname(), '/vendor/')) {
                    continue;
                }
                $conteudo = (string) file_get_contents($arquivo->getPathname());
                if (stripos($conteudo, self::ANTIGO) !== false) {
                    $achados[] = substr($arquivo->getPathname(), strlen($raiz) + 1);
                }
            }
        }
        // O ADR 0021 guarda o histórico (o antigo exemplo) numa nota de adendo; só ele pode citar o nome antigo.
        self::assertSame([], array_values(array_diff($achados, ['docs/adr/0021-modelos-opcionais.md'])), 'o nome antigo só pode aparecer no adendo histórico do ADR 0021');
    }

    public function testCadaPaginaTemLandmarksH1IdiomaPularEImagensComAlt(): void
    {
        foreach (self::paginasPublicadas() as $nome) {
            $html = self::arquivoPublicado(self::$demo, self::$siteDemo, $nome);
            if (str_contains($html, '/_pbl/peles/publicare/')) {
                self::assertStringContainsString('<html lang="pt-BR">', $html, $nome);
                self::assertSame(1, substr_count($html, '<h1'), "{$nome}: um único h1");
                self::assertSame(1, substr_count($html, '<main'), $nome);
                self::assertSame(1, substr_count($html, '<footer'), "{$nome}: um único rodapé");
                self::assertStringContainsString('<header class="topo">', $html, $nome);
                self::assertStringContainsString('<nav class="menu" aria-label="Menu principal">', $html, $nome);
                self::assertStringContainsString('<a class="pular" href="#conteudo">', $html, $nome);
                self::assertStringContainsString('id="conteudo"', $html, $nome);
            }
            preg_match_all('#<img\b[^>]*>#', $html, $imgs);
            foreach ($imgs[0] as $img) {
                self::assertMatchesRegularExpression('#\salt="#', $img, "{$nome}: imagem sem alt: {$img}");
            }
        }
    }

    public function testSemInlineSemCdnESemJavaScriptDaPele(): void
    {
        foreach (self::paginasPublicadas() as $nome) {
            $html = self::arquivoPublicado(self::$demo, self::$siteDemo, $nome);
            if (!str_contains($html, '/_pbl/peles/publicare/')) {
                continue;
            }
            self::assertStringNotContainsString('<style', $html, $nome);
            self::assertStringNotContainsString(' style=', $html, $nome);
            self::assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)#', $html, $nome);
            self::assertDoesNotMatchRegularExpression('#\son[a-z]+=#i', $html, $nome);
            self::assertStringNotContainsString('javascript:', $html, $nome);
            self::assertDoesNotMatchRegularExpression('#<(?:script|img)\b[^>]*\ssrc="https?://|<link\b[^>]*rel="(?:stylesheet|icon|preload)"[^>]*href="https?://#', $html, $nome . ': recurso de terceiros');
            self::assertStringNotContainsString('bootstrap', $html, $nome);
            preg_match_all('#<script\b[^>]*\ssrc="([^"]+)"#', $html, $scripts);
            foreach ($scripts[1] as $src) {
                self::assertStringStartsWith('/_pbl/assets/pbl-barra.js', $src, "{$nome}: só o script da barra de edição, que é do sistema");
            }
        }
    }

    public function testHomeDaDemoTemEstruturaDaPeleNova(): void
    {
        $html = self::arquivoPublicado(self::$demo, self::$siteDemo, 'index');
        $ultima = -1;
        foreach (['class="topo"', 'class="hero"', 'class="faixa pilares"', 'class="faixa suave"', 'class="destaques"', 'class="faixa suave vitrine"', 'class="faixa ultimas"', 'class="faixa chamada"', 'class="rodape"'] as $marca) {
            $pos = strpos($html, $marca);
            self::assertNotFalse($pos, "falta {$marca} na home");
            self::assertGreaterThan($ultima, $pos, "{$marca} fora de ordem");
            $ultima = $pos;
        }
        self::assertSame(1, substr_count($html, '<h1'));
        self::assertStringContainsString(ConteudoPublicare::PROMESSA, $html);
        // Quatro pilares e, em "Veja também", seis cartões úteis (nada herdado do exemplo antigo, nada repetido).
        self::assertSame(10, substr_count($html, 'class="cartao-atalho"'));
        $vitrine = substr($html, (int) strpos($html, 'class="faixa suave vitrine"'), (int) strpos($html, 'class="faixa ultimas"') - (int) strpos($html, 'class="faixa suave vitrine"'));
        self::assertSame(6, substr_count($vitrine, 'class="cartao-atalho"'));
        foreach (['Como atualizar', 'Documentação do usuário', 'Linguagem de moldes', 'Pele onepage', 'Pele portal', 'Segurança'] as $titulo) {
            self::assertStringContainsString('<span class="titulo">' . $titulo . '</span>', $vitrine, $titulo);
        }
        self::assertStringNotContainsString('Ver versão', $html);
        self::assertStringNotContainsString('Versões publicadas no GitHub', $html);
        self::assertMatchesRegularExpression('#<a class="cartao-atalho" href="/onepage[^"]*"[^>]*><span class="icone" aria-hidden="true"></span><span class="titulo">Pele onepage</span>#u', $html);
        self::assertMatchesRegularExpression('#<a class="cartao-atalho" href="/portal[^"]*"[^>]*><span class="icone" aria-hidden="true"></span><span class="titulo">Pele portal</span>#u', $html);
        // Os botões do hero e a chamada final vêm da pasta "chamadas".
        self::assertGreaterThanOrEqual(2, substr_count($html, 'class="botao'));
        self::assertStringContainsString('Como instalar', $html);
        // Aviso do site e rodapé vêm dos blocos.
        self::assertStringContainsString('class="aviso-do-site"', $html);
        self::assertStringContainsString('licença Apache-2.0', $html);
        // Três destaques com texto, três últimas notícias reais com data.
        self::assertSame(3, substr_count($html, 'class="destaque"'));
        self::assertStringContainsString('Veja o que ele faz', $html);
        self::assertSame(3, substr_count($html, '<article class="item">'));
        self::assertSame(1, substr_count($html, 'Versão 0.1.0-rc.10: recuperação'), 'a notícia da rc.10 aparece uma vez só (as do portal ficam fora da home)');
        // Sem imagem vazia: destaques e notícias da home ou trazem imagem real ou não têm espaço para ela.
        self::assertSame(3, substr_count($html, 'class="destaque"'));
        self::assertSame(0, substr_count($html, 'class="item-imagem"'));
        self::assertSame(1, preg_match_all('#<h3 data-pbl-objeto="\d+">Veja o que ele faz#', $html), 'um destaque por assunto, sem títulos repetidos');
        self::assertSame(0, substr_count($html, 'Conheça.'));
        self::assertMatchesRegularExpression('#<span class="data">\d{2}/\d{2}/\d{4}</span>#', $html);
        self::assertStringContainsString('Versão 0.1.0-rc.10', $html);
        // Busca ligada e menu com os itens do exemplo.
        self::assertStringContainsString('role="search"', $html);
        foreach (['O que é', 'O que faz', 'Como facilita', 'O que inova', 'Perguntas', 'Instalação', 'Comunidade', 'Estado'] as $rotulo) {
            self::assertStringContainsString('>' . $rotulo . '</a>', $html, $rotulo);
        }
    }

    public function testPerguntasFrequentesEmDetailsComDozePerguntasOuMenos(): void
    {
        $html = self::arquivoPublicado(self::$demo, self::$siteDemo, 'perguntas-frequentes');
        $n = substr_count($html, '<details class="faq-item"');
        self::assertGreaterThanOrEqual(8, $n);
        self::assertLessThanOrEqual(12, $n);
        self::assertSame(count(ConteudoPublicare::perguntas()), $n);
        self::assertSame($n, substr_count($html, '<summary>'));
        self::assertStringNotContainsString('accordion', $html);
        self::assertStringContainsString('<h2>Instalação e operação</h2>', $html);
        self::assertStringContainsString('Apache-2.0', $html);
    }

    public function testPaginaDeEventosNoticiasEPaginaInternaNaPeleNova(): void
    {
        $agenda = self::arquivoPublicado(self::$demo, self::$siteDemo, 'agenda');
        self::assertStringContainsString('Marcos do projeto', $agenda);
        self::assertStringContainsString('Eventos passados', $agenda);
        self::assertStringContainsString('Versão 0.1.0-rc.10', $agenda);
        $noticia = self::arquivoPublicado(self::$demo, self::$siteDemo, 'versao-0-1-0-rc-10');
        self::assertStringContainsString('Publicado em 24/11/2026', $noticia);
        $evento = self::arquivoPublicado(self::$demo, self::$siteDemo, 'marco-rc-10');
        self::assertStringContainsString('class="evento-quando"', $evento);
        $interna = self::arquivoPublicado(self::$demo, self::$siteDemo, 'o-que-ele-faz');
        self::assertStringContainsString('class="pagina-topo"', $interna);
        self::assertStringContainsString('class="pbl-migalhas"', $interna);
        self::assertStringContainsString('<h2>Operação</h2>', $interna);
        $estado = self::arquivoPublicado(self::$demo, self::$siteDemo, 'estado-do-projeto');
        self::assertStringContainsString(\Pbl\Versao::ATUAL, $estado, 'a versão vem de Versao::ATUAL, não de texto fixo');
        self::assertStringContainsString('Ainda não existe', $estado);
    }

    public function testPaginaNaoEncontradaFicaDentroDaPele(): void
    {
        $r = self::pegar(self::$demo, '/isto-nao-existe');
        self::assertSame(404, $r->getStatusCode());
        $html = (string) $r->getContent();
        self::assertStringContainsString('<h1>Página não encontrada</h1>', $html);
        self::assertStringContainsString('/_pbl/peles/publicare/assets/pele.css', $html);
        self::assertStringContainsString('<header class="topo">', $html);
        self::assertStringContainsString('<footer class="rodape">', $html);
        self::assertStringContainsString('<main id="conteudo"', $html);
        self::assertStringContainsString('lang="pt-BR"', $html);
        self::assertStringContainsString('noindex', $html);
        self::assertStringNotContainsString('<style', $html);
    }

    public function testBuscaEOutrasPaginasEspeciaisNaPeleNova(): void
    {
        $r = self::pegar(self::$demo, '/busca?q=moldes');
        self::assertSame(200, $r->getStatusCode());
        $html = (string) $r->getContent();
        self::assertStringContainsString('class="lista-itens"', $html);
        self::assertStringContainsString('pele.css', $html);
        $tag = self::pegar(self::$demo, '/tag/painel');
        self::assertSame(200, $tag->getStatusCode());
    }

    public function testContasDeDemonstracaoTemIdentidadeDoPublicare(): void
    {
        $s = Servicos::doBancoInstalado(self::$demo);
        foreach (['autor', 'editor'] as $login) {
            $conta = $s->usuarios->porLogin($login);
            self::assertNotNull($conta, $login);
            self::assertStringEndsWith('@exemplo.invalid', $conta->email);
            self::assertStringContainsString('demonstração', $conta->nome);
        }
        $site = $s->sites->porId(self::$siteDemo);
        self::assertNotNull($site);
        self::assertSame('Publicare 4', $site->nome);
        self::assertSame('publicare', $site->pele);
    }

    public function testDemoEIdempotente(): void
    {
        $s = Servicos::doBancoInstalado(self::$demo);
        $antes = (int) $s->conexao()->fetchOne('SELECT COUNT(*) FROM objeto');
        self::assertGreaterThan(60, $antes);
        $t = new CommandTester(new DemoPopularComando(self::$demo));
        self::assertSame(1, $t->execute([]), 'site cheio: recusa sem --forcar');
        self::assertSame(0, $t->execute(['--forcar' => true, '--sem-publicar' => true]), $t->getDisplay());
        self::assertSame($antes, (int) $s->conexao()->fetchOne('SELECT COUNT(*) FROM objeto'));
    }
}
