<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use Pbl\Consulta\MotorDeConsulta;
use Pbl\Conteudo\Classe;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Linguagem\Compilador;
use Pbl\Linguagem\ErroSintaxe;
use Pbl\Linguagem\Runtime;
use Pbl\Publicacao\FonteRepositorio;
use PHPUnit\Framework\TestCase;

/** `#autor.titulo`, `expandir`, ordenar e filtrar pelo objeto referenciado e `#temfilhos`, dentro de moldes, com banco de verdade. */
final class ReferenciasNoMoldeTest extends TestCase
{
    private Connection $con;
    private FonteRepositorio $fonte;
    private string $cache;
    private Objeto $raiz;

    protected function setUp(): void
    {
        $this->cache = Ajuda::pastaTemporaria();
        $this->con = Conexao::memoria();
        (new Migrador($this->con))->migrar();
        $classes = new ClasseRepositorio($this->con);
        $objetos = new ObjetoRepositorio($this->con);
        $this->fonte = new FonteRepositorio($objetos, $classes, new MotorDeConsulta($this->con, $objetos));
        $pagina = $classes->criar('pagina', []);
        $autor = $classes->criar('autor', [['nome' => 'cargo', 'tipo' => 'texto', 'indexavel' => true]]);
        $noticia = $classes->criar('noticia', [['nome' => 'autor', 'tipo' => 'ref', 'classe' => 'autor']]);

        $this->raiz = $objetos->criar(1, $pagina, null, 'Portal', '', []);
        $ana = $objetos->criar(1, $autor, $this->raiz->id, 'Ana', 'ana', ['cargo' => 'editora']);
        $caio = $objetos->criar(1, $autor, $this->raiz->id, 'Caio', 'caio', ['cargo' => 'repórter']);
        $objetos->criar(1, $noticia, $this->raiz->id, 'Obra', 'obra', ['autor' => $ana->id], 'publicado', ['peso' => 2]);
        $objetos->criar(1, $noticia, $this->raiz->id, 'Chuva', 'chuva', ['autor' => $caio->id], 'publicado', ['peso' => 1]);
        $objetos->criar(1, $noticia, $this->raiz->id, 'Solta', 'solta', [], 'publicado', ['peso' => 3]);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->cache);
    }

    private function render(string $molde, ?Objeto $objeto = null): string
    {
        $contexto = $this->fonte->contextoDe($objeto ?? $this->raiz);

        return ((new Compilador($this->cache))->carregar($molde))($contexto, new Runtime($this->fonte));
    }

    public function testCampoDoObjetoReferenciadoNoMoldeComELazyEComExpandir(): void
    {
        $esperado = 'Chuva:Caio;Obra:Ana;Solta:;';

        self::assertSame($esperado, $this->render('<@filhos classes="noticia"@><@eco #titulo@>:<@eco #autor.titulo@>;<@/filhos@>'), 'busca o autor sob demanda');
        self::assertSame($esperado, $this->render('<@filhos classes="noticia" expandir="autor"@><@eco #titulo@>:<@eco #autor.titulo@>;<@/filhos@>'), 'carga em lote');
        self::assertSame('xeditora', $this->render('<@localizar classes="noticia" ordem="-peso" limite=1 expandir="autor"@>x<@/localizar@><@localizar classes="autor" campo="cargo" valor="editora"@><@eco #cargo@><@/localizar@>'));
    }

    public function testExpandirTrazAsReferenciasEmUmaConsultaPorPropriedade(): void
    {
        $com = $this->fonte->filhos(['id' => $this->raiz->id, 'site' => 1], ['classes' => 'noticia', 'expandir' => 'autor']);
        $sem = $this->fonte->filhos(['id' => $this->raiz->id, 'site' => 1], ['classes' => 'noticia']);

        self::assertArrayHasKey('autor.', $com[0]);
        self::assertSame('Caio', $com[0]['autor.']['titulo']);
        self::assertSame('repórter', $com[0]['autor.']['cargo']);
        self::assertArrayNotHasKey('autor.', $com[2], 'notícia sem autor não ganha referência');
        self::assertArrayNotHasKey('autor.', $sem[0], 'sem expandir, a referência só é buscada se o molde usar');
    }

    public function testOrdenarEFiltrarPeloAutorDentroDoMolde(): void
    {
        self::assertSame('Obra,Chuva,Solta,', $this->render('<@localizar classes="noticia" ordem="autor.titulo"@><@eco #titulo@>,<@/localizar@>'));
        self::assertSame('Chuva,', $this->render('<@localizar classes="noticia" campo="autor.cargo" valor="repórter"@><@eco #titulo@>,<@/localizar@>'));
        self::assertSame('Obra,', $this->render('<@localizar classes="noticia" campo="autor.titulo" op="contem" valor="an"@><@eco #titulo@>,<@/localizar@>'));
        self::assertSame('Obra,Solta,', $this->render('<@localizar classes="noticia" campo="peso" op=">" valor=1 ordem="peso"@><@eco #titulo@>,<@/localizar@>'));
    }

    public function testConsultaMalFormadaDevolveListaVaziaNuncaErroDeSql(): void
    {
        self::assertSame('', $this->render('<@localizar classes="noticia" campo="autor.cargo.x" valor="a"@>x<@/localizar@>'));
        self::assertSame('', $this->render('<@localizar classes="noticia" campo="titulo" op="LIKE" valor="a"@>x<@/localizar@>'));
        self::assertSame('', $this->render('<@localizar classes="noticia" campo="x; DROP TABLE objeto" valor="a"@>x<@/localizar@>'));
        self::assertSame(3, (int) $this->con->fetchOne("SELECT COUNT(*) FROM objeto WHERE classe_id = (SELECT id FROM classe WHERE nome = 'noticia')"));
    }

    public function testTemfilhosNoContextoDoObjetoECadaItemDaLista(): void
    {
        self::assertSame('1', $this->render('<@eco #temfilhos@>'));
        self::assertSame('0', $this->render('<@filhos limite=1 classes="noticia"@><@eco #temfilhos@><@/filhos@>'));
        self::assertSame('0,0,', $this->render('<@filhos classes="autor"@><@eco #temfilhos@>,<@/filhos@>'));
        self::assertSame('sim', $this->render('<@se [#temfilhos==1]@>sim<@senao@>nao<@/se@>'));
    }

    public function testPontoContinuaSendoConcatenacaoQuandoNaoColadoALetra(): void
    {
        self::assertSame('Portal!', $this->render('<@eco #titulo . "!"@>'));
        self::assertSame('Portal!', $this->render('<@eco #titulo."!"@>'));
        self::assertSame('Portal-Portal', $this->render('<@eco #titulo . "-" . #titulo@>'));
        self::assertSame('Portal!', $this->render('<@var $s = "!"@><@eco #titulo.$s@>'));
    }

    public function testSoUmNivelDeReferenciaNoMolde(): void
    {
        $this->expectException(ErroSintaxe::class);
        (new Compilador($this->cache))->compilar('<@eco #a.b.c@>');
    }
}
