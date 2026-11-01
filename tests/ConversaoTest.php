<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Conversao\Aviso;
use Pbl\Conversao\Conversor;
use Pbl\Conversao\ConversorDeDiretorio;
use Pbl\Conversao\NomesDeMolde;
use Pbl\Linguagem\Compilador;
use Pbl\Linguagem\Runtime;
use Pbl\Publicacao\ContextoDeObjeto;
use Pbl\Publicacao\FonteRepositorio;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Moldes sintéticos escritos no estilo dos moldes reais do PBL3 (sem copiar nenhum). */
final class ConversaoTest extends TestCase
{
    private function converter(string $pbl3, bool $seguro = false): \Pbl\Conversao\Resultado
    {
        return (new Conversor($seguro))->converter($pbl3);
    }

    public function testParametrosEntreColchetesEMenuTipico(): void
    {
        $r = $this->converter(<<<'PBL'
<ul>
<@localizar classes=["pagina,folder"] condicao=["menu_principal=1"] ordem=['peso,-data_publicacao'] niveis=[0] pai=[1] limite=[5]@>
<li><a href="<@= $_page->config["portal"]["url"].#url @>"><@= #titulo @></a></li>
<@/localizar @>
<@naolocalizado@>vazio<@/naolocalizado@>
</ul>
PBL);

        self::assertTrue($r->valido, implode("\n", array_map(static fn (Aviso $a): string => $a->mensagem, $r->avisos)));
        self::assertStringContainsString('<@localizar classes="pagina,folder" campo="menu_principal" valor="1" ordem="peso,-data_publicacao" niveis=1 pai=1 limite=5@>', $r->saida);
        self::assertStringContainsString('<@ecocru "" . #url@>', $r->saida, 'URL absoluta do portal removida');
        self::assertStringContainsString('<@/localizar@>', $r->saida);
        self::assertSame(['menu_principal'], $r->camposIndexaveis);
        self::assertSame(0, $r->contar(Aviso::REVISAR));
    }

    public function testNiveisVirammaisUmEPaiMenosUmSome(): void
    {
        self::assertStringContainsString('niveis=3', $this->converter('<@localizar classes=["a"] niveis=[2] pai=[-1]@><@/localizar@>')->saida);
        $s = $this->converter('<@localizar classes=["a"] niveis=[-1] pai=[-1]@><@/localizar@>')->saida;
        self::assertStringNotContainsString('niveis', $s);
        self::assertStringNotContainsString('pai=', $s);
    }

    public function testCamposRenomeadosEStatus(): void
    {
        $r = $this->converter('<@= #cod_objeto@><@= #cod_pai@><@se [#cod_status!=2]@>x<@senao@>y<@/se@><@se [#cod_status==4]@>z<@\se@>');

        self::assertStringContainsString('<@ecocru #id@>', $r->saida);
        self::assertStringContainsString('<@ecocru #pai@>', $r->saida);
        self::assertStringContainsString('<@se [#status != "publicado"]@>', $r->saida);
        self::assertStringContainsString('<@se [#status == "submetido"]@>', $r->saida);
        self::assertSame(2, substr_count($r->saida, '<@/se@>'), 'fechamento \\se vira /se');
        self::assertTrue($r->valido);
    }

    public function testEcoEcoeEModoSeguro(): void
    {
        $pbl3 = '<@eco #a@><@= #b@><@ecoe #c@>';

        self::assertSame('<@ecocru #a@><@ecocru #b@><@eco #c@>', $this->converter($pbl3)->saida);
        self::assertSame('<@eco #a@><@eco #b@><@eco #c@>', $this->converter($pbl3, true)->saida);
        $avisos = array_map(static fn (Aviso $a): string => $a->mensagem, $this->converter($pbl3)->avisos);
        self::assertContains('2 saída(s) convertida(s) para ecocru (sem escape, como no PBL3); use o modo seguro para escapar HTML', $avisos);
    }

    public function testCortaTextoVirouEcoLimiteEVarEUsarObjeto(): void
    {
        $r = $this->converter('<@= cortaTexto(#texto, 200) @><@var $url=#url_amigavel @><@usarobjeto cod_objeto=[#cod_pai] @><@eco #titulo@><@/usarobjeto @><@temfilho cod_objeto=[#cod_objeto]@>t<@/temfilho@>');

        self::assertStringContainsString('<@eco_limite texto=#texto limite=200@>', $r->saida);
        self::assertStringContainsString('<@var $url = #url_amigavel@>', $r->saida);
        self::assertStringContainsString('<@usarobjeto id=#pai@>', $r->saida);
        self::assertStringContainsString('<@temfilho id=#id@>', $r->saida);
        self::assertTrue($r->valido);
    }

    public function testIncluirVirouNomeDeMoldeERegistraDependencia(): void
    {
        $r = $this->converter("<@incluir arquivo=['/html/include/inc_caminho.php']@><@incluir arquivo=[\"/html/include/Menu Principal.php\"]@>");

        self::assertSame(['include/inc_caminho', 'include/menu_principal'], $r->incluidos);
        self::assertStringContainsString('<@incluir arquivo="include/inc_caminho"@>', $r->saida);
    }

    public function testPhpCruEComandosNaoPortadosViramComentarioComRevisar(): void
    {
        $r = $this->converter(<<<'PBL'
<?php require_once("x.php"); $a = 1; ?>
<p>ok</p>
<@usarblob nome=["conteudo"] @><img src="<@srcblob@>"><@/usarblob@>
<@incluimenu@>
<@executar objeto=["agenda"] parametros=["a"]@>
<@= $_page->config["outro"]["valor"] @>
<@exec ls@>
PBL);

        self::assertStringNotContainsString('<?', $r->saida);
        self::assertStringContainsString('<p>ok</p>', $r->saida);
        self::assertStringContainsString('<@-- REVISAR: PHP removido', $r->saida);
        self::assertTrue($r->valido, 'o resultado compila no PBL4');
        self::assertGreaterThanOrEqual(7, $r->contar(Aviso::REVISAR));
        $mensagens = implode("\n", array_map(static fn (Aviso $a): string => $a->mensagem, $r->avisos));
        self::assertStringContainsString('executar não portado', $mensagens);
        self::assertStringContainsString('dependem do upload', $mensagens);
    }

    public function testCondicaoComplexaLikeETagsNaoSaemEmSilencio(): void
    {
        $r = $this->converter('<@localizar classes=["a"] condicao=["x=1 and y=2"] like=["titulo","%a%"] tags=["z"]@><@/localizar@>');

        self::assertTrue($r->valido);
        self::assertSame(3, $r->contar(Aviso::REVISAR));
        self::assertStringContainsString('<@-- REVISAR:', $r->saida);
        self::assertStringContainsString('<@localizar classes="a"@>', $r->saida);
    }

    public function testApostrofoEArrobaDentroDeComentarioNaoQuebram(): void
    {
        $r = $this->converter('<@incluimenu@><@= #t@>');

        self::assertTrue($r->valido);
        self::assertSame(1, preg_match('/^<@-- REVISAR.*--@><@ecocru #t@>$/s', $r->saida));
    }

    public function testArquivoLatin1EConvertidoParaUtf8(): void
    {
        $r = $this->converter(mb_convert_encoding("<p>Ação</p><@= #t@>", 'ISO-8859-1', 'UTF-8'));

        self::assertStringContainsString('<p>Ação</p>', $r->saida);
        self::assertTrue(mb_check_encoding($r->saida, 'UTF-8'));
    }

    public function testTagNaoFechadaEMarcadaComoErro(): void
    {
        $r = $this->converter("a\n<@eco #titulo");

        self::assertFalse($r->valido);
        self::assertGreaterThanOrEqual(1, $r->contar(Aviso::ERRO));
    }

    /** @return iterable<string, array{string, string}> */
    public static function nomes(): iterable
    {
        yield 'html/include' => ['/html/include/inc_menu.php', 'include/inc_menu'];
        yield 'public_html/html' => ['source/public_html/html/template/view_basic.php', 'template/view_basic'];
        yield 'sem html' => ['pele/topo.pbl', 'pele/topo'];
        yield 'maiúsculas e espaços' => ['/html/Include/Menu Principal.PHP', 'include/menu_principal'];
        yield 'traversal' => ['/html/../../etc/passwd', 'etc/passwd'];
        yield 'só ponto' => ['/../..', 'molde'];
    }

    #[DataProvider('nomes')]
    public function testNomesDeMoldeSaoSeguros(string $entrada, string $esperado): void
    {
        $nome = NomesDeMolde::de($entrada);

        self::assertSame($esperado, $nome);
        self::assertSame(1, preg_match('/^[a-z0-9_]+(?:\/[a-z0-9_]+)*$/', $nome));
    }

    public function testMoldeConvertidoRenderizaNoPbl4ComOMesmoResultadoEsperado(): void
    {
        $con = Conexao::memoria();
        (new Migrador($con))->migrar();
        $classes = new ClasseRepositorio($con);
        $objetos = new ObjetoRepositorio($con);
        $pagina = $classes->criar('pagina', [['nome' => 'menu_principal', 'tipo' => 'numero', 'indexavel' => true]]);
        $raiz = $objetos->criar(1, $pagina, null, 'Raiz', '', []);
        $objetos->criar(1, $pagina, $raiz->id, 'Contato', 'contato', ['menu_principal' => 1], 'publicado', ['peso' => 20]);
        $objetos->criar(1, $pagina, $raiz->id, 'Início', 'inicio', ['menu_principal' => 1], 'publicado', ['peso' => 10]);
        $objetos->criar(1, $pagina, $raiz->id, 'Escondida', 'escondida', ['menu_principal' => 0], 'publicado', ['peso' => 5]);
        $objetos->criar(1, $pagina, $raiz->id, 'Rascunho', 'rascunho', ['menu_principal' => 1], 'privado', ['peso' => 1]);

        $pbl3 = '<ul><@localizar classes=["pagina"] condicao=["menu_principal=1"] ordem=["peso"] niveis=[0] pai=[' . $raiz->id . ']@><li><a href="<@= $_page->config["portal"]["url"].#url @>"><@= #titulo @></a></li><@/localizar@></ul>';
        $r = $this->converter($pbl3);
        self::assertTrue($r->valido);

        $pasta = Ajuda::pastaTemporaria();
        try {
            $fonte = new FonteRepositorio($objetos, $classes);
            $html = ((new Compilador($pasta))->carregar($r->saida))(ContextoDeObjeto::de($raiz, 'pagina'), new Runtime($fonte));

            self::assertSame('<ul><li><a href="/inicio">Início</a></li><li><a href="/contato">Contato</a></li></ul>', $html);
        } finally {
            Ajuda::apagar($pasta);
        }
    }

    public function testConverterDiretorioGeraMoldesRelatorioEDetectaFaltantes(): void
    {
        $origem = Ajuda::pastaTemporaria();
        $destino = Ajuda::pastaTemporaria();
        try {
            mkdir("{$origem}/site/html/template", 0775, true);
            mkdir("{$origem}/site/html/include", 0775, true);
            mkdir("{$origem}/site/vendor/x", 0775, true);
            file_put_contents("{$origem}/site/html/template/view_basic.php", '<@incluir arquivo=["/html/include/inc_topo.php"]@><@incluir arquivo=["/html/include/some.php"]@><@= #titulo@>');
            file_put_contents("{$origem}/site/html/include/inc_topo.php", '<?php echo 1; ?><h1><@= #titulo@></h1>');
            file_put_contents("{$origem}/site/html/include/sem_tags.php", '<?php echo "so php";');
            file_put_contents("{$origem}/site/html/include/some.php", '<div>html puro incluido</div>');
            file_put_contents("{$origem}/site/html/include/nunca_incluido.php", '<p>ninguem inclui</p>');
            file_put_contents("{$origem}/site/vendor/x/lib.php", '<@= #nao_converter@>');

            $r = (new ConversorDeDiretorio())->converter($origem, $destino);

            self::assertCount(3, $r['arquivos'], 'inclui o molde sem tags que alguém incluiu');
            self::assertSame(2, $r['ignorados'], 'sem tags e sem ninguém incluir: sem_tags e nunca_incluido');
            self::assertSame([], $r['faltando']);
            self::assertSame('<div>html puro incluido</div>', file_get_contents("{$destino}/include/some.pbl"));
            self::assertFileExists("{$destino}/template/view_basic.pbl");
            self::assertFileExists("{$destino}/include/inc_topo.pbl");
            self::assertFileDoesNotExist("{$destino}/x/lib.pbl");
            self::assertStringContainsString('<@incluir arquivo="include/inc_topo"@>', (string) file_get_contents("{$destino}/template/view_basic.pbl"));
            $relatorio = (string) file_get_contents("{$destino}/RELATORIO.md");
            self::assertStringContainsString('Moldes convertidos: **3**', $relatorio);
            self::assertStringContainsString('PHP removido', $relatorio);
        } finally {
            Ajuda::apagar($origem);
            Ajuda::apagar($destino);
        }
    }

    public function testIncluidoQueNaoExisteNaOrigemFicaListadoComoFaltante(): void
    {
        $origem = Ajuda::pastaTemporaria();
        $destino = Ajuda::pastaTemporaria();
        try {
            file_put_contents("{$origem}/a.php", '<@incluir arquivo=["/html/include/fantasma.php"]@>');

            $r = (new ConversorDeDiretorio())->converter($origem, $destino);

            self::assertSame(['include/fantasma'], $r['faltando']);
            self::assertStringContainsString('`include/fantasma`', $r['relatorio']);
        } finally {
            Ajuda::apagar($origem);
            Ajuda::apagar($destino);
        }
    }

    public function testNomesRepetidosGanhamSufixoEOrigemNaoExiste(): void
    {
        $origem = Ajuda::pastaTemporaria();
        $destino = Ajuda::pastaTemporaria();
        try {
            mkdir("{$origem}/a/html", 0775, true);
            mkdir("{$origem}/b/html", 0775, true);
            file_put_contents("{$origem}/a/html/topo.php", '<@= #a@>');
            file_put_contents("{$origem}/b/html/topo.php", '<@= #b@>');

            $r = (new ConversorDeDiretorio())->converter($origem, $destino);

            self::assertSame(['topo', 'topo_2'], array_column($r['arquivos'], 'molde'));
        } finally {
            Ajuda::apagar($origem);
            Ajuda::apagar($destino);
        }
        $this->expectException(\InvalidArgumentException::class);
        (new ConversorDeDiretorio())->converter('/nao/existe/mesmo', sys_get_temp_dir());
    }
}
