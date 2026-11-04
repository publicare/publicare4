<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Doctrine\DBAL\Connection;
use Pbl\Aplicacao;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migracoes\M016ClassesFixas;
use Pbl\Banco\Migrador;
use Pbl\Caminhos;
use Pbl\Conteudo\ClassesPadrao;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\Site;
use Pbl\Conteudo\ViolacaoDeRegra;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** Fase 5l (ADR 0015): pacote mínimo, classes fixas, regras do link, migração 16 e metatags de SEO da pele básica. */
final class ClassesPadraoTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;
    private Site $site;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Meu Site', 'www.exemplo.org');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $this->site = $this->s->sites->porHost('www.exemplo.org') ?? self::fail();
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function obj(string $url): Objeto
    {
        return $this->s->objetos->porUrl($this->site->id, $url) ?? self::fail("sem objeto {$url}");
    }

    private function classe(string $nome): \Pbl\Conteudo\Classe
    {
        return $this->s->classes->porNome($nome) ?? self::fail("sem classe {$nome}");
    }

    private function pagina(string $url): string
    {
        foreach ($this->s->sites->todos() as $site) {
            $this->s->publicador->publicarSite($site);
        }
        $r = (new Aplicacao($this->caminhos))->tratar(Request::create("http://www.exemplo.org{$url}"));
        self::assertSame(200, $r->getStatusCode());

        return (string) $r->getContent();
    }

    public function testInstaladorCriaClassesEConteudoDeExemplo(): void
    {
        foreach (ClassesPadrao::FIXAS as $nome) {
            self::assertTrue($this->classe($nome)->fixa, $nome);
        }
        self::assertFalse($this->classe('noticia')->fixa);
        self::assertFalse($this->classe('mensagem_contato')->fixa);

        $inicio = $this->s->objetos->porId((int) $this->site->objetoRaizId) ?? self::fail();
        self::assertSame('pagina', $this->classe('pagina')->nome);
        self::assertSame('publicado', $this->obj('sobre')->status);
        self::assertSame('publicado', $this->obj('noticias')->status);
        $noticia = $this->obj('bem-vindo-ao-novo-site');
        self::assertSame($this->obj('noticias')->id, $noticia->paiId);
        self::assertSame('publicado', $this->obj('contato')->status);
        self::assertSame($inicio->id, $this->obj('contato')->paiId);
        $caixa = $this->obj('mensagens-recebidas');
        self::assertSame('privado', $caixa->status);
        self::assertSame($caixa->id, $this->obj('contato')->dados['destino']);
    }

    public function testCamposDeSeoExistemEmPastaPaginaENoticia(): void
    {
        foreach (['pasta', 'pagina', 'noticia'] as $nome) {
            $propriedades = [];
            foreach ($this->classe($nome)->propriedades as $p) {
                $propriedades[$p['nome']] = $p['tipo'];
            }
            self::assertSame('texto', $propriedades['seo_titulo'] ?? null, $nome);
            self::assertSame('texto', $propriedades['seo_descricao'] ?? null, $nome);
            self::assertSame('imagem', $propriedades['seo_imagem'] ?? null, $nome);
            self::assertSame('booleano', $propriedades['seo_nao_indexar'] ?? null, $nome);
        }
    }

    public function testClasseFixaNaoApagaMasNaoFixaApaga(): void
    {
        foreach (ClassesPadrao::FIXAS as $nome) {
            $c = $this->classe($nome);
            try {
                $this->s->classes->apagar($c->id, $c->versao);
                self::fail("{$nome} foi apagada");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('fixa', $e->getMessage());
            }
            self::assertNotNull($this->s->classes->porNome($nome));
        }
        $livre = $this->s->classes->criar('evento', [['nome' => 'local', 'tipo' => 'texto']]);
        $this->s->classes->apagar($livre->id, $livre->versao);
        self::assertNull($this->s->classes->porNome('evento'));

        $mensagem = $this->classe('mensagem_contato');
        $this->s->classes->apagar($mensagem->id, $mensagem->versao);
        self::assertNull($this->s->classes->porNome('mensagem_contato'));
    }

    public function testClasseFixaNaoPerdeBaseMasAceitaPropriedadeNova(): void
    {
        $pagina = $this->classe('pagina');
        $semCorpo = array_values(array_filter($pagina->propriedades, static fn (array $p): bool => $p['nome'] !== 'corpo'));
        try {
            $this->s->classes->atualizar($pagina->id, $pagina->versao, $semCorpo, ['descricao' => '', 'aceita_filhos' => true, 'filhas' => []]);
            self::fail('removeu a base');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('corpo', $e->getMessage());
        }
        $arquivada = array_map(static fn (array $p): array => $p['nome'] === 'resumo' ? $p + ['arquivada' => true] : $p, $pagina->propriedades);
        try {
            $this->s->classes->atualizar($pagina->id, $pagina->versao, $arquivada, ['descricao' => '', 'aceita_filhos' => true, 'filhas' => []]);
            self::fail('arquivou a base');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('resumo', $e->getMessage());
        }

        $nova = [...$pagina->propriedades, ['nome' => 'autoria', 'tipo' => 'texto', 'indexavel' => false]];
        $depois = $this->s->classes->atualizar($pagina->id, $pagina->versao, $nova, ['descricao' => 'Página', 'aceita_filhos' => true, 'filhas' => []]);
        self::assertTrue($depois->fixa);
        self::assertContains('autoria', array_map(static fn (array $p): string => $p['nome'], $depois->propriedades));
    }

    public function testMigracao16EIdempotenteEMarcaFixasEmBancoExistente(): void
    {
        $conexao = Conexao::sqlite($this->caminhos->banco());
        $conexao->executeStatement("UPDATE classe SET fixa = 0 WHERE nome IN ('pasta', 'link', 'formulario')");
        // Reaplica sobre a coluna já criada: não falha e remarca.
        (new M016ClassesFixas())->aplicar($conexao);
        (new M016ClassesFixas())->aplicar($conexao);
        self::assertSame(6, $this->fixas($conexao));
        self::assertSame(0, (int) $conexao->fetchOne("SELECT fixa FROM classe WHERE nome = 'noticia'"));
        self::assertSame([], (new Migrador($conexao))->migrar());
        self::assertSame(20, (new Migrador($conexao))->versaoAtual());
    }

    public function testMigracao16SobreBancoAntigoSemAsColunas(): void
    {
        $conexao = Conexao::sqlite($this->raiz . '/antigo.sqlite');
        (new Migrador($conexao))->migrar();
        $conexao->executeStatement('ALTER TABLE classe DROP COLUMN fixa');
        $conexao->executeStatement('ALTER TABLE objeto DROP COLUMN no_menu');
        $conexao->executeStatement('ALTER TABLE objeto DROP COLUMN rotulo_menu');
        $conexao->executeStatement('DELETE FROM schema_versao WHERE versao >= 16');
        self::assertSame([16, 17, 18, 19, 20], (new Migrador($conexao))->migrar());
        self::assertSame(1, $this->fixas($conexao), 'em banco novo só formulario existe na migração 11');
        self::assertSame(0, (int) $conexao->fetchOne('SELECT COUNT(*) FROM objeto WHERE no_menu = 1'));
    }

    private function fixas(Connection $conexao): int
    {
        return (int) $conexao->fetchOne('SELECT COUNT(*) FROM classe WHERE fixa = 1');
    }

    public function testLinkInternoAcompanhaAUrlDoDestinoQuandoElaMuda(): void
    {
        $this->s->objetos->criar($this->site->id, $this->classe('link'), (int) $this->site->objetoRaizId, 'Quem somos', 'quem-somos', [
            'destino_interno' => $this->obj('sobre')->id,
        ]);
        self::assertMatchesRegularExpression('~<a class="cartao-atalho" href="/sobre"><span class="titulo">Quem somos</span>~', $this->pagina('/'));

        $this->s->objetos->atualizar($this->obj('sobre')->id, ['url_amigavel' => 'a-empresa']);
        $depois = $this->pagina('/');
        self::assertMatchesRegularExpression('~<a class="cartao-atalho" href="/a-empresa"><span class="titulo">Quem somos</span>~', $depois);
        self::assertStringNotContainsString('href="/sobre"', $depois);
    }

    public function testLinkExternoComNovaAbaTemRelNoopener(): void
    {
        $this->s->objetos->criar($this->site->id, $this->classe('link'), (int) $this->site->objetoRaizId, 'Parceiro', 'parceiro', [
            'destino_externo' => 'https://parceiro.example/pagina?a=1&b=2',
            'nova_aba' => true,
        ]);
        $html = $this->pagina('/');
        self::assertStringContainsString('href="https://parceiro.example/pagina?a=1&amp;b=2"', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
        self::assertStringContainsString('target="_blank"', $html);
    }

    /** @return array<string, array{0: string}> */
    public static function enderecosInvalidos(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript em caixa mista' => ['JaVaScRiPt:alert(1)'],
            'data' => ['data:text/html;base64,PHNjcmlwdD4='],
            'sem esquema' => ['exemplo.org/pagina'],
            'http sem host' => ['http:///x'],
            'com espaco' => ['https://exemplo.org/a b'],
            'com quebra de linha' => ["https://exemplo.org/\njavascript:1"],
            'ftp' => ['ftp://exemplo.org/arquivo'],
        ];
    }

    /** @dataProvider enderecosInvalidos */
    public function testLinkExternoRejeitaEsquemasPerigosos(string $endereco): void
    {
        try {
            $this->s->objetos->criar($this->site->id, $this->classe('link'), (int) $this->site->objetoRaizId, 'Ruim', 'ruim', ['destino_externo' => $endereco]);
            self::fail('aceitou ' . $endereco);
        } catch (ViolacaoDeRegra $e) {
            self::assertSame(ViolacaoDeRegra::DESTINO_DE_LINK, $e->codigo);
            self::assertSame('destino_externo', $e->campo);
        }
    }

    public function testLinkAceitaMailtoTelEHttp(): void
    {
        foreach (['mailto:contato@exemplo.org', 'tel:+5561999999999', 'http://exemplo.org/x', 'https://exemplo.org'] as $i => $endereco) {
            $o = $this->s->objetos->criar($this->site->id, $this->classe('link'), (int) $this->site->objetoRaizId, "Ok {$i}", "ok-{$i}", ['destino_externo' => $endereco]);
            self::assertSame($endereco, $o->dados['destino_externo']);
        }
    }

    public function testLinkExigeExatamenteUmDestino(): void
    {
        $link = $this->classe('link');
        $raiz = (int) $this->site->objetoRaizId;
        try {
            $this->s->objetos->criar($this->site->id, $link, $raiz, 'Dois', 'dois', ['destino_interno' => $this->obj('sobre')->id, 'destino_externo' => 'https://exemplo.org']);
            self::fail('aceitou dois destinos');
        } catch (ViolacaoDeRegra $e) {
            self::assertSame(ViolacaoDeRegra::DESTINO_DE_LINK, $e->codigo);
        }
        try {
            $this->s->objetos->criar($this->site->id, $link, $raiz, 'Nenhum', 'nenhum', []);
            self::fail('aceitou nenhum destino');
        } catch (ViolacaoDeRegra $e) {
            self::assertSame(ViolacaoDeRegra::DESTINO_DE_LINK, $e->codigo);
        }
        // Na edição vale a mesma regra.
        $ok = $this->s->objetos->criar($this->site->id, $link, $raiz, 'Ok', 'ok', ['destino_externo' => 'https://exemplo.org']);
        $this->expectException(ViolacaoDeRegra::class);
        $this->s->objetos->atualizar($ok->id, ['dados' => ['destino_interno' => $this->obj('sobre')->id, 'destino_externo' => 'https://exemplo.org']]);
    }

    public function testLinkMaliciosoSaiEscapadoNaPele(): void
    {
        $this->s->objetos->criar($this->site->id, $this->classe('link'), (int) $this->site->objetoRaizId, '<script>alert(1)</script>', 'xss', [
            'destino_externo' => 'https://exemplo.org/"onmouseover="alert(1)',
        ]);
        $html = $this->pagina('/');
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('"onmouseover="', $html);
    }

    public function testPeleEmiteMetatagsDeSeo(): void
    {
        $html = $this->pagina('/sobre');
        self::assertStringContainsString('<title>Sobre</title>', $html);
        self::assertStringContainsString('<meta name="description" content="Sobre este site">', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://www.exemplo.org/sobre">', $html);
        self::assertStringContainsString('<meta property="og:title" content="Sobre">', $html);
        self::assertStringContainsString('<meta property="og:description" content="Sobre este site">', $html);
        self::assertStringContainsString('<meta property="og:type" content="website">', $html);
        self::assertStringNotContainsString('noindex', $html);

        $noticia = $this->pagina('/bem-vindo-ao-novo-site');
        self::assertStringContainsString('<meta property="og:type" content="article">', $noticia);
    }

    public function testCamposDeSeoTemPrioridadeENoindex(): void
    {
        $sobre = $this->obj('sobre');
        $this->s->objetos->atualizar($sobre->id, ['dados' => [...$sobre->dados,
            'seo_titulo' => 'Título para buscadores',
            'seo_descricao' => 'Descrição própria',
            'seo_nao_indexar' => true,
        ]]);
        $html = $this->pagina('/sobre');
        self::assertStringContainsString('<title>Título para buscadores</title>', $html);
        self::assertStringContainsString('<meta name="description" content="Descrição própria">', $html);
        self::assertStringContainsString('<meta name="robots" content="noindex">', $html);
        self::assertStringContainsString('<h1>Sobre</h1>', $html, 'o título da página não muda');
    }

    public function testMetatagsEscapamHtmlMalicioso(): void
    {
        $sobre = $this->obj('sobre');
        $this->s->objetos->atualizar($sobre->id, ['dados' => [...$sobre->dados,
            'seo_titulo' => '</title><script>alert(1)</script>',
            'seo_descricao' => '"><script>alert(2)</script>',
        ]]);
        $html = $this->pagina('/sobre');
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('&lt;/title&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('content="&quot;&gt;&lt;script&gt;alert(2)&lt;/script&gt;"', $html);
    }

    public function testContatoEstaNoArEAEnviarFicaPrivado(): void
    {
        $html = $this->pagina('/contato');
        self::assertStringContainsString('<form', $html);
        self::assertStringContainsString('name="nome"', $html);
    }
}
