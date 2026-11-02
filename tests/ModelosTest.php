<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Caminhos;
use Pbl\Console\DemoPopularComando;
use Pbl\Console\ModelosComando;
use Pbl\Conteudo\Modelos;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Modelos opcionais (evento, faq, destaque, bloco), a tela de ativação, o CLI e o demo (ADR 0021). */
final class ModelosTest extends TestCase
{
    use AdminAuxiliar;

    private function modelos(): Modelos
    {
        return new Modelos($this->s->conexao(), $this->caminhos);
    }

    private function pagina(string $url): string
    {
        $this->s->publicador->publicarSite($this->s->sites->porId($this->siteId) ?? self::fail());
        $arquivo = $this->caminhos->dados() . '/cache/paginas/' . $this->siteId . '/' . $url . '.html';
        self::assertFileExists($arquivo);

        return (string) file_get_contents($arquivo);
    }

    public function testInstalacaoNaoTemModelosPorPadrao(): void
    {
        self::assertSame([], $this->modelos()->ativos());
        self::assertNull($this->s->classes->porNome('evento'));
    }

    public function testAtivarEIdempotente(): void
    {
        $a = $this->modelos()->ativar('evento', $this->siteId);
        self::assertSame(['evento', 'agenda'], $a['classes']);
        self::assertSame(3, $a['exemplos']);
        self::assertTrue($this->modelos()->ativo('evento'));
        $quantos = count($this->s->classes->todas());
        $b = $this->modelos()->ativar('evento', $this->siteId);
        self::assertSame([], $b['classes']);
        self::assertSame(0, $b['exemplos']);
        self::assertCount($quantos, $this->s->classes->todas());
    }

    public function testModeloDesconhecidoEhRecusado(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->modelos()->ativar('inexistente', $this->siteId);
    }

    public function testInstaladorAtivaOsModelosEscolhidos(): void
    {
        $outra = Ajuda::raizDeProjeto();
        try {
            $c = new Caminhos($outra);
            (new Instalador($c))->instalar('Outro', 'outro.exemplo.org', null, ['faq', 'bloco']);
            $s = Servicos::doBancoInstalado($c);
            self::assertNotNull($s->classes->porNome('faq'));
            self::assertNotNull($s->classes->porNome('bloco'));
            self::assertNull($s->classes->porNome('evento'));
            $this->expectException(\InvalidArgumentException::class);
            (new Instalador(new Caminhos(Ajuda::raizDeProjeto())))->instalar('X', 'x.exemplo.org', null, ['nada']);
        } finally {
            Ajuda::apagar($outra);
        }
    }

    public function testMoldesRenderizamAgendaEPerguntas(): void
    {
        $this->modelos()->ativar('evento', $this->siteId);
        $this->modelos()->ativar('faq', $this->siteId);
        $agenda = $this->pagina('agenda');
        self::assertStringContainsString('Próximos eventos', $agenda);
        self::assertStringContainsString('Encontro de exemplo', $agenda);
        self::assertStringContainsString('Eventos passados', $agenda);
        self::assertGreaterThan(strpos($agenda, 'Eventos passados'), strpos($agenda, 'Evento passado de exemplo'));
        self::assertLessThan(strpos($agenda, 'Eventos passados'), strpos($agenda, 'Encontro de exemplo'));
        $faq = $this->pagina('perguntas-frequentes');
        self::assertStringContainsString('<details', $faq);
        self::assertStringContainsString('Como edito esta pergunta?', $faq);
    }

    public function testDestaquesEBlocoAparecemNaPaginaInicial(): void
    {
        $this->modelos()->ativar('destaque', $this->siteId);
        $this->modelos()->ativar('bloco', $this->siteId);
        $inicio = $this->pagina('index');
        self::assertStringContainsString('class="destaques"', $inicio);
        self::assertStringContainsString('Destaque de exemplo', $inicio);
        self::assertStringContainsString('Rodapé de exemplo', $inicio);
    }

    public function testSemOsModelosAPaginaInicialNaoMostraFaixaNemErro(): void
    {
        $inicio = $this->pagina('index');
        self::assertStringNotContainsString('class="destaques"', $inicio);
    }

    public function testTelaDeModelosListaEAtiva(): void
    {
        $t = $this->token('admin');
        $html = $this->html($this->req('GET', '/_pbl/modelos', $t));
        self::assertStringContainsString('Agenda de eventos', $html);
        self::assertStringContainsString('Inativo', $html);
        $r = $this->post($t, '/_pbl/modelos/ativar', ['modelo' => 'faq', 'site' => (string) $this->siteId]);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('ativado', $this->html($r));
        self::assertTrue($this->modelos()->ativo('faq'));
        self::assertCount(1, $this->auditoria('modelo.ativar'));
    }

    public function testTelaDeModelosSoParaAdminEComCsrf(): void
    {
        self::assertSame(403, $this->req('GET', '/_pbl/modelos', $this->token('editor'))->getStatusCode());
        $e = $this->token('editor');
        self::assertSame(403, $this->post($e, '/_pbl/modelos/ativar', ['modelo' => 'faq', 'site' => (string) $this->siteId])->getStatusCode());
        $a = $this->token('admin');
        self::assertSame(403, $this->post($a, '/_pbl/modelos/ativar', ['modelo' => 'faq', 'site' => (string) $this->siteId], false)->getStatusCode());
        self::assertSame(400, $this->post($a, '/_pbl/modelos/ativar', ['modelo' => 'nada', 'site' => (string) $this->siteId])->getStatusCode());
        self::assertFalse($this->modelos()->ativo('faq'));
    }

    public function testComandoListaEAtiva(): void
    {
        $t = new CommandTester(new ModelosComando($this->caminhos));
        self::assertSame(0, $t->execute([]));
        self::assertStringContainsString('inativo', $t->getDisplay());
        self::assertSame(0, $t->execute(['acao' => 'ativar', 'nome' => 'bloco', '--site' => (string) $this->siteId]));
        self::assertTrue($this->modelos()->ativo('bloco'));
        self::assertSame(1, $t->execute(['acao' => 'ativar', 'nome' => 'zzz']));
    }

    public function testDemoPopularEhIdempotentePublicaERecusaSiteCheio(): void
    {
        $t = new CommandTester(new DemoPopularComando($this->caminhos));
        self::assertSame(0, $t->execute(['--site' => (string) $this->siteId]), $t->getDisplay());
        self::assertStringContainsString('página(s) publicada(s)', $t->getDisplay());
        $contar = fn (): int => (int) $this->s->conexao()->fetchOne('SELECT COUNT(*) FROM objeto');
        $antes = $contar();
        self::assertGreaterThan(40, $antes);
        // Segunda rodada: o site já está cheio (recusa) e, com --forcar, não cria nada de novo.
        self::assertSame(1, $t->execute(['--site' => (string) $this->siteId]));
        self::assertSame(0, $t->execute(['--site' => (string) $this->siteId, '--forcar' => true, '--sem-publicar' => true]));
        self::assertSame($antes, $contar());
        $dir = $this->caminhos->dados() . '/cache/paginas/' . $this->siteId;
        self::assertFileExists($dir . '/index.html');
        self::assertFileDoesNotExist($dir . '/rascunho-regimento-interno.html', 'rascunho (privado) não é publicado');
        self::assertFileDoesNotExist($dir . '/balanco-do-semestre.html', 'conteúdo agendado só sai na data');
        self::assertFileExists($dir . '/faq-1.html');
        self::assertStringContainsString('Instituto Exemplo', (string) file_get_contents($dir . '/index.html'));
        self::assertStringContainsString('<details', (string) file_get_contents($dir . '/perguntas-frequentes.html'));
    }
}
