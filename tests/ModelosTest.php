<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Caminhos;
use Pbl\Console\DemoPopularComando;
use Pbl\Console\ModelosComando;
use Pbl\Conteudo\Modelos;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Modelos opcionais (evento, faq, destaque, bloco), a tela de ativação, o CLI e o demo (ADR 0021). */
final class ModelosTest extends TestCase
{
    use AdminAuxiliar;

    private function modelos(): Modelos
    {
        return new Modelos($this->s->conexao(), $this->caminhos);
    }

    private function pagina(string $url): string
    {
        $this->s->publicador->publicarSite($this->s->sites->porId($this->siteId) ?? self::fail());
        $arquivo = $this->caminhos->dados() . '/cache/paginas/' . $this->siteId . '/' . $url . '.html';
        self::assertFileExists($arquivo);

        return (string) file_get_contents($arquivo);
    }

    /** @return list<array{string}> */
    public static function esquemasPerigosos(): array
    {
        return [['javascript:alert(1)'], ['JAVASCRIPT:alert(1)'], [' javascript:alert(1)'], ["java\tscript:alert(1)"], ['data:text/html,x'], ['vbscript:x'], ["\tjavascript:x"], ['//evil.com\\x']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('esquemasPerigosos')]
    public function testEnderecosDosModelosRecusamEsquemasPerigososNaGravacao(string $perigoso): void
    {
        $this->modelos()->ativar('evento', $this->siteId);
        $this->modelos()->ativar('destaque', $this->siteId);
        $site = $this->s->sites->porId($this->siteId) ?? self::fail();
        $agenda = (new \Pbl\Conteudo\ObjetoRepositorio($this->s->conexao()))->porUrl($this->siteId, 'agenda') ?? self::fail();
        $casos = [['evento', 'link_inscricao', $agenda->id, ['inicio' => '2026-12-01 10:00:00']], ['destaque', 'destino_externo', $site->objetoRaizId, []]];
        foreach ($casos as [$classe, $campo, $pai, $base]) {
            try {
                $this->modelos()->garantirObjeto($site, $classe, $pai, 'Ruim ' . $classe, 'ruim-' . $classe, $base + [$campo => $perigoso]);
                self::fail("{$classe}.{$campo} aceitou o endereço perigoso");
            } catch (\Pbl\Conteudo\ViolacaoDeRegra $e) {
                self::assertSame($campo, $e->campo);
            }
        }
        $ok = $this->modelos()->garantirObjeto($site, 'evento', $agenda->id, 'Bom', 'bom', ['inicio' => '2026-12-01 10:00:00', 'link_inscricao' => 'https://exemplo.com/i']);
        self::assertGreaterThan(0, $ok->id);
    }

    public function testInstalacaoNaoTemModelosPorPadrao(): void
    {
        self::assertSame([], $this->modelos()->ativos());
        self::assertNull($this->s->classes->porNome('evento'));
    }

    public function testAtivarEIdempotente(): void
    {
        $a = $this->modelos()->ativar('evento', $this->siteId);
        self::assertSame(['evento', 'agenda'], $a['classes']);
        self::assertSame(3, $a['exemplos']);
        self::assertTrue($this->modelos()->ativo('evento'));
        $quantos = count($this->s->classes->todas());
        $b = $this->modelos()->ativar('evento', $this->siteId);
        self::assertSame([], $b['classes']);
        self::assertSame(0, $b['exemplos']);
        self::assertCount($quantos, $this->s->classes->todas());
    }

    public function testAtivarRecriaTiposFixosQueFaltamEmInstalacaoAntiga(): void
    {
        $this->s->conexao()->executeStatement("DELETE FROM classe WHERE nome IN ('arquivo', 'imagem')");
        self::assertNull($this->s->classes->porNome('arquivo'));
        $this->modelos()->ativar('evento', $this->siteId);
        self::assertNotNull($this->s->classes->porNome('arquivo'));
        self::assertNotNull($this->s->classes->porNome('imagem'));
    }

    public function testModeloDesconhecidoEhRecusado(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->modelos()->ativar('inexistente', $this->siteId);
    }

    public function testInstaladorAtivaOsModelosEscolhidos(): void
    {
        $outra = Ajuda::raizDeProjeto();
        try {
            $c = new Caminhos($outra);
            (new Instalador($c))->instalar('Outro', 'outro.exemplo.org', null, ['faq', 'bloco']);
            $s = Servicos::doBancoInstalado($c);
            self::assertNotNull($s->classes->porNome('faq'));
            self::assertNotNull($s->classes->porNome('bloco'));
            self::assertNull($s->classes->porNome('evento'));
            $this->expectException(\InvalidArgumentException::class);
            (new Instalador(new Caminhos(Ajuda::raizDeProjeto())))->instalar('X', 'x.exemplo.org', null, ['nada']);
        } finally {
            Ajuda::apagar($outra);
        }
    }

    public function testMoldesRenderizamAgendaEPerguntas(): void
    {
        $this->modelos()->ativar('evento', $this->siteId);
        $this->modelos()->ativar('faq', $this->siteId);
        $agenda = $this->pagina('agenda');
        self::assertStringContainsString('Próximos eventos', $agenda);
        self::assertStringContainsString('Encontro de exemplo', $agenda);
        self::assertStringContainsString('Eventos passados', $agenda);
        self::assertGreaterThan(strpos($agenda, 'Eventos passados'), strpos($agenda, 'Evento passado de exemplo'));
        self::assertLessThan(strpos($agenda, 'Eventos passados'), strpos($agenda, 'Encontro de exemplo'));
        $faq = $this->pagina('perguntas-frequentes');
        self::assertStringContainsString('class="accordion-item"', $faq);
        self::assertStringContainsString('Como edito esta pergunta?', $faq);
    }

    public function testDestaquesEBlocoAparecemNaPaginaInicial(): void
    {
        $this->modelos()->ativar('destaque', $this->siteId);
        $this->modelos()->ativar('bloco', $this->siteId);
        $inicio = $this->pagina('index');
        self::assertStringContainsString('class="destaque-principal"', $inicio, 'o primeiro destaque vira o principal do molde home');
        self::assertStringContainsString('Destaque de exemplo', $inicio);
        self::assertStringContainsString('Rodapé de exemplo', $inicio);
    }

    public function testSemOsModelosAPaginaInicialNaoMostraFaixaNemErro(): void
    {
        $inicio = $this->pagina('index');
        self::assertStringNotContainsString('class="carrossel', $inicio);
    }

    public function testTelaDeModelosListaEAtiva(): void
    {
        $t = $this->token('admin');
        $html = $this->html($this->req('GET', '/_pbl/modelos', $t));
        self::assertStringContainsString('Agenda de eventos', $html);
        self::assertStringContainsString('Inativo', $html);
        $r = $this->post($t, '/_pbl/modelos/ativar', ['modelo' => 'faq', 'site' => (string) $this->siteId]);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('ativado', $this->html($r));
        self::assertTrue($this->modelos()->ativo('faq'));
        self::assertCount(1, $this->auditoria('modelo.ativar'));
    }

    public function testTelaDeModelosSoParaAdminEComCsrf(): void
    {
        self::assertSame(403, $this->req('GET', '/_pbl/modelos', $this->token('editor'))->getStatusCode());
        $e = $this->token('editor');
        self::assertSame(403, $this->post($e, '/_pbl/modelos/ativar', ['modelo' => 'faq', 'site' => (string) $this->siteId])->getStatusCode());
        $a = $this->token('admin');
        self::assertSame(403, $this->post($a, '/_pbl/modelos/ativar', ['modelo' => 'faq', 'site' => (string) $this->siteId], false)->getStatusCode());
        self::assertSame(400, $this->post($a, '/_pbl/modelos/ativar', ['modelo' => 'nada', 'site' => (string) $this->siteId])->getStatusCode());
        self::assertFalse($this->modelos()->ativo('faq'));
    }

    public function testComandoListaEAtiva(): void
    {
        $t = new CommandTester(new ModelosComando($this->caminhos));
        self::assertSame(0, $t->execute([]));
        self::assertStringContainsString('inativo', $t->getDisplay());
        self::assertSame(0, $t->execute(['acao' => 'ativar', 'nome' => 'bloco', '--site' => (string) $this->siteId]));
        self::assertTrue($this->modelos()->ativo('bloco'));
        self::assertSame(1, $t->execute(['acao' => 'ativar', 'nome' => 'zzz']));
    }

    public function testDemoPopularEhIdempotentePublicaERecusaSiteCheio(): void
    {
        $t = new CommandTester(new DemoPopularComando($this->caminhos));
        self::assertSame(0, $t->execute(['--site' => (string) $this->siteId]), $t->getDisplay());
        self::assertStringContainsString('página(s) publicada(s)', $t->getDisplay());
        $contar = fn (): int => (int) $this->s->conexao()->fetchOne('SELECT COUNT(*) FROM objeto');
        $antes = $contar();
        self::assertGreaterThan(40, $antes);
        // Segunda rodada: o site já está cheio (recusa) e, com --forcar, não cria nada de novo.
        self::assertSame(1, $t->execute(['--site' => (string) $this->siteId]));
        self::assertSame(0, $t->execute(['--site' => (string) $this->siteId, '--forcar' => true, '--sem-publicar' => true]));
        self::assertSame($antes, $contar());
        $dir = $this->caminhos->dados() . '/cache/paginas/' . $this->siteId;
        self::assertFileExists($dir . '/index.html');
        self::assertFileDoesNotExist($dir . '/rascunho-regimento-interno.html', 'rascunho (privado) não é publicado');
        self::assertFileDoesNotExist($dir . '/balanco-do-semestre.html', 'conteúdo agendado só sai na data');
        self::assertFileExists($dir . '/faq-1.html');
        self::assertStringContainsString('Instituto Exemplo', (string) file_get_contents($dir . '/index.html'));
        self::assertStringContainsString('class="accordion-item"', (string) file_get_contents($dir . '/perguntas-frequentes.html'));
    }

    public function testHomeDaDemoTemHierarquiaAtalhosDasVersoesEHtmlLimpo(): void
    {
        $t = new CommandTester(new DemoPopularComando($this->caminhos));
        self::assertSame(0, $t->execute(['--site' => (string) $this->siteId]), $t->getDisplay());
        $dir = $this->caminhos->dados() . '/cache/paginas/' . $this->siteId;
        $html = (string) file_get_contents($dir . '/index.html');
        $ordem = ['class="topo"', 'class="hero"', 'class="carrossel', 'class="atalhos"', 'class="ultimas"', 'class="links-rapidos"'];
        $ultima = -1;
        foreach ($ordem as $marca) {
            $pos = strpos($html, $marca);
            self::assertNotFalse($pos, "falta {$marca} na home");
            self::assertGreaterThan($ultima, $pos, "{$marca} fora de ordem");
            $ultima = $pos;
        }
        // Dois cartões grandes para as versões, apontando para as raízes das peles.
        self::assertMatchesRegularExpression('#<a class="cartao-atalho" href="/onepage[^"]*"[^>]*><span class="titulo">Ver versão Onepage</span>#u', $html);
        self::assertMatchesRegularExpression('#<a class="cartao-atalho" href="/portal[^"]*"[^>]*><span class="titulo">Ver versão Portal</span>#u', $html);
        // Os cartões não se repetem em "Links rápidos", que continua com os demais links.
        $rapidos = substr($html, (int) strpos($html, 'class="links-rapidos"'));
        self::assertStringNotContainsString('Ver versão', $rapidos);
        self::assertStringContainsString('Agenda de eventos', $rapidos);
        // Folha de estilos própria; nada de estilo nem script embutido (CSP rígida).
        self::assertMatchesRegularExpression('#<link rel="stylesheet" href="/_pbl/peles/basica/assets/pele\.css\?v=[0-9a-f]{8}">#', $html);
        self::assertStringNotContainsString('<style', $html);
        self::assertStringNotContainsString(' style=', $html);
        self::assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)#', $html);
        // Lista de notícias: miniatura de 400 px (nítida) dentro de moldura de proporção fixa, com data.
        self::assertMatchesRegularExpression('#class="item-imagem"[^>]*><img src="/_pbl/arquivo/\d+/miniatura/400"#', $html);
        self::assertMatchesRegularExpression('#<span class="data">\d{2}/\d{2}/\d{4}</span>#', $html);
        self::assertFileExists($this->caminhos->peles() . '/basica/assets/pele.css');
        $css = (string) file_get_contents($this->caminhos->peles() . '/basica/assets/pele.css');
        self::assertStringContainsString('aspect-ratio', $css);
        self::assertStringContainsString('object-fit:cover', $css);
        self::assertStringContainsString(':focus-visible', $css);
        self::assertStringContainsString('prefers-color-scheme:dark', $css);
        // Demais páginas da pele básica seguem com a folha e sem inline.
        foreach (['noticias', 'agenda', 'perguntas-frequentes', 'contato', 'quem-somos'] as $url) {
            $p = (string) file_get_contents($dir . '/' . $url . '.html');
            self::assertStringContainsString('assets/pele.css', $p, $url);
            self::assertStringNotContainsString('<style', $p, $url);
            self::assertStringNotContainsString(' style=', $p, $url);
        }
        // JavaScript próprio em todas as peles, sempre arquivo local versionado: nunca inline, nunca manipulador no HTML (CSP script-src 'self').
        foreach (['index', 'noticias', 'perguntas-frequentes', 'agenda'] as $url) {
            $p = (string) file_get_contents($dir . '/' . $url . '.html');
            self::assertMatchesRegularExpression('#<script src="/_pbl/peles/basica/assets/pbl-js\.js\?v=[0-9a-f]{8}"></script>#', $p, $url);
            self::assertMatchesRegularExpression('#<script src="/_pbl/peles/basica/assets/bootstrap\.bundle\.min\.js\?v=[0-9a-f]{8}" defer></script>#', $p, $url);
            self::assertMatchesRegularExpression('#<script src="/_pbl/peles/basica/assets/pbl-pele\.js\?v=[0-9a-f]{8}" defer></script>#', $p, $url);
            self::assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)#', $p, $url);
            self::assertDoesNotMatchRegularExpression('#\son(click|load|error|change|submit|mouse\w+|key\w+)=#i', $p, $url);
            self::assertStringNotContainsString('javascript:', $p, $url);
            self::assertStringNotContainsString('cdn.', $p, $url);
        }
        foreach (['bootstrap.bundle.min.js', 'pbl-js.js', 'pbl-pele.js'] as $js) {
            self::assertFileExists($this->caminhos->peles() . '/basica/assets/' . $js);
        }
        // Menu recolhível, carrossel e controles com os atributos data-bs-* do Bootstrap, e o conteúdo todo no HTML estático (sem JS).
        self::assertMatchesRegularExpression('#<button class="topo-toggle" type="button" data-bs-toggle="collapse" data-bs-target="\#topo-nav" aria-controls="topo-nav" aria-expanded="false" aria-label="[^"]+">#', $html);
        self::assertStringContainsString('<div class="topo-nav collapse" id="topo-nav">', $html);
        self::assertStringContainsString('id="hero-carrossel" data-bs-ride="carousel"', $html);
        self::assertSame(3, substr_count($html, 'class="carousel-item destaque-principal'), 'os 3 destaques do demo viram slides');
        self::assertSame(1, substr_count($html, 'carousel-item destaque-principal active'));
        self::assertStringContainsString('data-bs-slide="prev"', $html);
        self::assertStringContainsString('data-bs-slide="next"', $html);
        self::assertStringContainsString('Oficinas de leitura', $html);
        self::assertStringContainsString('Seja voluntário', $html);
        self::assertStringContainsString('data-bs-target="#links-rapidos-lista" aria-controls="links-rapidos-lista" aria-expanded="true"', $html);
        self::assertStringContainsString('class="collapse show" id="links-rapidos-lista"', $html);
        $faqHtml = (string) file_get_contents($dir . '/perguntas-frequentes.html');
        self::assertMatchesRegularExpression('#<button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="\#pergunta-(\d+)" aria-controls="pergunta-\1" aria-expanded="false">#', $faqHtml);
        self::assertMatchesRegularExpression('#id="pergunta-\d+" data-bs-parent="\#perguntas"><div class="accordion-body">\S#', $faqHtml, 'a resposta está no HTML estático');
        // Nada fica escondido por estilo embutido; só o CSS com html.js esconde, e o JS próprio é quem marca a classe.
        $css = (string) file_get_contents($this->caminhos->peles() . '/basica/assets/pele.css');
        self::assertStringContainsString('.js .topo-nav.collapse:not(.show)', $css);
        self::assertStringContainsString('prefers-reduced-motion:reduce', $css);
        $semJs = (string) preg_replace('#\.js [^{}]*\{[^{}]*\}#', '', $css);
        self::assertDoesNotMatchRegularExpression('#[^{}]*\.(collapse|carousel-item|accordion-collapse)[^{}]*\{[^}]*display:none#', $semJs, 'nenhuma regra esconde conteúdo sem html.js');
        $marcador = (string) file_get_contents($this->caminhos->peles() . '/basica/assets/pbl-js.js');
        self::assertStringContainsString("documentElement.className += ' js'", $marcador);
        $pele = (string) file_get_contents($this->caminhos->peles() . '/basica/assets/pbl-pele.js');
        self::assertStringContainsString('prefers-reduced-motion', $pele);
        self::assertStringNotContainsString('innerHTML', $pele);
        // Ações por título (barra de edição): cada item listado leva o id e um espaço vazio e oculto para os botões; o do <body> não conta.
        foreach (['index', 'onepage', 'portal', 'noticias', 'perguntas-frequentes'] as $url) {
            $p = (string) file_get_contents($dir . '/' . $url . '.html');
            $marcas = preg_match_all('#\sdata-pbl-objeto="[1-9][0-9]*"#', $p);
            $espacos = substr_count($p, '<span class="pbl-acoes" hidden></span>');
            self::assertGreaterThan(1, $marcas, $url);
            self::assertSame($marcas - 1, $espacos, "{$url}: um espaço de ações por item listado");
        }
        // Em onepage e portal, o hero usa um tamanho de miniatura que existe (antes o src saía vazio).
        foreach (['onepage', 'portal'] as $url) {
            $p = (string) file_get_contents($dir . '/' . $url . '.html');
            self::assertStringNotContainsString('src=""', $p, $url);
            self::assertStringNotContainsString(' style=', $p, $url);
        }
    }

    public function testImagensDaDemoSaoGrandesESemTexto(): void
    {
        $t = new CommandTester(new DemoPopularComando($this->caminhos));
        self::assertSame(0, $t->execute(['--site' => (string) $this->siteId, '--sem-publicar' => true]), $t->getDisplay());
        $linha = $this->s->conexao()->fetchAssociative("SELECT largura, altura FROM arquivo WHERE nome = 'abertas-inscricoes-oficinas.png'");
        self::assertIsArray($linha);
        $info = [(int) $linha['largura'], (int) $linha['altura']];
        self::assertSame([1200, 630], $info);
    }

    public function testHomeGenericaSemLinksNaoTemBlocoDeAtalhos(): void
    {
        $inicio = $this->pagina('index');
        self::assertStringNotContainsString('class="atalhos"', $inicio);
        self::assertStringNotContainsString('Ver versão', $inicio);
        self::assertStringNotContainsString('class="links-rapidos"', $inicio);
        self::assertStringContainsString('class="hero"', $inicio);
    }
}
