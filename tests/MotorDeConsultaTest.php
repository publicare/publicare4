<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use Pbl\Consulta\Consulta;
use Pbl\Consulta\Filtro;
use Pbl\Consulta\MotorDeConsulta;
use Pbl\Conteudo\Classe;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\ObjetoRepositorio;
use PHPUnit\Framework\TestCase;

/** Tipos de propriedade (data, booleano, ref) e o motor único: filtro e ordem pelo objeto referenciado, sem tabela temporária. */
final class MotorDeConsultaTest extends TestCase
{
    private Connection $con;
    private ObjetoRepositorio $objetos;
    private MotorDeConsulta $motor;
    private Classe $autor;
    private Classe $noticia;
    /** @var array<string, Objeto> */
    private array $a = [];
    /** @var array<string, Objeto> */
    private array $n = [];

    protected function setUp(): void
    {
        $this->con = Conexao::memoria();
        (new Migrador($this->con))->migrar();
        $classes = new ClasseRepositorio($this->con);
        $this->objetos = new ObjetoRepositorio($this->con);
        $this->motor = new MotorDeConsulta($this->con, $this->objetos);
        $this->autor = $classes->criar('autor', [
            ['nome' => 'cargo', 'tipo' => 'texto', 'indexavel' => true],
            ['nome' => 'ativo', 'tipo' => 'booleano', 'indexavel' => true],
        ]);
        $this->noticia = $classes->criar('noticia', [
            ['nome' => 'autor', 'tipo' => 'ref', 'classe' => 'autor'],
            ['nome' => 'publicada_em', 'tipo' => 'data', 'indexavel' => true],
            ['nome' => 'leituras', 'tipo' => 'numero', 'indexavel' => true],
        ]);

        // site 1: três autores e quatro notícias; site 2 tem um autor e uma notícia
        $this->a['ana'] = $this->objetos->criar(1, $this->autor, null, 'Ana Souza', 'ana', ['cargo' => 'editora', 'ativo' => true]);
        $this->a['bia'] = $this->objetos->criar(1, $this->autor, null, 'Bia Lima', 'bia', ['cargo' => 'repórter', 'ativo' => true]);
        $this->a['caio'] = $this->objetos->criar(1, $this->autor, null, 'Caio Reis', 'caio', ['cargo' => 'repórter', 'ativo' => false]);
        $this->n['n1'] = $this->objetos->criar(1, $this->noticia, null, 'Chuva forte', 'chuva', ['autor' => $this->a['caio']->id, 'publicada_em' => '2026-03-01', 'leituras' => 50]);
        $this->n['n2'] = $this->objetos->criar(1, $this->noticia, null, 'Obra no centro', 'obra', ['autor' => $this->a['ana']->id, 'publicada_em' => '2026-05-10', 'leituras' => 120]);
        $this->n['n3'] = $this->objetos->criar(1, $this->noticia, null, 'Festa junina', 'festa', ['autor' => $this->a['bia']->id, 'publicada_em' => '2026-06-20 18:00:00', 'leituras' => 80]);
        $this->n['n4'] = $this->objetos->criar(1, $this->noticia, null, 'Sem autor', 'sem-autor', ['publicada_em' => '2026-01-05']);
        $outro = $this->objetos->criar(2, $this->autor, null, 'Zé de Outro Site', 'ze', ['cargo' => 'editor']);
        $this->objetos->criar(2, $this->noticia, null, 'Do outro site', 'outra', ['autor' => $outro->id]);
    }

    /**
     * @param list<Filtro> $filtros
     * @return list<string>
     */
    private function titulos(array $filtros = [], string $ordem = '', int $site = 1, ?Classe $classe = null, bool $visiveis = true): array
    {
        $c = new Consulta(siteId: $site, classeIds: $classe === null ? null : [$classe->id], filtros: $filtros, ordem: $ordem, apenasVisiveis: $visiveis);

        return array_map(static fn (Objeto $o): string => $o->titulo, $this->motor->buscar($c));
    }

    public function testTiposSaoValidadosENormalizados(): void
    {
        self::assertSame(1, $this->a['ana']->dados['ativo'], 'booleano vira 0/1');
        $this->expectException(\InvalidArgumentException::class);
        $this->objetos->criar(1, $this->autor, null, 'Ruim', 'ruim', ['ativo' => 'talvez']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function dadosInvalidos(): iterable
    {
        yield 'data impossível' => [['publicada_em' => '2026-02-31']];
        yield 'data em formato errado' => [['publicada_em' => '31/12/2026']];
        yield 'data não texto' => [['publicada_em' => 20260101]];
        yield 'ref para objeto que não existe' => [['autor' => 999999]];
        yield 'ref não numérica' => [['autor' => 'ana']];
        yield 'ref zero' => [['autor' => 0]];
        yield 'número como texto' => [['leituras' => '12']];
    }

    /** @param array<string, mixed> $dados */
    #[\PHPUnit\Framework\Attributes\DataProvider('dadosInvalidos')]
    public function testDadosInvalidosSaoRecusados(array $dados): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->objetos->criar(1, $this->noticia, null, 'X', 'x-' . uniqid(), $dados);
    }

    public function testReferenciaSoParaObjetoDoMesmoSiteEDaClasseIndicada(): void
    {
        $outroSite = $this->motor->buscar(new Consulta(siteId: 2, classeIds: [$this->autor->id]))[0];
        try {
            $this->objetos->criar(1, $this->noticia, null, 'Vaza site', 'vaza', ['autor' => $outroSite->id]);
            self::fail('aceitou referência a objeto de outro site');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('não existe neste site', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('da classe autor');
        $this->objetos->criar(1, $this->noticia, null, 'Alvo errado', 'errado', ['autor' => $this->n['n1']->id]);
    }

    public function testClasseRefExigeClasseExistenteEFicaSempreIndexada(): void
    {
        $classes = new ClasseRepositorio($this->con);
        $c = $classes->criar('livro', [['nome' => 'editora', 'tipo' => 'ref']]);

        self::assertTrue($c->propriedades[0]['indexavel']);
        $this->expectException(\InvalidArgumentException::class);
        $classes->criar('livro2', [['nome' => 'editora', 'tipo' => 'ref', 'classe' => 'nao_existe']]);
    }

    public function testOrdenaPeloCampoDoObjetoReferenciadoSemTabelaTemporariaENulosPorUltimo(): void
    {
        self::assertSame(['Obra no centro', 'Festa junina', 'Chuva forte', 'Sem autor'], $this->titulos([], 'autor.titulo', 1, $this->noticia), 'Ana, Bia, Caio; sem autor por último');
        self::assertSame(['Chuva forte', 'Festa junina', 'Obra no centro', 'Sem autor'], $this->titulos([], '-autor.titulo', 1, $this->noticia), 'nulo continua por último no decrescente');
        self::assertSame(['Obra no centro', 'Festa junina', 'Chuva forte', 'Sem autor'], $this->titulos([], 'autor.cargo, autor.titulo', 1, $this->noticia), 'cargo (editora antes de repórter), depois nome');
        self::assertSame(['Sem autor'], array_slice($this->titulos([], 'leituras', 1, $this->noticia), -1), 'propriedade nula também por último');
        self::assertSame([], $this->con->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table' AND (name LIKE 'tmp%' OR name LIKE '%temp%')"), 'nenhuma tabela temporária criada');
    }

    public function testFiltraPeloCampoDoObjetoReferenciado(): void
    {
        self::assertSame(['Festa junina', 'Chuva forte'], $this->titulos([new Filtro('autor.cargo', '=', 'repórter')], '-leituras', 1, $this->noticia));
        self::assertSame(['Obra no centro'], $this->titulos([new Filtro('autor.cargo', '=', 'editora')], '', 1, $this->noticia));
        self::assertSame(['Chuva forte'], $this->titulos([new Filtro('autor.ativo', '=', '0')], '', 1, $this->noticia));
        self::assertSame(['Obra no centro'], $this->titulos([new Filtro('autor.titulo', 'contem', 'souza')], '', 1, $this->noticia), 'campo do sistema do referenciado');
        self::assertSame([], $this->titulos([new Filtro('autor.cargo', '=', 'repórter'), new Filtro('autor.ativo', '=', '1'), new Filtro('leituras', '>', '100')], '', 1, $this->noticia));
    }

    public function testReferenciaNuncaCruzaDeSite(): void
    {
        // a notícia do site 2 aponta para um autor do site 2; vista pelo site 1 não casa com nada
        self::assertSame([], $this->titulos([new Filtro('autor.cargo', '=', 'editor')], '', 1, $this->noticia));
        self::assertSame(['Do outro site'], $this->titulos([new Filtro('autor.cargo', '=', 'editor')], '', 2, $this->noticia));
    }

    public function testOperadoresDeComparacaoParaNumeroDataETexto(): void
    {
        $t = fn (string $campo, string $op, string $valor): array => $this->titulos([new Filtro($campo, $op, $valor)], 'publicada_em', 1, $this->noticia);

        self::assertSame(['Obra no centro', 'Festa junina'], $t('leituras', '>', '60'));
        self::assertSame(['Obra no centro', 'Festa junina'], $t('publicada_em', '>=', '2026-05-01'));
        self::assertSame(['Sem autor', 'Chuva forte'], $t('publicada_em', '<', '2026-04-01'));
        self::assertSame(['Chuva forte'], $t('leituras', '=', '50'));
        self::assertCount(3, $t('leituras', '!=', '50'), 'diferente de 50 (sem autor tem leituras nulas e entra)');
        self::assertSame(['Obra no centro'], $t('leituras', '=', '120.0'));
    }

    public function testContemEscapaCuringasEIgnoraMaiusculasNoTitulo(): void
    {
        $this->objetos->criar(1, $this->noticia, null, '100% garantido_já', 'garantido', []);

        self::assertSame(['100% garantido_já'], $this->titulos([new Filtro('titulo', 'contem', '100%')], '', 1, $this->noticia));
        self::assertSame(['100% garantido_já'], $this->titulos([new Filtro('titulo', 'contem', 'GARANTIDO_JÁ')], '', 1, $this->noticia));
        self::assertSame([], $this->titulos([new Filtro('titulo', 'contem', 'garan_ido')], '', 1, $this->noticia), '_ é literal');
    }

    public function testContarIgnoraJanelaEOrdem(): void
    {
        $c = new Consulta(siteId: 1, classeIds: [$this->noticia->id], limite: 2);

        self::assertCount(2, $this->motor->buscar($c));
        self::assertSame(4, $this->motor->contar($c));
        self::assertSame(1, $this->motor->contar(new Consulta(siteId: 1, classeIds: [$this->noticia->id], filtros: [new Filtro('autor.cargo', '=', 'editora')])));
    }

    public function testApenasVisiveisFalsoMostraRascunhoEVencidoParaOAdmin(): void
    {
        $this->objetos->criar(1, $this->noticia, null, 'Rascunho', 'rascunho', [], 'privado');
        $this->objetos->criar(1, $this->noticia, null, 'Vencida', 'vencida', [], 'publicado', ['data_validade' => gmdate('c', time() - 60)]);

        self::assertNotContains('Rascunho', $this->titulos([], '', 1, $this->noticia));
        self::assertNotContains('Vencida', $this->titulos([], '', 1, $this->noticia));
        self::assertContains('Rascunho', $this->titulos([], '', 1, $this->noticia, false));
        self::assertContains('Vencida', $this->titulos([], '', 1, $this->noticia, false));
    }

    public function testPorIdsEmLoteRespeitaSiteEVisibilidade(): void
    {
        $privado = $this->objetos->criar(1, $this->autor, null, 'Privado', 'priv', [], 'privado');
        $achados = $this->motor->porIds(1, [$this->a['ana']->id, $this->a['bia']->id, $privado->id, 999999, $this->a['ana']->id]);

        self::assertSame([$this->a['ana']->id, $this->a['bia']->id], array_keys($achados));
        self::assertArrayHasKey($privado->id, $this->motor->porIds(1, [$privado->id], false));
        self::assertSame([], $this->motor->porIds(2, [$this->a['ana']->id]), 'id de outro site');
    }

    public function testIdsComFilhosEmLoteEPorSite(): void
    {
        $pai = $this->objetos->criar(1, $this->autor, null, 'Pai', 'pai', []);
        $this->objetos->criar(1, $this->autor, $pai->id, 'Filho', 'filho', []);
        $vazio = $this->objetos->criar(1, $this->autor, null, 'Vazio', 'vazio', []);

        self::assertSame([$pai->id], $this->motor->idsComFilhos([$pai->id, $vazio->id], 1));
        self::assertTrue($this->motor->temFilhos($pai->id, 1));
        self::assertFalse($this->motor->temFilhos($pai->id, 2), 'site errado');
    }

    public function testCamposInvalidosVirameExcecaoNuncaSql(): void
    {
        foreach (['a.b.c', 'x; DROP TABLE objeto', 'a b'] as $campo) {
            try {
                $this->motor->buscar(new Consulta(siteId: 1, filtros: [new Filtro($campo, '=', '1')]));
                self::fail("aceitou o campo {$campo}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        // ordem inválida é ignorada (cai no padrão), nunca vira SQL
        self::assertNotEmpty($this->titulos([], "peso; DROP TABLE objeto, 'x", 1, $this->noticia));
        self::assertSame(7, (int) $this->con->fetchOne('SELECT COUNT(*) FROM objeto WHERE site_id = 1'));
        $this->expectException(\InvalidArgumentException::class);
        new Filtro('titulo', 'LIKE', 'x');
    }

    public function testLimiteDeJuncoesProtegeContraConsultaAbusiva(): void
    {
        $filtros = array_map(static fn (int $i): Filtro => new Filtro("autor.p{$i}", '=', '1'), range(1, 6));
        $this->expectException(\InvalidArgumentException::class);
        $this->motor->buscar(new Consulta(siteId: 1, filtros: $filtros, ordem: 'a.x, b.y, c.z'));
    }
}
