<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use Pbl\Busca\BuscaSql;
use Pbl\Busca\Termos;
use Pbl\Consulta\Consulta;
use Pbl\Consulta\Filtro;
use Pbl\Consulta\MotorDeConsulta;
use Pbl\Conteudo\Classe;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\ObjetoRepositorio;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Busca nível B (ADR 0010): várias palavras, prefixo, plural, relevância, visibilidade e segurança. */
final class BuscaNivelBTest extends TestCase
{
    private Connection $con;
    private ObjetoRepositorio $objetos;
    private MotorDeConsulta $motor;
    private Classe $doc;

    protected function setUp(): void
    {
        $this->con = Conexao::memoria();
        (new Migrador($this->con))->migrar();
        $this->objetos = new ObjetoRepositorio($this->con);
        $this->motor = new MotorDeConsulta($this->con, $this->objetos);
        $this->doc = (new ClasseRepositorio($this->con))->criar('doc', [
            ['nome' => 'resumo', 'tipo' => 'texto'],
            ['nome' => 'corpo', 'tipo' => 'html'],
            ['nome' => 'leituras', 'tipo' => 'numero', 'indexavel' => true],
        ]);
    }

    /**
     * @param array<string, string|int|float|bool> $dados
     * @param array{peso?: int, descricao?: string, data_publicacao?: string|null, data_validade?: string|null} $meta
     */
    private function criar(string $titulo, string $url, array $dados = [], array $meta = [], string $status = 'publicado', int $site = 1): int
    {
        return $this->objetos->criar($site, $this->doc, null, $titulo, $url, $dados, $status, $meta)->id;
    }

    /** @return list<string> */
    private function achar(string $busca, string $ordem = '', int $site = 1, bool $visiveis = true): array
    {
        return array_map(
            static fn ($o): string => $o->titulo,
            $this->motor->buscar(new Consulta(siteId: $site, ordem: $ordem, apenasVisiveis: $visiveis, busca: $busca)),
        );
    }

    /** @return array<string, array{string, string}> */
    public static function pluraisSingulares(): array
    {
        return [
            'ões' => ['ações', 'ação'], 'ães' => ['pães', 'pão'], 'ais' => ['animais', 'animal'], 'eis' => ['papéis', 'papel'],
            'res' => ['mulheres', 'mulher'], 'zes' => ['luzes', 'luz'], 'ns' => ['homens', 'homem'], 's simples' => ['casas', 'casa'],
            'ois' => ['lençóis', 'lençol'],
        ];
    }

    #[DataProvider('pluraisSingulares')]
    public function testPluralEntendeSingularENoSentidoContrario(string $plural, string $singular): void
    {
        self::assertSame(Termos::de($singular), Termos::de($plural));
    }

    public function testPalavrasQueNaoSaoPluralFicamComoEstao(): void
    {
        foreach (['onibus', 'pais', 'lapis', 'gas', 'reis', 'mes', 'oi', 'sim'] as $palavra) {
            self::assertSame([$palavra], Termos::de($palavra), $palavra);
        }
        self::assertSame([], Termos::de('a'), 'letra sozinha');
    }

    public function testTermosIgnoramPalavrasVaziasLetrasSozinhasERepeticao(): void
    {
        self::assertSame(['ministerio', 'saude'], Termos::de('O Ministério da Saúde, de A a Z; saúde!'));
        self::assertSame([], Termos::de('de da o a'));
        self::assertSame(['lei', '2024'], Termos::de('Lei 2024/lei'));
    }

    public function testTetosDeTermosEDeTamanho(): void
    {
        $muitos = implode(' ', array_map(static fn (int $i): string => 'palavra' . chr(97 + $i), range(0, 20)));
        self::assertLessThanOrEqual(Termos::MAX_TERMOS, count(Termos::daConsulta($muitos)));
        $longo = str_repeat('a', 5000);
        $termos = Termos::daConsulta($longo . ' ' . $longo);
        self::assertLessThanOrEqual(1, count($termos));
        foreach ($termos as $t) {
            self::assertLessThanOrEqual(Termos::MAX_TAMANHO_TERMO, strlen($t));
        }
    }

    public function testVariasPalavrasPrecisamAparecerTodasEmQualquerOrdem(): void
    {
        $this->criar('Relatório da Presidência', 'a', ['resumo' => 'Contas de 2025']);
        $this->criar('Presidência da República', 'b');
        $this->criar('Relatório de obras', 'c');

        self::assertSame(['Relatório da Presidência'], $this->achar('presidencia relatorio'));
        self::assertEqualsCanonicalizing(['Relatório da Presidência', 'Presidência da República'], $this->achar('presidencia'));
        self::assertSame([], $this->achar('presidencia obras'), 'E, não OU');
        self::assertSame(['Relatório da Presidência'], $this->achar('relatorio contas'), 'palavras em campos diferentes');
    }

    public function testPrefixo(): void
    {
        $this->criar('Presidência da República', 'a');
        $this->criar('Presidente eleito', 'b');
        $this->criar('Residência', 'c');

        self::assertEqualsCanonicalizing(['Presidência da República', 'Presidente eleito'], $this->achar('presid'));
        self::assertSame(['Presidência da República'], $this->achar('presid repub'));
        self::assertSame([], $this->achar('xresid'), 'só o começo da palavra, não o meio');
    }

    public function testPluralNaBuscaAchaSingularEViceVersa(): void
    {
        $this->criar('Ações do governo', 'a');
        $this->criar('Casa nova', 'b');
        $this->criar('Casas antigas', 'c');

        self::assertSame(['Ações do governo'], $this->achar('acao'));
        self::assertSame(['Ações do governo'], $this->achar('ACOES'));
        self::assertEqualsCanonicalizing(['Casa nova', 'Casas antigas'], $this->achar('casas'));
        self::assertEqualsCanonicalizing(['Casa nova', 'Casas antigas'], $this->achar('casa'));
    }

    public function testBuscaNoTituloNaDescricaoENoCorpoComHtmlEntidades(): void
    {
        $this->criar('Primeiro', 'a', ['corpo' => '<p>Texto sobre <strong>saneamento</strong> &amp; água</p>']);
        $this->criar('Segundo', 'b', [], ['descricao' => 'Resumo de licitações']);
        $this->criar('Terceiro', 'c', ['resumo' => 'Obras de pavimentação']);
        $this->criar('Quarto', 'd', ['leituras' => 99999]);

        self::assertSame(['Primeiro'], $this->achar('saneamento agua'));
        self::assertSame([], $this->achar('strong'), 'a marcação não é texto');
        self::assertSame(['Segundo'], $this->achar('licitacao'));
        self::assertSame(['Terceiro'], $this->achar('pavimenta'));
        self::assertSame([], $this->achar('99999'), 'número não é texto');
    }

    public function testRelevanciaTituloDepoisDescricaoDepoisCorpo(): void
    {
        $this->criar('Quadro de avisos', 'corpo', ['resumo' => 'Sobre a vacina da gripe'], ['peso' => 1]);
        $this->criar('Campanha da vacina', 'titulo', [], ['peso' => 3]);
        $this->criar('Saúde pública', 'desc', [], ['descricao' => 'Dia da vacina', 'peso' => 2]);

        self::assertSame(['Campanha da vacina', 'Saúde pública', 'Quadro de avisos'], $this->achar('vacina'));
        self::assertSame(['Campanha da vacina', 'Saúde pública', 'Quadro de avisos'], $this->achar('vacina', 'relevancia'));
    }

    public function testRelevanciaPalavraIgualEInicialGanhamDePrefixoEDoMeio(): void
    {
        $this->criar('Campanha de vacinação', 'prefixo');
        $this->criar('Plano de vacina', 'meio');
        $this->criar('Vacina', 'exato');
        $this->criar('Vacina contra gripe', 'inicia');

        self::assertSame(['Vacina', 'Vacina contra gripe', 'Plano de vacina', 'Campanha de vacinação'], $this->achar('vacina'));
    }

    public function testOrdemExplicitaPrevaleceEProdutoRelevanciaPodeSerCombinada(): void
    {
        $this->criar('Vacina A', 'a', [], ['peso' => 9]);
        $this->criar('Vacina B', 'b', [], ['peso' => 1]);

        self::assertSame(['Vacina B', 'Vacina A'], $this->achar('vacina', 'peso'));
        self::assertSame(['Vacina A', 'Vacina B'], $this->achar('vacina', 'relevancia, -peso'));
        self::assertSame(['Vacina A', 'Vacina B'], $this->achar('vacina', 'titulo'));
    }

    public function testRelevanciaSemBuscaEIgnorada(): void
    {
        $this->criar('B', 'b', [], ['peso' => 2]);
        $this->criar('A', 'a', [], ['peso' => 1]);
        $lista = array_map(static fn ($o): string => $o->titulo, $this->motor->buscar(new Consulta(siteId: 1, ordem: 'relevancia, peso')));
        self::assertSame(['A', 'B'], $lista);
    }

    public function testRespeitaEstadoEDatasDePublicacao(): void
    {
        $ontem = gmdate('c', time() - 86400);
        $amanha = gmdate('c', time() + 86400);
        $this->criar('Vacina publicada', 'a');
        $this->criar('Vacina rascunho', 'b', [], [], 'privado');
        $this->criar('Vacina agendada', 'c', [], ['data_publicacao' => $amanha]);
        $this->criar('Vacina vencida', 'd', [], ['data_validade' => $ontem]);
        $this->criar('Vacina na lixeira', 'e', [], [], 'apagado');

        self::assertSame(['Vacina publicada'], $this->achar('vacina'));
        self::assertCount(5, $this->achar('vacina', '', 1, false), 'consulta de administração enxerga todos');
    }

    public function testDespublicarEMudarOConteudoAtualizamOResultado(): void
    {
        $id = $this->criar('Edital aberto', 'a', ['resumo' => 'compra de merenda']);
        self::assertSame(['Edital aberto'], $this->achar('merenda'));

        $this->objetos->atualizar($id, ['dados' => ['resumo' => 'compra de livros']]);
        self::assertSame([], $this->achar('merenda'), 'termo antigo sai do índice');
        self::assertSame(['Edital aberto'], $this->achar('livros'));

        $this->objetos->atualizar($id, ['titulo' => 'Pregão', 'descricao' => 'novo texto']);
        self::assertSame([], $this->achar('edital'));
        self::assertSame(['Pregão'], $this->achar('pregao'));
        self::assertSame(['Pregão'], $this->achar('novo texto livros'));

        $this->objetos->atualizar($id, ['status' => 'privado']);
        self::assertSame([], $this->achar('pregao'));
        $this->objetos->atualizar($id, ['status' => 'publicado']);
        self::assertSame(['Pregão'], $this->achar('pregao'));
    }

    public function testIsolamentoEntreSites(): void
    {
        $this->criar('Vacina do site 1', 'a');
        $this->criar('Vacina do site 2', 'a', [], [], 'publicado', 2);

        self::assertSame(['Vacina do site 1'], $this->achar('vacina', '', 1));
        self::assertSame(['Vacina do site 2'], $this->achar('vacina', '', 2));
    }

    public function testCombinaComOutrosFiltrosPaginacaoEContagem(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->criar("Vacina {$i}", "v{$i}", ['leituras' => $i]);
        }
        $this->criar('Outra coisa', 'o', ['leituras' => 9]);
        $c = new Consulta(siteId: 1, filtros: [new Filtro('leituras', '>=', '3')], inicio: 1, limite: 1, busca: 'vacina', ordem: 'leituras');

        self::assertSame(3, $this->motor->contar($c));
        self::assertSame(['Vacina 4'], array_map(static fn ($o): string => $o->titulo, $this->motor->buscar($c)));
    }

    public function testBuscaEmBrancoNaoFiltraEPontuacaoOuPalavrasVaziasNaoAchamNada(): void
    {
        $this->criar('Um', 'a');
        $this->criar('Dois', 'b');
        self::assertCount(2, $this->achar('   '));
        self::assertSame([], $this->achar('de'));
        self::assertSame([], $this->achar('%'));
        self::assertSame([], $this->achar('_'));
        self::assertSame([], $this->achar('!!'));
    }

    public function testEntradaMaliciosaEParametrizadaECuringasSaoLiterais(): void
    {
        $this->criar('Cem por cento', 'a', ['resumo' => '100% garantido']);
        $this->criar('Sublinhado_um', 'b');
        $this->criar('Sublinhadoxum', 'c');

        self::assertSame([], $this->achar("x'; DROP TABLE objeto; --"));
        self::assertSame([], $this->achar("') OR 1=1 --"));
        self::assertSame(2, (int) $this->con->fetchOne('SELECT COUNT(*) FROM objeto WHERE site_id = 1 AND titulo IN (\'Sublinhado_um\', \'Sublinhadoxum\')'));
        self::assertEqualsCanonicalizing(['Sublinhado_um', 'Sublinhadoxum'], $this->achar('sublinhado_um'), '_ separa palavras; "um" é palavra vazia');
        self::assertSame([], $this->achar('sublin_ado'), '_ não casa um caractere qualquer');
        self::assertSame([], $this->achar('subl%ado'), '% não casa trecho qualquer');
        self::assertSame(3, (int) $this->con->fetchOne('SELECT COUNT(*) FROM objeto'));
    }

    public function testConsultaGigantePassaEPassaRapido(): void
    {
        $this->criar('Aaaa bbbb', 'a');
        $entrada = str_repeat('(a+)+$ ', 5000) . str_repeat('x', 100000);
        $t = microtime(true);
        self::assertSame([], $this->achar($entrada));
        self::assertLessThan(1.0, microtime(true) - $t);
    }

    public function testPalavrasComAcentosEMaiusculasNaConsultaEDeCaracteresEspeciais(): void
    {
        $this->criar('Cidadão Çatarina Œuvre', 'a');
        self::assertSame(['Cidadão Çatarina Œuvre'], $this->achar('CIDADAOS catarina oeuvre'));
        self::assertSame(['Cidadão Çatarina Œuvre'], $this->achar('cidadão'));
    }

    public function testMigracaoIndexaObjetosJaExistentes(): void
    {
        $con = Conexao::memoria();
        $migrador = new Migrador($con);
        $migrador->migrar();
        $classes = new ClasseRepositorio($con);
        $doc = $classes->criar('doc', [['nome' => 'resumo', 'tipo' => 'texto']]);
        $id = (new ObjetoRepositorio($con))->criar(1, $doc, null, 'Antigo documento', 'antigo', ['resumo' => 'sobre tributos'])->id;
        // simula instalação anterior à 1c: sem a tabela de termos
        $con->executeStatement('DROP TABLE objeto_termo');
        $con->executeStatement('DELETE FROM schema_versao WHERE versao >= 10');
        (new Migrador($con))->migrar();

        $motor = new MotorDeConsulta($con, new ObjetoRepositorio($con));
        $achados = $motor->buscar(new Consulta(siteId: 1, busca: 'tributo antigo'));
        self::assertSame([$id], array_map(static fn ($o): int => $o->id, $achados));
    }

    public function testCorpoGiganteNaoCortaTermosDoTituloEDaDescricao(): void
    {
        $corpo = implode(' ', array_map(static fn (int $i): string => 'palavra' . $i, range(1, Termos::MAX_TERMOS_POR_OBJETO + 500)));
        $this->criar('Titulounico especial', 'a', ['resumo' => $corpo], ['descricao' => 'descricaounica']);

        self::assertSame(['Titulounico especial'], $this->achar('titulounico'));
        self::assertSame(['Titulounico especial'], $this->achar('descricaounica'));
        self::assertSame(['Titulounico especial'], $this->achar('palavra1'));
        self::assertLessThanOrEqual(Termos::MAX_TERMOS_POR_OBJETO, (int) $this->con->fetchOne('SELECT COUNT(*) FROM objeto_termo'));
    }

    public function testRemoverTiraDoIndice(): void
    {
        $id = $this->criar('Efêmero', 'a');
        (new BuscaSql($this->con))->remover($id);
        self::assertSame([], $this->achar('efemero'));
        self::assertSame(0, (int) $this->con->fetchOne('SELECT COUNT(*) FROM objeto_termo WHERE objeto_id = ?', [$id]));
    }

    public function testDesempenhoComCincoMilObjetos(): void
    {
        $palavras = ['saude', 'educacao', 'obras', 'transporte', 'licitacao', 'orcamento', 'servidor', 'concurso', 'edital', 'contrato',
            'presidencia', 'ministerio', 'relatorio', 'campanha', 'vacina', 'escola', 'hospital', 'estrada', 'ponte', 'praca'];
        mt_srand(42);
        $inicio = microtime(true);
        $this->con->beginTransaction();
        for ($i = 0; $i < 5000; $i++) {
            $titulo = ucfirst($palavras[mt_rand(0, 19)]) . ' ' . $palavras[mt_rand(0, 19)] . " n{$i}";
            $corpo = implode(' ', array_map(static fn (int $k): string => $palavras[mt_rand(0, 19)], range(1, 40)));
            $this->criar($titulo, "p{$i}", ['resumo' => $corpo], ['descricao' => $palavras[mt_rand(0, 19)] . ' ' . $palavras[mt_rand(0, 19)]]);
        }
        $this->con->commit();
        $criacao = microtime(true) - $inicio;

        $inicio = microtime(true);
        $achados = $this->motor->buscar(new Consulta(siteId: 1, busca: 'presid vacina', limite: 20));
        $total = $this->motor->contar(new Consulta(siteId: 1, busca: 'presid vacina'));
        $tempo = microtime(true) - $inicio;
        $inicio = microtime(true);
        $this->motor->buscar(new Consulta(siteId: 1, busca: 'hospital', limite: 20));
        $umaPalavra = microtime(true) - $inicio;

        fwrite(STDERR, sprintf("\n[busca 5000 objetos] indexar+criar: %.1fs; 2 palavras (buscar+contar): %.3fs (%d achados); 1 palavra: %.3fs\n", $criacao, $tempo, $total, $umaPalavra));
        self::assertNotEmpty($achados);
        self::assertLessThan(2.0, $tempo);
        self::assertLessThan(2.0, $umaPalavra);
    }
}
