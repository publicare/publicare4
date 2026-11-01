<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migracoes\M001Base;
use Pbl\Banco\Migracoes\M002Metadados;
use Pbl\Banco\Migracoes\M003TituloBusca;
use Pbl\Banco\Migracoes\M004BuscaNormalizada;
use Pbl\Banco\Migrador;
use Pbl\Consulta\Consulta;
use Pbl\Consulta\Filtro;
use Pbl\Consulta\MotorDeConsulta;
use Pbl\Conteudo\Classe;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\ConflitoDeVersao;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\ObjetoRepositorio;
use PHPUnit\Framework\TestCase;

/** Histórico de versões, troca de URL (com histórico), mover na árvore e restaurar. */
final class VersoesETUrlsTest extends TestCase
{
    private Connection $con;
    private ObjetoRepositorio $objetos;
    private Classe $pagina;

    protected function setUp(): void
    {
        $this->con = Conexao::memoria();
        (new Migrador($this->con))->migrar();
        $this->objetos = new ObjetoRepositorio($this->con);
        $this->pagina = (new ClasseRepositorio($this->con))->criar('pagina', [
            ['nome' => 'resumo', 'tipo' => 'texto', 'indexavel' => true],
            ['nome' => 'corpo', 'tipo' => 'html'],
        ]);
    }

    /** Mensagem do erro de validação que a operação provocou, ou null se ela foi aceita. */
    private function recusa(callable $operacao): ?string
    {
        try {
            $operacao();
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function criar(string $titulo, string $url, ?int $pai = null, int $site = 1): Objeto
    {
        return $this->objetos->criar($site, $this->pagina, $pai, $titulo, $url, ['resumo' => 'r', 'corpo' => '<p>c</p>']);
    }

    public function testCriarGravaAVersaoUmEAtualizarGravaAsSeguintes(): void
    {
        $o = $this->criar('Edital', 'edital');
        self::assertSame(1, $o->versao);

        $o2 = $this->objetos->atualizar($o->id, ['titulo' => 'Edital 2026'], 'corrige o ano');
        $o3 = $this->objetos->atualizar($o->id, ['status' => 'privado'], 'tira do ar');

        self::assertSame([2, 3], [$o2->versao, $o3->versao]);
        $historico = $this->objetos->versoes($o->id);
        self::assertSame([3, 2, 1], array_column($historico, 'versao'));
        self::assertSame(['tira do ar', 'corrige o ano', 'criação'], array_column($historico, 'motivo'));
        self::assertSame(['Edital 2026', 'Edital 2026', 'Edital'], array_column($historico, 'titulo'));
    }

    public function testAtualizarMesclaDadosRemoveComNullEReindexa(): void
    {
        $o = $this->criar('Doc', 'doc');
        $atualizado = $this->objetos->atualizar($o->id, ['dados' => ['resumo' => 'novo resumo', 'corpo' => null]]);
        $motor = new MotorDeConsulta($this->con, $this->objetos);

        self::assertSame(['resumo' => 'novo resumo'], $atualizado->dados);
        self::assertCount(1, $motor->buscar(new Consulta(siteId: 1, filtros: [new Filtro('resumo', '=', 'novo resumo')])));
        self::assertCount(0, $motor->buscar(new Consulta(siteId: 1, filtros: [new Filtro('resumo', '=', 'r')])), 'o índice antigo saiu');
        self::assertCount(1, $motor->buscar(new Consulta(siteId: 1, filtros: [new Filtro('resumo', 'contem', 'NOVO')])), 'e o normalizado também foi refeito');
    }

    public function testMudarOTituloAtualizaABuscaSemAcento(): void
    {
        $o = $this->criar('Antigo', 'antigo');
        $this->objetos->atualizar($o->id, ['titulo' => 'Presidência da República']);
        $motor = new MotorDeConsulta($this->con, $this->objetos);

        self::assertCount(1, $motor->buscar(new Consulta(siteId: 1, filtros: [new Filtro('titulo', 'contem', 'presidencia')])));
        self::assertCount(0, $motor->buscar(new Consulta(siteId: 1, filtros: [new Filtro('titulo', 'contem', 'antigo')])));
    }

    public function testValidaOQueMuda(): void
    {
        $o = $this->criar('X', 'x');
        foreach ([['status' => 'sumido'], ['dados' => ['inexistente' => 1]], ['url_amigavel' => 'Maiuscula']] as $mudanca) {
            self::assertNotNull($this->recusa(fn () => $this->objetos->atualizar($o->id, $mudanca)), 'aceitou ' . json_encode($mudanca));
        }
        self::assertSame(1, $this->objetos->porId($o->id)?->versao, 'nada mudou, nenhuma versão nova');
        $this->expectException(\InvalidArgumentException::class);
        $this->objetos->atualizar(999999, ['titulo' => 'nada']);
    }

    public function testConflitoDeVersaoImpedeSobrescreverTrabalhoAlheio(): void
    {
        $o = $this->criar('Pauta', 'pauta');
        $this->objetos->atualizar($o->id, ['titulo' => 'Pauta (ana)'], '', 1);

        try {
            $this->objetos->atualizar($o->id, ['titulo' => 'Pauta (bia)'], '', 1);
            self::fail('deveria dar conflito');
        } catch (ConflitoDeVersao $e) {
            self::assertSame([1, 2], [$e->esperada, $e->atual]);
        }
        self::assertSame('Pauta (ana)', $this->objetos->porId($o->id)?->titulo);
    }

    public function testTrocarDeUrlGuardaOEnderecoAntigoQueViraRedirecionamento(): void
    {
        $o = $this->criar('Guia', 'guia');
        $this->objetos->atualizar($o->id, ['url_amigavel' => 'guia-completo']);
        $this->objetos->atualizar($o->id, ['url_amigavel' => 'manual']);

        self::assertSame('manual', $this->objetos->urlAtualDoHistorico(1, 'guia'), 'não encadeia: vai direto para o endereço de hoje');
        self::assertSame('manual', $this->objetos->urlAtualDoHistorico(1, 'guia-completo'));
        self::assertNull($this->objetos->urlAtualDoHistorico(1, 'manual'), 'o endereço atual não redireciona');
        self::assertNull($this->objetos->urlAtualDoHistorico(2, 'guia'), 'histórico é por site');
        self::assertNull($this->objetos->urlAtualDoHistorico(1, 'nunca-existiu'));
    }

    public function testObjetoForaDoArNaoRedirecionaNemEnumera(): void
    {
        $o = $this->criar('Aviso', 'aviso');
        $this->objetos->atualizar($o->id, ['url_amigavel' => 'aviso-novo']);
        $this->objetos->atualizar($o->id, ['status' => 'privado']);

        self::assertNull($this->objetos->urlAtualDoHistorico(1, 'aviso'));
    }

    public function testUrlEmUsoOuDeOutroObjetoNoHistoricoExigeLiberacao(): void
    {
        $a = $this->criar('A', 'a');
        $b = $this->criar('B', 'b');
        $this->objetos->atualizar($a->id, ['url_amigavel' => 'a2']); // 'a' passa a redirecionar para o objeto A

        self::assertStringContainsString('já é usada', (string) $this->recusa(fn () => $this->objetos->atualizar($b->id, ['url_amigavel' => 'a2'])));
        self::assertStringContainsString('liberá-la de propósito', (string) $this->recusa(fn () => $this->objetos->atualizar($b->id, ['url_amigavel' => 'a'])));

        $this->objetos->atualizar($b->id, ['url_amigavel' => 'a'], 'toma o endereço', null, true);
        self::assertSame('a', $this->objetos->porId($b->id)?->urlAmigavel);
        self::assertNull($this->objetos->urlAtualDoHistorico(1, 'a'), 'liberada: não redireciona mais para A');
    }

    public function testVoltarParaUmaUrlAntigaDoMesmoObjetoEPermitido(): void
    {
        $o = $this->criar('Guia', 'guia');
        $this->objetos->atualizar($o->id, ['url_amigavel' => 'manual']);
        $this->objetos->atualizar($o->id, ['url_amigavel' => 'guia']);

        self::assertSame('guia', $this->objetos->porId($o->id)?->urlAmigavel);
        self::assertNull($this->objetos->urlAtualDoHistorico(1, 'guia'));
        self::assertSame('guia', $this->objetos->urlAtualDoHistorico(1, 'manual'));
    }

    public function testRestauraUmaVersaoComoNovaVersaoSemApagarOHistorico(): void
    {
        $o = $this->criar('Original', 'original');
        $this->objetos->atualizar($o->id, ['titulo' => 'Editado', 'dados' => ['resumo' => 'editado', 'corpo' => '<p>x</p>', ]]);
        $this->objetos->atualizar($o->id, ['dados' => ['resumo' => 'de novo']]);

        $r = $this->objetos->restaurar($o->id, 1);

        self::assertSame(4, $r->versao);
        self::assertSame('Original', $r->titulo);
        self::assertSame(['resumo' => 'r', 'corpo' => '<p>c</p>'], $r->dados);
        self::assertSame([4, 3, 2, 1], array_column($this->objetos->versoes($o->id), 'versao'));
        self::assertSame('restaurada a versão 1', $this->objetos->versoes($o->id)[0]['motivo']);
    }

    public function testMoverReescreveOCaminhoDaSubarvoreSemMudarEnderecos(): void
    {
        $a = $this->criar('A', 'a');
        $b = $this->criar('B', 'b');
        $a1 = $this->criar('A1', 'a1', $a->id);
        $a11 = $this->criar('A11', 'a11', $a1->id);

        $movido = $this->objetos->mover($a1->id, $b->id);

        self::assertSame("{$b->caminho}{$a1->id}/", $movido->caminho);
        self::assertSame("{$b->caminho}{$a1->id}/{$a11->id}/", $this->objetos->porId($a11->id)?->caminho);
        self::assertSame($b->id, $movido->paiId);
        self::assertSame('a1', $movido->urlAmigavel);
        self::assertSame(2, $movido->versao);
        $motor = new MotorDeConsulta($this->con, $this->objetos);
        self::assertSame(['A1', 'A11'], array_map(static fn (Objeto $x): string => $x->titulo, $motor->buscar(new Consulta(siteId: 1, paiId: $b->id, ordem: 'id'))));
    }

    public function testMoverRecusaCicloOutroSiteEPaiInexistente(): void
    {
        $a = $this->criar('A', 'a');
        $a1 = $this->criar('A1', 'a1', $a->id);
        $outro = $this->criar('Outro', 'o', null, 2);

        foreach ([[$a->id, $a1->id], [$a->id, $a->id], [$a1->id, $outro->id], [$a1->id, 999999]] as [$id, $pai]) {
            self::assertNotNull($this->recusa(fn () => $this->objetos->mover($id, $pai)), "moveu {$id} para {$pai}");
        }
        $this->assertSame("{$a->caminho}{$a1->id}/", $this->objetos->porId($a1->id)?->caminho, 'nada mudou');
    }

    public function testMoverParaARaiz(): void
    {
        $a = $this->criar('A', 'a');
        $a1 = $this->criar('A1', 'a1', $a->id);

        $m = $this->objetos->mover($a1->id, null);

        self::assertSame("/{$a1->id}/", $m->caminho);
        self::assertNull($m->paiId);
    }

    public function testMigracaoDaVersaoCriaVersaoUmParaObjetosExistentes(): void
    {
        $con = Conexao::memoria();
        (new Migrador($con, [new M001Base(), new M002Metadados(), new M003TituloBusca(), new M004BuscaNormalizada()]))->migrar();
        $agora = gmdate('c');
        $con->insert('classe', ['nome' => 'pagina', 'propriedades' => '[]']);
        $con->insert('objeto', ['site_id' => 1, 'classe_id' => 1, 'caminho' => '/1/', 'titulo' => 'Antigo', 'titulo_busca' => 'antigo', 'titulo_norm' => 'antigo', 'url_amigavel' => 'antigo', 'status' => 'publicado', 'dados' => '{}', 'criado_em' => $agora, 'atualizado_em' => $agora]);

        (new Migrador($con))->migrar();

        self::assertSame(1, (int) $con->fetchOne('SELECT versao FROM objeto WHERE id = 1'));
        self::assertSame('estado inicial', $con->fetchOne('SELECT motivo FROM objeto_versao WHERE objeto_id = 1 AND versao = 1'));
    }

    public function testCriarNaoTomaUmEnderecoQueRedirecionaParaOutroObjeto(): void
    {
        $a = $this->criar('A', 'a');
        $this->objetos->atualizar($a->id, ['url_amigavel' => 'a-novo']); // 'a' agora redireciona para o objeto A

        self::assertStringContainsString('liberá-la de propósito', (string) $this->recusa(fn () => $this->criar('Intruso', 'a')));
        self::assertSame('a-novo', $this->objetos->urlAtualDoHistorico(1, 'a'), 'o redirecionamento segue valendo');

        $b = $this->objetos->criar(1, $this->pagina, null, 'Dono novo', 'a', [], 'publicado', [], true);
        self::assertSame('a', $b->urlAmigavel);
        self::assertNull($this->objetos->urlAtualDoHistorico(1, 'a'), 'liberado de propósito: o histórico foi limpo');
    }

    public function testMigracaoRenomeiaSlugIndexExistenteEPublicacaoNaoQuebra(): void
    {
        $con = Conexao::memoria();
        (new Migrador($con, [new M001Base(), new M002Metadados(), new M003TituloBusca(), new M004BuscaNormalizada()]))->migrar();
        $agora = gmdate('c');
        $con->insert('classe', ['nome' => 'pagina', 'propriedades' => '[]']);
        foreach (['index', 'index-1'] as $i => $url) {
            $con->insert('objeto', ['site_id' => 1, 'classe_id' => 1, 'caminho' => '/' . ($i + 1) . '/', 'titulo' => 'P' . $i, 'titulo_busca' => 'p', 'titulo_norm' => 'p', 'url_amigavel' => $url, 'status' => 'publicado', 'dados' => '{}', 'criado_em' => $agora, 'atualizado_em' => $agora]);
        }

        (new Migrador($con))->migrar();

        self::assertSame(['index-2', 'index-1'], $con->fetchFirstColumn('SELECT url_amigavel FROM objeto ORDER BY id'), 'o antigo index vira o primeiro número livre (index-1 já era de outro)');
        self::assertSame(0, (int) $con->fetchOne("SELECT COUNT(*) FROM objeto WHERE url_amigavel = 'index'"));
    }
}
