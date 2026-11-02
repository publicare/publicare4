<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Banco\Conexao;
use Pbl\Caminhos;
use Pbl\Console\SanitizarHtmlComando;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\Site;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Varredura do HTML gravado antes da fase 5g (`bin/pbl sanitizar-html`): `ObjetoRepositorio::atualizar` já limpa o `html` na
 * gravação (fase 5g), então "conteúdo antigo" é sempre simulado escrevendo direto no banco, por fora do repositório — como
 * `AdminObjetosTest::testTodaSaidaNovaEhEscapada` já faz para o mesmo cenário.
 */
final class SanitizarHtmlComandoTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;
    private Site $site;
    private Objeto $inicio;
    private CommandTester $t;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Meu Site', 'www.exemplo.org');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $this->site = $this->s->sites->porHost('www.exemplo.org') ?? self::fail();
        $this->inicio = $this->s->objetos->porId((int) $this->site->objetoRaizId) ?? self::fail();
        $this->t = new CommandTester(new SanitizarHtmlComando($this->caminhos));
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function pagina(string $url, string $corpoSujo, string $status = 'publicado'): Objeto
    {
        $classe = $this->s->classes->porNome('pagina') ?? self::fail();
        // Cria limpo (a gravação normal já limparia o html sujo) e depois reescreve direto no banco, simulando conteúdo
        // gravado antes da fase 5g.
        $o = $this->s->objetos->criar($this->site->id, $classe, $this->inicio->id, ucfirst($url), $url, ['resumo' => '', 'corpo' => '<p>provisório</p>'], $status);
        Conexao::sqlite($this->caminhos->banco())->executeStatement(
            'UPDATE objeto SET dados = ? WHERE id = ?',
            [json_encode(['resumo' => '', 'corpo' => $corpoSujo], JSON_THROW_ON_ERROR), $o->id],
        );

        return $this->s->objetos->porId($o->id) ?? self::fail();
    }

    public function testRemoveScriptOnerrorEJavascriptEGravaNovaVersao(): void
    {
        $sujo = '<p onclick="alert(1)">oi</p><script>alert(2)</script><img src="http://x.exemplo.org/x.png" onerror="alert(3)"><a href="javascript:alert(4)">x</a>';
        $p = $this->pagina('antiga', $sujo);

        self::assertSame(0, $this->t->execute([]));

        $depois = $this->s->objetos->porId($p->id) ?? self::fail();
        // Igual ao que `HtmlSeguro::limpar` já faz na gravação normal (tests/HtmlSeguroTest.php): mesmo valor, calculado aqui
        // só para garantir que é exatamente o que foi gravado (nenhuma diferença por ter passado pela varredura).
        self::assertSame(\Pbl\Conteudo\HtmlSeguro::limpar($sujo), $depois->dados['corpo']);
        self::assertStringNotContainsString('onclick', $depois->dados['corpo']);
        self::assertStringNotContainsString('<script', $depois->dados['corpo']);
        self::assertStringNotContainsString('onerror', $depois->dados['corpo']);
        self::assertStringNotContainsString('javascript:', $depois->dados['corpo']);
        self::assertSame($p->versao + 1, $depois->versao, 'uma nova versão foi gravada');
        self::assertStringContainsString('varridos: 5', $this->t->getDisplay()); // pacote da instalação (5l) + a página antiga
        self::assertStringContainsString('alterados: 1', $this->t->getDisplay());
        self::assertStringContainsString('erros: 0', $this->t->getDisplay());
    }

    public function testHtmlJaLimpoFicaIntocadoSemNovaVersao(): void
    {
        // Sem link: `HtmlSeguro::limpar` acrescenta `rel="noopener noreferrer"` em todo `<a>`, então um link sem esse atributo
        // mudaria aqui mesmo que já estivesse "limpo" no sentido de não ter nada perigoso (não é esse o cenário do teste).
        $p = $this->pagina('limpa', '<p>Texto <strong>normal</strong> e uma lista:</p><ul><li>um</li><li>dois</li></ul>');
        $versaoAntes = $p->versao;

        self::assertSame(0, $this->t->execute([]));

        $depois = $this->s->objetos->porId($p->id) ?? self::fail();
        self::assertSame($versaoAntes, $depois->versao, 'nenhuma versão nova para html que já estava limpo');
        self::assertStringContainsString('alterados: 0', $this->t->getDisplay());
    }

    public function testVersaoNovaTemAutoriaDeSistemaESemConteudoNaAuditoria(): void
    {
        $sujo = '<script>alert(1)</script><p>oi</p>';
        $p = $this->pagina('autoria', $sujo);

        self::assertSame(0, $this->t->execute([]));

        $historico = $this->s->objetos->versoes($p->id);
        $ultima = $historico[0];
        self::assertNull($ultima['usuario_id'], 'autoria de sistema: sem usuário');

        $recentes = $this->s->auditoria->recentes(50);
        $linha = null;
        foreach ($recentes as $l) {
            if ($l['objeto_id'] === $p->id && $l['acao'] === 'objeto.atualizar') {
                $linha = $l;
                break;
            }
        }
        self::assertNotNull($linha);
        self::assertSame('sistema', $linha['ator']);
        self::assertNotNull($linha['detalhe']);
        self::assertStringContainsString('dados.corpo', (string) $linha['detalhe'], 'só o nome do campo, não o conteúdo');
        self::assertStringNotContainsString('script', (string) $linha['detalhe']);
        self::assertStringNotContainsString($sujo, (string) $linha['detalhe']);
    }

    public function testSimularNaoGravaEMostraResumoSemDespejarOHtml(): void
    {
        $sujo = '<p onclick="alert(1)">oi</p><script>alert(2)</script>';
        $p = $this->pagina('simulada', $sujo);

        self::assertSame(0, $this->t->execute(['--simular' => true]));

        $depois = $this->s->objetos->porId($p->id) ?? self::fail();
        self::assertSame($p->versao, $depois->versao, 'simular não grava nada');
        self::assertSame($sujo, $depois->dados['corpo'], 'o valor gravado continua o mesmo');
        $tela = $this->t->getDisplay();
        self::assertStringContainsString((string) $p->id, $tela);
        self::assertStringContainsString('corpo', $tela);
        self::assertStringContainsString('tags removidas', $tela);
        self::assertStringContainsString('script', $tela);
        self::assertStringContainsString('atributos removidos', $tela);
        self::assertStringContainsString('onclick', $tela);
        self::assertStringNotContainsString('alert(2)', $tela, 'o conteúdo do html não aparece, só o resumo');
        self::assertStringContainsString('(simulação', $tela);
        self::assertStringContainsString('alterados: 1', $tela);
    }

    public function testIdempotenteSegundaExecucaoNaoMudaNada(): void
    {
        $this->pagina('a', '<script>alert(1)</script><p>a</p>');
        $this->pagina('b', '<img src=x onerror=alert(1)><p>b</p>');

        self::assertSame(0, $this->t->execute([]));
        self::assertStringContainsString('alterados: 2', $this->t->getDisplay());

        $aAntes = $this->s->objetos->porUrl($this->site->id, 'a') ?? self::fail();
        $bAntes = $this->s->objetos->porUrl($this->site->id, 'b') ?? self::fail();

        $t2 = new CommandTester(new SanitizarHtmlComando($this->caminhos));
        self::assertSame(0, $t2->execute([]));
        self::assertStringContainsString('alterados: 0', $t2->getDisplay(), 'nada para mudar na segunda passada');

        $aDepois = $this->s->objetos->porUrl($this->site->id, 'a') ?? self::fail();
        $bDepois = $this->s->objetos->porUrl($this->site->id, 'b') ?? self::fail();
        self::assertSame($aAntes->versao, $aDepois->versao);
        self::assertSame($bAntes->versao, $bDepois->versao);
    }

    public function testLoteMaiorQueOTamanhoDaPaginaVarreTodosOsObjetos(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->pagina("pagina-{$i}", '<script>alert(1)</script><p>' . $i . '</p>');
        }

        self::assertSame(0, $this->t->execute(['--limite' => '5']));

        self::assertStringContainsString('varridos: 29', $this->t->getDisplay()); // pacote da instalação (5l) + 25
        self::assertStringContainsString('alterados: 25', $this->t->getDisplay());
        for ($i = 0; $i < 25; $i++) {
            $o = $this->s->objetos->porUrl($this->site->id, "pagina-{$i}") ?? self::fail();
            self::assertSame('<p>' . $i . '</p>', $o->dados['corpo']);
        }
    }

    public function testSiteFiltradoSoVarreOSiteEscolhido(): void
    {
        // Um segundo site na mesma instalação (o instalador só cria um por `dados/`).
        $outro = $this->s->sites->criar('Outro Site', 'outro.exemplo.org');
        $classe = $this->s->classes->porNome('pagina') ?? self::fail();
        $raizOutro = $this->s->objetos->criar($outro->id, $classe, null, 'Outro', '', ['resumo' => '', 'corpo' => '<p>ok</p>']);
        $this->s->sites->definirRaiz($outro->id, $raizOutro->id);

        $pSite1 = $this->pagina('do-site-1', '<script>alert(1)</script><p>1</p>');
        Conexao::sqlite($this->caminhos->banco())->executeStatement(
            'UPDATE objeto SET dados = ? WHERE id = ?',
            [json_encode(['resumo' => '', 'corpo' => '<script>alert(2)</script><p>2</p>'], JSON_THROW_ON_ERROR), $raizOutro->id],
        );

        self::assertSame(0, $this->t->execute(['--site' => (string) $this->site->id]));

        $depois1 = $this->s->objetos->porId($pSite1->id) ?? self::fail();
        self::assertSame('<p>1</p>', $depois1->dados['corpo'], 'o site escolhido foi limpo');
        $raizOutroDepois = $this->s->objetos->porId($raizOutro->id) ?? self::fail();
        self::assertStringContainsString('<script>', (string) $raizOutroDepois->dados['corpo'], 'o outro site não foi tocado');
    }

    public function testRepublicaAPaginaQueEstaNoAr(): void
    {
        $p = $this->pagina('publicada', '<script>alert(1)</script><p>novo</p>', 'publicado');
        $this->s->publicador->publicarSite($this->site);
        $arquivo = $this->caminhos->pagina($this->site->id, 'publicada');
        self::assertFileExists($arquivo);
        self::assertStringContainsString('alert(1)', (string) file_get_contents($arquivo), 'a página antiga ainda tem o html sujo');

        self::assertSame(0, $this->t->execute([]));

        self::assertFileExists($arquivo);
        $conteudo = (string) file_get_contents($arquivo);
        self::assertStringNotContainsString('<script>', $conteudo);
        self::assertStringContainsString('<p>novo</p>', $conteudo);
    }

    public function testRepublicaOSiteInteiroEntaoOutraPaginaEmCacheTambemEhRefeita(): void
    {
        $this->pagina('suja', '<script>alert(1)</script><p>novo</p>');
        $this->pagina('vizinha', '<p>limpa</p>');
        $this->s->publicador->publicarSite($this->site);
        $vizinha = $this->caminhos->pagina($this->site->id, 'vizinha');
        file_put_contents($vizinha, 'CACHE-VELHO');

        self::assertSame(0, $this->t->execute([]));

        // A "vizinha" não foi alterada nem é a página/pai do objeto sujo: só o rebuild do site a refaz.
        self::assertStringNotContainsString('CACHE-VELHO', (string) file_get_contents($vizinha));
    }

    public function testFalhaAoRepublicarContaComoErroESaiComCodigoDiferenteDeZero(): void
    {
        $this->pagina('suja', '<script>alert(1)</script><p>novo</p>');
        $this->s->publicador->publicarSite($this->site);
        $pasta = $this->caminhos->paginasDoSite($this->site->id);
        chmod($pasta, 0555);
        if (is_writable($pasta)) {
            chmod($pasta, 0755);
            self::markTestSkipped('o usuário ignora permissões de pasta (root)');
        }
        try {
            $codigo = $this->t->execute([]);
        } finally {
            chmod($pasta, 0755);
        }

        self::assertSame(1, $codigo);
        $saida = $this->t->getDisplay();
        self::assertStringContainsString('não foi possível republicar', $saida);
        self::assertStringContainsString('alterados: 1', $saida);
        self::assertStringContainsString('erros: 1', $saida);
    }
}
