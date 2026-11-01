<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Conteudo\SiteRepositorio;
use PHPUnit\Framework\TestCase;

final class BancoEConteudoTest extends TestCase
{
    private Connection $con;

    protected function setUp(): void
    {
        $this->con = Conexao::memoria();
        (new Migrador($this->con))->migrar();
    }

    public function testMigradorEIdempotente(): void
    {
        $m = new Migrador($this->con);

        self::assertSame([], $m->migrar());
        self::assertSame(5, $m->versaoAtual());
    }

    public function testCaminhoMaterializadoEEscopoDeSubarvore(): void
    {
        $classes = new ClasseRepositorio($this->con);
        $objetos = new ObjetoRepositorio($this->con);
        $classe = $classes->criar('pagina', [['nome' => 'corpo', 'tipo' => 'html']]);

        $raiz = $objetos->criar(1, $classe, null, 'Raiz', '', []);
        $filho = $objetos->criar(1, $classe, $raiz->id, 'Filho', 'filho', []);
        $neto = $objetos->criar(1, $classe, $filho->id, 'Neto', 'filho/neto', []);
        $outroSite = $objetos->criar(2, $classe, null, 'Outro', '', []);

        self::assertSame("/{$raiz->id}/", $raiz->caminho);
        self::assertSame("/{$raiz->id}/{$filho->id}/{$neto->id}/", $neto->caminho);

        $ids = array_map(static fn ($o) => $o->id, $objetos->publicadosDaSubarvore(1, $filho->caminho));
        self::assertSame([$filho->id, $neto->id], $ids);
        self::assertNotContains($outroSite->id, $ids);
    }

    public function testFilhosEmLoteSaoUmaConsultaPorTodosOsPais(): void
    {
        $classes = new ClasseRepositorio($this->con);
        $objetos = new ObjetoRepositorio($this->con);
        $c = $classes->criar('pagina', []);
        $a = $objetos->criar(1, $c, null, 'A', 'a', []);
        $b = $objetos->criar(1, $c, null, 'B', 'b', []);
        $objetos->criar(1, $c, $a->id, 'A1', 'a1', []);
        $objetos->criar(1, $c, $a->id, 'A2', 'a2', []);
        $objetos->criar(1, $c, $b->id, 'B1', 'b1', [], 'privado');

        $lote = $objetos->filhosEmLote([$a->id, $b->id]);

        self::assertCount(2, $lote[$a->id]);
        self::assertArrayNotHasKey($b->id, $lote, 'filho privado não aparece');
    }

    public function testValidaDadosPelaClasseEIndexaSoIndexaveis(): void
    {
        $classes = new ClasseRepositorio($this->con);
        $objetos = new ObjetoRepositorio($this->con);
        $c = $classes->criar('evento', [
            ['nome' => 'local', 'tipo' => 'texto', 'indexavel' => true],
            ['nome' => 'vagas', 'tipo' => 'numero', 'indexavel' => true],
            ['nome' => 'obs', 'tipo' => 'texto'],
        ]);

        $o = $objetos->criar(1, $c, null, 'Seminário', 'seminario', ['local' => 'Sala 1', 'vagas' => 40, 'obs' => 'x']);

        $linhas = $this->con->fetchAllAssociative('SELECT chave, texto, numero FROM objeto_indice WHERE objeto_id = ? ORDER BY chave', [$o->id]);
        self::assertSame(['local', 'vagas'], array_column($linhas, 'chave'));
        self::assertEquals(40, $linhas[1]['numero']);

        $this->expectException(\InvalidArgumentException::class);
        $objetos->criar(1, $c, null, 'Ruim', 'ruim', ['inexistente' => 'x']);
    }

    public function testRejeitaUrlComTraversalOuMaiuscula(): void
    {
        $classes = new ClasseRepositorio($this->con);
        $objetos = new ObjetoRepositorio($this->con);
        $c = $classes->criar('pagina', []);

        foreach (['../x', 'a//b', 'A', '/a', 'a/', 'a b', '.hidden'] as $url) {
            try {
                $objetos->criar(1, $c, null, 't', $url, []);
                self::fail("Aceitou URL inválida: {$url}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testHostResolveParaSite(): void
    {
        $sites = new SiteRepositorio($this->con);
        $s = $sites->criar('Meu', 'Exemplo.COM.br');

        self::assertSame($s->id, $sites->porHost('exemplo.com.br')?->id);
        self::assertNull($sites->porHost('outro.com'));
    }
}
