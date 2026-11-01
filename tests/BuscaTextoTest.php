<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Banco\Conexao;
use Pbl\Banco\Migracoes\M001Base;
use Pbl\Banco\Migracoes\M002Metadados;
use Pbl\Banco\Migracoes\M003TituloBusca;
use Pbl\Banco\Migrador;
use Pbl\Busca\Texto;
use Pbl\Consulta\Consulta;
use Pbl\Consulta\Filtro;
use Pbl\Consulta\MotorDeConsulta;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\ObjetoRepositorio;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Busca que ignora maiúsculas e acentos (nível A da busca, fluxo 8). */
final class BuscaTextoTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function textos(): iterable
    {
        yield 'acento agudo e circunflexo' => ['Presidência da República', 'presidencia da republica'];
        yield 'maiúsculas acentuadas' => ['ÓRGÃO PÚBLICO', 'orgao publico'];
        yield 'cedilha e til' => ['Ação, coração e atenção', 'acao, coracao e atencao'];
        yield 'ñ e ü' => ['Ñandú Müller', 'nandu muller'];
        yield 'ß e ligaduras' => ['Straße Œuvre Æther', 'strasse oeuvre aether'];
        yield 'decomposto (base + acento combinante)' => ["Presidencia\u{0302}", 'presidencia'];
        yield 'espaços repetidos e pontas' => ["  muitos \t espaços\n aqui  ", 'muitos espacos aqui'];
        yield 'já normalizado' => ['texto simples 123', 'texto simples 123'];
        yield 'vazio' => ['', ''];
        yield 'letras não latinas e emoji ficam' => ['Ελλάδα 😀', 'ελλάδα 😀'];
    }

    #[DataProvider('textos')]
    public function testNormalizar(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, Texto::normalizar($entrada));
        self::assertSame(Texto::normalizar($entrada), Texto::normalizar(Texto::normalizar($entrada)), 'idempotente');
    }

    public function testMigracaoRecalculaOsDadosQueJaExistiam(): void
    {
        $con = Conexao::memoria();
        (new Migrador($con, [new M001Base(), new M002Metadados(), new M003TituloBusca()]))->migrar();
        $agora = gmdate('c');
        $con->insert('classe', ['nome' => 'pagina', 'propriedades' => '[]']);
        $con->insert('objeto', ['site_id' => 1, 'classe_id' => 1, 'caminho' => '/1/', 'titulo' => 'Presidência da República', 'titulo_busca' => 'presidência da república', 'url_amigavel' => 'p', 'status' => 'publicado', 'dados' => '{}', 'criado_em' => $agora, 'atualizado_em' => $agora]);
        $con->insert('objeto_indice', ['objeto_id' => 1, 'chave' => 'resumo', 'texto' => 'Ação do Órgão', 'numero' => null]);

        (new Migrador($con))->migrar();

        self::assertSame('presidencia da republica', $con->fetchOne('SELECT titulo_busca FROM objeto WHERE id = 1'));
        self::assertSame('acao do orgao', $con->fetchOne("SELECT texto_norm FROM objeto_indice WHERE objeto_id = 1 AND chave = 'resumo'"));
    }

    public function testBuscaPorTituloPropriedadeEObjetoReferenciadoIgnoraMaiusculasEAcentos(): void
    {
        $con = Conexao::memoria();
        (new Migrador($con))->migrar();
        $classes = new ClasseRepositorio($con);
        $objetos = new ObjetoRepositorio($con);
        $motor = new MotorDeConsulta($con, $objetos);
        $autor = $classes->criar('autor', []);
        $doc = $classes->criar('documento', [
            ['nome' => 'resumo', 'tipo' => 'texto', 'indexavel' => true],
            ['nome' => 'autor', 'tipo' => 'ref'],
        ]);
        $joao = $objetos->criar(1, $autor, null, 'João Conceição', 'joao', []);
        $objetos->criar(1, $doc, null, 'Relatório da Presidência', 'rel', ['resumo' => 'Ação de saúde pública', 'autor' => $joao->id]);
        $objetos->criar(1, $doc, null, 'Outro assunto', 'outro', ['resumo' => 'Nada a ver']);
        $buscar = static fn (string $campo, string $valor): array => array_map(
            static fn (Objeto $o): string => $o->titulo,
            $motor->buscar(new Consulta(siteId: 1, classeIds: [$doc->id], filtros: [new Filtro($campo, 'contem', $valor)])),
        );

        foreach (['presidencia', 'PRESIDÊNCIA', 'Presidência', 'presid', 'relatorio da presid'] as $termo) {
            self::assertSame(['Relatório da Presidência'], $buscar('titulo', $termo), "título: {$termo}");
        }
        self::assertSame(['Relatório da Presidência'], $buscar('resumo', 'ACAO de saude'), 'propriedade de texto');
        self::assertSame(['Relatório da Presidência'], $buscar('autor.titulo', 'joao conceicao'), 'título do objeto referenciado');
        self::assertSame([], $buscar('titulo', 'inexistente'));
        self::assertSame([], $buscar('resumo', '%'), 'curinga é literal');
    }
}
