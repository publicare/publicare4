<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use Pbl\Consulta\Consulta;
use Pbl\Consulta\MotorDeConsulta;
use Pbl\Conteudo\Classe;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Conteudo\TagRepositorio;
use PHPUnit\Framework\TestCase;

/** Tags dos objetos (fase 5r, ADR 0024): normalização, vínculo, versionamento, consulta e nuvem. */
final class TagsTest extends TestCase
{
    private Connection $con;
    private ObjetoRepositorio $objetos;
    private TagRepositorio $tags;
    private Classe $classe;

    protected function setUp(): void
    {
        $this->con = Conexao::memoria();
        (new Migrador($this->con))->migrar();
        $this->objetos = new ObjetoRepositorio($this->con);
        $this->tags = new TagRepositorio($this->con);
        $this->classe = (new ClasseRepositorio($this->con))->criar('pagina', [['nome' => 'corpo', 'tipo' => 'html']]);
    }

    public function testSlugENormalizacaoDaEntrada(): void
    {
        self::assertSame('educacao-infantil', TagRepositorio::slug('  Educação   Infantil! '));
        self::assertSame('', TagRepositorio::slug('!!!'));
        // Repetida por slug, vazia e só-símbolo caem; vale a primeira grafia digitada.
        self::assertSame(['Educação', 'eventos-2026'], TagRepositorio::interpretar("Educação, educacao,, eventos-2026 ;\n Eventos 2026, !!!"));
        self::assertNull(TagRepositorio::validar(['a']));
        self::assertNotNull(TagRepositorio::validar([str_repeat('x', 41)]));
        self::assertNotNull(TagRepositorio::validar(array_map(static fn (int $i): string => "t{$i}", range(1, 21))));
    }

    public function testCriaSobDemandaReaproveitaEApagaOrfas(): void
    {
        $a = $this->objetos->criar(1, $this->classe, null, 'A', 'a', [], 'publicado', ['tags' => 'Educação, Eventos']);
        $b = $this->objetos->criar(1, $this->classe, null, 'B', 'b', [], 'publicado', ['tags' => ['educacao']]);

        self::assertSame(['Educação', 'Eventos'], $this->tags->nomesDoObjeto($a->id));
        self::assertSame(2, (int) $this->con->fetchOne('SELECT COUNT(*) FROM tag'), 'a tag do mesmo slug é reaproveitada');

        $this->objetos->atualizar($a->id, ['tags' => ['educacao']]);
        self::assertSame(1, (int) $this->con->fetchOne('SELECT COUNT(*) FROM tag'), '"eventos" ficou sem objeto e saiu');

        $this->objetos->atualizar($a->id, ['tags' => []]);
        $this->objetos->atualizar($b->id, ['tags' => []]);
        self::assertSame(0, (int) $this->con->fetchOne('SELECT COUNT(*) FROM tag'));
        self::assertSame([], $this->tags->porObjeto($a->id));
    }

    public function testTagsSaoPorSite(): void
    {
        $this->objetos->criar(1, $this->classe, null, 'A', 'a', [], 'publicado', ['tags' => 'x']);
        $this->objetos->criar(2, $this->classe, null, 'B', 'b', [], 'publicado', ['tags' => 'x']);

        self::assertSame(2, (int) $this->con->fetchOne('SELECT COUNT(*) FROM tag'));
        self::assertNotNull($this->tags->porSlug(1, 'x'));
        self::assertNull($this->tags->porSlug(3, 'x'));
    }

    public function testLimitesViramErroENadaEGravado(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->objetos->criar(1, $this->classe, null, 'A', 'a', [], 'publicado', ['tags' => str_repeat('longa', 20)]);
        } finally {
            self::assertSame(0, (int) $this->con->fetchOne('SELECT COUNT(*) FROM objeto'));
        }
    }

    public function testSemTagsNaMudancaNaoMexeEVersaoGuardaAsTags(): void
    {
        $o = $this->objetos->criar(1, $this->classe, null, 'A', 'a', [], 'publicado', ['tags' => 'um, dois']);
        $this->objetos->atualizar($o->id, ['titulo' => 'A2']);
        self::assertSame(['dois', 'um'], $this->tags->nomesDoObjeto($o->id), 'ausente = não mexe');

        $this->objetos->atualizar($o->id, ['tags' => 'tres'], 'trocou');
        self::assertSame(['tres'], $this->tags->nomesDoObjeto($o->id));

        // Voltar à versão 2 (um, dois) restaura as tags daquele momento.
        $this->objetos->restaurar($o->id, 2);
        self::assertSame(['dois', 'um'], $this->tags->nomesDoObjeto($o->id));
        // A versão 1 também as tinha.
        $this->objetos->restaurar($o->id, 1);
        self::assertCount(2, $this->tags->nomesDoObjeto($o->id));
    }

    public function testRestaurarVersaoSemTagsNaoApagaAsAtuais(): void
    {
        $o = $this->objetos->criar(1, $this->classe, null, 'A', 'a', []);
        $this->con->executeStatement("UPDATE objeto_versao SET dados = '{}' WHERE objeto_id = ?", [$o->id]); // versão "antiga", sem _tags
        $this->objetos->atualizar($o->id, ['tags' => 'nova']);
        $this->objetos->restaurar($o->id, 1);

        self::assertSame(['nova'], $this->tags->nomesDoObjeto($o->id));
    }

    public function testConsultaPorTagRespeitaPublicadoEVigencia(): void
    {
        $motor = new MotorDeConsulta($this->con, $this->objetos);
        $this->objetos->criar(1, $this->classe, null, 'Publicada', 'p', [], 'publicado', ['tags' => 'foco, outra']);
        $this->objetos->criar(1, $this->classe, null, 'Privada', 'q', [], 'privado', ['tags' => 'foco']);
        $this->objetos->criar(1, $this->classe, null, 'Vencida', 'v', [], 'publicado', ['tags' => 'foco', 'data_validade' => '2000-01-01T00:00:00+00:00']);
        $this->objetos->criar(1, $this->classe, null, 'Sem tag', 's', []);
        $this->objetos->criar(2, $this->classe, null, 'Outro site', 'o', [], 'publicado', ['tags' => 'foco']);

        $titulos = static fn (array $r): array => array_map(static fn ($x) => $x->titulo, $r);
        $consulta = new Consulta(siteId: 1, tags: ['foco']);
        self::assertSame(['Publicada'], $titulos($motor->buscar($consulta)));
        self::assertSame(['Publicada'], $titulos($motor->buscar(new Consulta(siteId: 1, tags: ['Foco', 'outra']))), 'várias tags = todas (E)');
        self::assertSame([], $titulos($motor->buscar(new Consulta(siteId: 1, tags: ['foco', 'inexistente']))));
        self::assertSame(1, $motor->contar($consulta));
    }

    public function testNuvemContaSoVisiveisEOrdenaPorUso(): void
    {
        $this->objetos->criar(1, $this->classe, null, 'A', 'a', [], 'publicado', ['tags' => 'grande, medio']);
        $this->objetos->criar(1, $this->classe, null, 'B', 'b', [], 'publicado', ['tags' => 'grande']);
        $this->objetos->criar(1, $this->classe, null, 'C', 'c', [], 'privado', ['tags' => 'so-privada']);

        $nuvem = $this->tags->nuvem(1, 10);

        self::assertSame(['grande', 'medio'], array_column($nuvem, 'slug'));
        self::assertSame([2, 1], array_column($nuvem, 'total'));
    }
}
