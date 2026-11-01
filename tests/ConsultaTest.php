<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use Pbl\Conteudo\Classe;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Publicacao\ContextoDeObjeto;
use Pbl\Publicacao\FonteRepositorio;
use PHPUnit\Framework\TestCase;

/** Os comandos de consulta do PBL4 sobre o banco de verdade (SQLite em memória). */
final class ConsultaTest extends TestCase
{
    private Connection $con;
    private ObjetoRepositorio $objetos;
    private FonteRepositorio $fonte;
    private Classe $pagina;
    private Classe $evento;
    /** @var array<string, int> */
    private array $id = [];

    protected function setUp(): void
    {
        $this->con = Conexao::memoria();
        (new Migrador($this->con))->migrar();
        $classes = new ClasseRepositorio($this->con);
        $this->objetos = new ObjetoRepositorio($this->con);
        $this->fonte = new FonteRepositorio($this->objetos, $classes);
        $this->pagina = $classes->criar('pagina', []);
        $this->evento = $classes->criar('evento', [
            ['nome' => 'local', 'tipo' => 'texto', 'indexavel' => true],
            ['nome' => 'vagas', 'tipo' => 'numero', 'indexavel' => true],
        ]);

        // site 1:  raiz > a > a1 > a1x ; raiz > b ; raiz > ev1, ev2 (eventos)
        $raiz = $this->objetos->criar(1, $this->pagina, null, 'Raiz', '', []);
        $a = $this->objetos->criar(1, $this->pagina, $raiz->id, 'Alfa', 'alfa', []);
        $a1 = $this->objetos->criar(1, $this->pagina, $a->id, 'Alfa Um', 'alfa/um', []);
        $a1x = $this->objetos->criar(1, $this->pagina, $a1->id, '100% Fundo_Teste', 'alfa/um/fundo', []);
        $b = $this->objetos->criar(1, $this->pagina, $raiz->id, 'Beta', 'beta', []);
        $e1 = $this->objetos->criar(1, $this->evento, $raiz->id, 'Seminário', 'seminario', ['local' => 'Sala 1', 'vagas' => 40]);
        $e2 = $this->objetos->criar(1, $this->evento, $raiz->id, 'Oficina', 'oficina', ['local' => 'Sala 2', 'vagas' => 15]);
        $rascunho = $this->objetos->criar(1, $this->pagina, $raiz->id, 'Rascunho', 'rascunho', [], 'privado');
        $outro = $this->objetos->criar(2, $this->pagina, null, 'Outro site', '', []);
        $this->id = [
            'raiz' => $raiz->id, 'a' => $a->id, 'a1' => $a1->id, 'a1x' => $a1x->id, 'b' => $b->id,
            'e1' => $e1->id, 'e2' => $e2->id, 'rascunho' => $rascunho->id, 'outro' => $outro->id,
        ];
    }

    /**
     * @param array<string, mixed> $filtros
     * @return list<string>
     */
    private function titulos(array $filtros, int $site = 1): array
    {
        return array_map(static fn (array $c) => (string) $c['titulo'], $this->fonte->localizar(['site' => $site], $filtros));
    }

    public function testFilhosSoPublicadosEComOrdemSegura(): void
    {
        $raiz = ['id' => $this->id['raiz'], 'site' => 1];

        $todos = array_column($this->fonte->filhos($raiz, []), 'titulo');
        self::assertSame(['Alfa', 'Beta', 'Seminário', 'Oficina'], $todos, 'rascunho não aparece');
        self::assertSame(['Seminário', 'Oficina', 'Beta', 'Alfa'], array_column($this->fonte->filhos($raiz, ['ordem' => 'titulo desc']), 'titulo'));
        self::assertSame(['Alfa', 'Beta'], array_column($this->fonte->filhos($raiz, ['classes' => 'pagina']), 'titulo'));
        self::assertSame(['Oficina', 'Seminário'], array_column($this->fonte->filhos($raiz, ['classes' => 'evento', 'ordem' => 'titulo']), 'titulo'));
        self::assertSame(['Beta'], array_column($this->fonte->filhos($raiz, ['inicio' => 1, 'limite' => 1]), 'titulo'));
        self::assertSame([], $this->fonte->filhos($raiz, ['classes' => 'inexistente']));
        // tentativa de injeção na ordem cai no padrão, sem erro
        self::assertCount(4, $this->fonte->filhos($raiz, ['ordem' => 'id; DROP TABLE objeto']));
    }

    public function testLocalizarPorSubarvoreENiveis(): void
    {
        self::assertSame(['Alfa Um', '100% Fundo_Teste'], $this->titulos(['pai' => $this->id['a']]));
        self::assertSame(['Alfa Um'], $this->titulos(['pai' => $this->id['a'], 'niveis' => 1]));
        self::assertSame(['Alfa Um', '100% Fundo_Teste'], $this->titulos(['pai' => $this->id['a'], 'niveis' => 2]));
        self::assertSame([], $this->titulos(['pai' => 999999]), 'pai inexistente');
        self::assertSame(['Outro site'], $this->titulos([], 2), 'nunca cruza de site');
        self::assertSame([], $this->titulos(['pai' => $this->id['outro']]), 'pai de outro site não vale');
    }

    public function testLocalizarPorCampoIndexadoEBusca(): void
    {
        self::assertSame(['Seminário'], $this->titulos(['classes' => 'evento', 'campo' => 'local', 'valor' => 'Sala 1']));
        self::assertSame(['Oficina'], $this->titulos(['campo' => 'vagas', 'valor' => '15']));
        self::assertSame([], $this->titulos(['campo' => 'local', 'valor' => 'Sala 3']));
        self::assertSame(['Alfa', 'Alfa Um'], $this->titulos(['busca' => 'ALFA']), 'busca sem diferenciar maiúsculas');
    }

    public function testBuscaNaoTrataCuringasComoPadrao(): void
    {
        self::assertSame(['100% Fundo_Teste'], $this->titulos(['busca' => '100%']));
        self::assertSame(['100% Fundo_Teste'], $this->titulos(['busca' => 'fundo_teste']));
        self::assertSame(['100% Fundo_Teste'], $this->titulos(['busca' => '%']), '% é literal');
        self::assertSame(['100% Fundo_Teste'], $this->titulos(['busca' => '_']), '_ é literal');
        self::assertSame([], $this->titulos(['busca' => 'al_a']), 'se _ fosse curinga casaria com Alfa');
    }

    public function testObjetoPorIdUrlTituloSoNoMesmoSiteEPublicado(): void
    {
        $ctx = ['site' => 1];

        self::assertSame('Beta', $this->fonte->objeto($ctx, (string) $this->id['b'], '', '')['titulo'] ?? null);
        self::assertSame('Alfa Um', $this->fonte->objeto($ctx, '', '/alfa/um', '')['titulo'] ?? null);
        self::assertSame('Oficina', $this->fonte->objeto($ctx, '', '', 'oficina')['titulo'] ?? null);
        self::assertNull($this->fonte->objeto($ctx, (string) $this->id['outro'], '', ''), 'outro site');
        self::assertNull($this->fonte->objeto($ctx, (string) $this->id['rascunho'], '', ''), 'rascunho');
        self::assertNull($this->fonte->objeto(['site' => 'x'], '1', '', ''));
    }

    public function testTemFilhos(): void
    {
        self::assertTrue($this->fonte->temFilhos(['id' => $this->id['a'], 'site' => 1], null));
        self::assertFalse($this->fonte->temFilhos(['id' => $this->id['b'], 'site' => 1], null));
        self::assertTrue($this->fonte->temFilhos(['id' => $this->id['b'], 'site' => 1], $this->id['raiz']));
    }

    public function testContextoExpoeSitePaiEClasse(): void
    {
        $o = $this->objetos->porId($this->id['a1']);
        self::assertNotNull($o);

        $c = ContextoDeObjeto::de($o, 'pagina');

        self::assertSame($this->id['a'], $c['pai']);
        self::assertSame(1, $c['site']);
        self::assertSame('/alfa/um', $c['url']);
    }

    public function testNomesReservadosNaoPodemSerPropriedades(): void
    {
        $classes = new ClasseRepositorio($this->con);
        foreach (ClasseRepositorio::RESERVADOS as $nome) {
            try {
                $classes->criar('x' . $nome, [['nome' => $nome, 'tipo' => 'texto']]);
                self::fail("aceitou propriedade reservada {$nome}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
