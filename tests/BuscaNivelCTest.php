<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use Pbl\Busca\BuscaSql;
use Pbl\Consulta\MotorDeConsulta;
use Pbl\Conteudo\Classe;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Conteudo\SiteRepositorio;
use Pbl\Publicacao\FonteRepositorio;
use PHPUnit\Framework\TestCase;

/**
 * Busca nível C (ADR 0010): sugestão de grafia parecida ("você quis dizer…"), opcional por site, sem tabela nova e
 * sem nunca vazar termo de conteúdo privado, não publicado ou na lixeira.
 */
final class BuscaNivelCTest extends TestCase
{
    private Connection $con;
    private ObjetoRepositorio $objetos;
    private BuscaSql $busca;
    private SiteRepositorio $sites;
    private FonteRepositorio $fonte;
    private Classe $doc;
    private int $site;
    private int $n = 0;

    protected function setUp(): void
    {
        $this->con = Conexao::memoria();
        (new Migrador($this->con))->migrar();
        $this->objetos = new ObjetoRepositorio($this->con);
        $this->busca = new BuscaSql($this->con);
        $this->sites = new SiteRepositorio($this->con);
        $classes = new ClasseRepositorio($this->con);
        $this->doc = $classes->criar('doc', [['nome' => 'corpo', 'tipo' => 'html']]);
        $this->fonte = new FonteRepositorio($this->objetos, $classes, new MotorDeConsulta($this->con, $this->objetos), null, $this->sites);
        $this->site = $this->sites->criar('Site de teste', 'teste' . random_int(1, 1000000) . '.exemplo.org')->id;
    }

    /** @param array<string, mixed> $dados */
    private function criar(string $titulo, array $dados = [], string $status = 'publicado'): int
    {
        return $this->objetos->criar($this->site, $this->doc, null, $titulo, 'u' . (++$this->n), $dados, $status)->id;
    }

    public function testSugereErroDeUmaLetra(): void
    {
        $this->criar('Fernanda Lima');

        self::assertSame('fernanda lima', $this->busca->sugerir($this->site, 'Fernandha Lima'));
    }

    public function testSugereTrocaDeLetrasAdjacentes(): void
    {
        $this->criar('Relatório Anual'); // normaliza para "relatorio anual"

        // "relatroio": letras "o" e "r" trocadas de lugar (distância 2, dentro do limiar de palavras longas)
        self::assertSame('relatorio anual', $this->busca->sugerir($this->site, 'relatroio anual'));
    }

    public function testPalavraComAcentoJaAchaDiretoENaoPrecisaDeSugestao(): void
    {
        $this->criar('Relatório Anual');

        // "relatório" normaliza para "relatorio": já existe no vocabulário, não é "erro" nenhum
        self::assertNull($this->busca->sugerir($this->site, 'relatório'));
    }

    public function testSemNenhumaPalavraParaCorrigirDevolveNull(): void
    {
        $this->criar('Casa Verde');

        self::assertNull($this->busca->sugerir($this->site, 'casa verde'), 'tudo já existe: nada para sugerir');
        self::assertNull($this->busca->sugerir($this->site, ''), 'consulta em branco: nada para sugerir');
    }

    public function testTermoDeConteudoPrivadoNuncaESugerido(): void
    {
        $this->criar('Orcamentosecreto Interno', [], 'privado');

        self::assertNull(
            $this->busca->sugerir($this->site, 'orcamentosecreta interno'),
            'a única palavra parecida está num conteúdo privado: não pode aparecer na sugestão',
        );
    }

    public function testTermoDeConteudoNaoPublicadoOuNaLixeiraNuncaESugerido(): void
    {
        $this->criar('Xablirinto Submetido', [], 'submetido');
        $this->criar('Xablirinto Apagado', [], 'apagado');

        self::assertNull($this->busca->sugerir($this->site, 'xablirinta'));
    }

    public function testMesmaPalavraSugeridaQuandoOConteudoEPublico(): void
    {
        // contraprova do teste anterior: a mesma palavra, publicada, já é candidata
        $this->criar('Orcamentosecreto Público');

        self::assertSame('orcamentosecreto publico', $this->busca->sugerir($this->site, 'orcamentosecreta publico'));
    }

    public function testPalavraMuitoCurtaNaoTentaCorrigir(): void
    {
        $this->criar('Algo Qualquer');

        // "ab" tem só 2 letras: abaixo do tamanho mínimo para tentar corrigir (mesmo sem existir no vocabulário)
        self::assertNull($this->busca->sugerir($this->site, 'ab'));
    }

    public function testSemCandidatoConfiavelParaUmaDasPalavrasDevolveNullParaAFraseToda(): void
    {
        $this->criar('Fernanda Lima');

        // "fernandha" tem candidato bom; "zzzzzzzzzz" não tem nenhum: a frase toda fica sem sugestão (nada arriscado)
        self::assertNull($this->busca->sugerir($this->site, 'fernandha zzzzzzzzzz'));
    }

    public function testDesempatePelaFrequenciaEntreDoisCandidatosComAMesmaDistancia(): void
    {
        $this->criar('Vaca Boa'); // "vaca" no título: pontuação alta
        $this->criar('Vaca Boa Também');
        $this->criar('Qualquer coisa com maca', ['corpo' => 'uma maca qualquer']); // "maca" só no corpo: pontuação baixa

        // "naca" está a 1 letra tanto de "vaca" quanto de "maca"; "vaca" é mais frequente/relevante no site
        self::assertSame('vaca', $this->busca->sugerir($this->site, 'naca'));
    }

    public function testLimiteDeOitoPalavrasDaConsultaTambemValeParaASugestao(): void
    {
        $this->criar('Um Dois Tres Quatro Cinco Seis Sete Oito Nove Dez');

        // 10 palavras digitadas: só as 8 primeiras entram (Termos::MAX_TERMOS); a chamada não falha nem estoura
        $sugestao = $this->busca->sugerir($this->site, 'umm doiis treis quatroo cincoo seiis seete oitoo novee dezz');
        self::assertLessThanOrEqual(8, $sugestao === null ? 0 : count(explode(' ', $sugestao)), 'só as 8 primeiras palavras entram na sugestão');
    }

    // ---------------------------------------------------------------- opcional por site (via FonteRepositorio)

    public function testSugestaoSoSaiDaFonteQuandoOSiteLigaATolerancia(): void
    {
        $this->criar('Fernanda Lima');
        $objeto = ['site' => $this->site];
        $filtros = ['busca' => 'fernandha'];

        self::assertNull($this->fonte->sugestao($objeto, $filtros), 'desligado por padrão');

        $this->sites->definirBuscaTolerancia($this->site, true);
        self::assertSame('fernanda', $this->fonte->sugestao($objeto, $filtros));

        $this->sites->definirBuscaTolerancia($this->site, false);
        self::assertNull($this->fonte->sugestao($objeto, $filtros), 'desligar de novo volta a não sugerir');
    }

    public function testSugestaoDaFonteExigeBuscaNaoVaziaEObjetoComSite(): void
    {
        $this->sites->definirBuscaTolerancia($this->site, true);
        $this->criar('Fernanda Lima');

        self::assertNull($this->fonte->sugestao(['site' => $this->site], []), 'sem busca=');
        self::assertNull($this->fonte->sugestao(['site' => $this->site], ['busca' => '']), 'busca em branco');
        self::assertNull($this->fonte->sugestao([], ['busca' => 'fernandha']), 'sem site no contexto');
    }

    public function testSugestaoDeSiteDiferenteNaoVazaParaOutroSite(): void
    {
        $outro = $this->sites->criar('Outro site', 'outro' . random_int(1, 1000000) . '.exemplo.org')->id;
        $this->sites->definirBuscaTolerancia($this->site, true);
        $this->sites->definirBuscaTolerancia($outro, true);
        // "fernanda" só existe no site B; o site A não deve sugeri-la
        $this->objetos->criar($outro, $this->doc, null, 'Fernanda Lima', 'ux', []);

        self::assertNull($this->fonte->sugestao(['site' => $this->site], ['busca' => 'fernandha']));
    }
}
