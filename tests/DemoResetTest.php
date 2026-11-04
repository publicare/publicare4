<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Atualizacao\Manutencao;
use Pbl\Console\DemoResetarComando;
use Pbl\Cron\Catalogo;
use Pbl\Cron\Tarefa;
use Pbl\Cron\Tarefas\ReinicioDaDemoTarefa;
use Pbl\Demo\AgendaDaDemo;
use Pbl\Demo\AvisoDaDemo;
use Pbl\Demo\ConfigDaDemo;
use Pbl\Demo\ReiniciadorDaDemo;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Reinício agendado da demo (ADR 0023, adendo): só em demo, com backup, manutenção, trava e restauração se falhar. */
final class DemoResetTest extends TestCase
{
    use AdminAuxiliar;

    private function ligarDemo(string $extra = ''): void
    {
        file_put_contents($this->caminhos->config() . '/demo.json', '{"demo": true' . $extra . '}');
    }

    /** @return list<string> */
    private function logins(): array
    {
        $s = Servicos::doBancoInstalado($this->caminhos);
        try {
            $l = array_map(static fn ($u): string => $u->login, $s->usuarios->todos());
            sort($l);

            return $l;
        } finally {
            $s->conexao()->close();
        }
    }

    /** @return list<string> */
    private function idsDoCatalogo(): array
    {
        return array_map(static fn (Tarefa $t): string => $t->id(), Catalogo::padrao($this->caminhos));
    }

    protected function tearDown(): void
    {
        putenv('PBL_DEMO');
        Ajuda::apagar($this->raiz);
    }

    public function testForaDeDemoNadaExisteNadaRoda(): void
    {
        putenv('PBL_DEMO');
        self::assertFalse(ConfigDaDemo::ativa($this->caminhos));
        self::assertNotContains('demo-reset', $this->idsDoCatalogo());
        self::assertSame('', AvisoDaDemo::texto($this->caminhos));
        self::assertSame('', AvisoDaDemo::faixa($this->caminhos));

        $antes = $this->logins();
        $c = new CommandTester(new DemoResetarComando($this->caminhos));
        self::assertSame(1, $c->execute(['--agora' => true]));
        self::assertStringContainsString('não é uma demo', $c->getDisplay());
        try {
            (new ReiniciadorDaDemo($this->caminhos))->reiniciar();
            self::fail('deveria recusar');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('não é uma demo', $e->getMessage());
        }
        self::assertFalse((new ReinicioDaDemoTarefa())->temTrabalho($this->s));
        self::assertSame($antes, $this->logins());
        self::assertContains('editor', $this->logins());
        self::assertDirectoryDoesNotExist($this->caminhos->dados() . '/demo/backups');
    }

    public function testFlagInvalidaNaoLigaEEnvLiga(): void
    {
        file_put_contents($this->caminhos->config() . '/demo.json', '{"demo": "true"}');
        self::assertFalse(ConfigDaDemo::ativa($this->caminhos));
        putenv('PBL_DEMO=0');
        self::assertFalse(ConfigDaDemo::ativa($this->caminhos));
        putenv('PBL_DEMO=1');
        self::assertTrue(ConfigDaDemo::ativa($this->caminhos));
    }

    public function testCatalogoETelaQuandoDemo(): void
    {
        $this->ligarDemo();
        self::assertContains('demo-reset', $this->idsDoCatalogo());
        $html = $this->html($this->req('GET', '/_pbl/tarefas', $this->token('admin')));
        self::assertStringContainsString('Reinício da demonstração', $html);
        self::assertStringContainsString('às 04:00', $html);
        self::assertStringContainsString('Demo: reinicia em', $html, 'aviso no painel');
    }

    public function testAgendaSeteDiasAs04DeBrasilia(): void
    {
        $ultimo = (new \DateTimeImmutable('2026-11-04 15:30', new \DateTimeZone('America/Sao_Paulo')))->getTimestamp();
        $prox = AgendaDaDemo::calcular($ultimo, 7, '04:00');
        self::assertSame('11/11 às 04:00', AgendaDaDemo::rotulo($prox));

        $agora = 1_800_000_000;
        $a = new AgendaDaDemo($this->caminhos, static function () use (&$agora): int {
            return $agora;
        });
        self::assertSame($agora, $a->ultimo(), 'a primeira leitura grava a linha de base');
        self::assertFalse($a->vencido());
        $agora += 8 * 86400;
        self::assertTrue($a->vencido());
        $a->registrarFalha();
        self::assertFalse($a->vencido(), 'depois de uma falha, espera');
        $agora += AgendaDaDemo::ESPERA_APOS_FALHA;
        self::assertTrue($a->vencido());
    }

    public function testConfigDeDiasEHora(): void
    {
        $this->ligarDemo(', "dias": 3, "hora": "05:30"');
        self::assertSame(3, ConfigDaDemo::dias($this->caminhos));
        self::assertSame('05:30', ConfigDaDemo::hora($this->caminhos));
        $this->ligarDemo(', "dias": 500, "hora": "25:99"');
        self::assertSame(7, ConfigDaDemo::dias($this->caminhos));
        self::assertSame('04:00', ConfigDaDemo::hora($this->caminhos));
    }

    public function testSimularNaoAlteraNada(): void
    {
        $this->ligarDemo();
        $antes = $this->logins();
        $c = new CommandTester(new DemoResetarComando($this->caminhos));
        self::assertSame(0, $c->execute(['--simular' => true]));
        self::assertStringContainsString('simulação', $c->getDisplay());
        self::assertSame($antes, $this->logins());
        self::assertDirectoryDoesNotExist($this->caminhos->dados() . '/demo/backups/x');
        self::assertFalse(Manutencao::ativa($this->caminhos));
    }

    public function testSemAgoraAindaNaoEHora(): void
    {
        $this->ligarDemo();
        $c = new CommandTester(new DemoResetarComando($this->caminhos));
        self::assertSame(0, $c->execute([]));
        self::assertStringContainsString('Ainda não é a hora', $c->getDisplay());
        self::assertContains('editor', $this->logins());
    }

    public function testReinicioCompletoRecriaContasEMantemConfig(): void
    {
        if (!extension_loaded("gd")) {
            self::markTestSkipped("O conteúdo de exemplo precisa da extensão GD (presente na imagem de produção e no CI).");
        }
        $this->ligarDemo();
        file_put_contents($this->caminhos->config() . '/rede.json', '{"x":1}');
        $segredo = glob($this->caminhos->config() . '/segredo*') ?: [];
        @mkdir($this->caminhos->uploads(), 0775, true);
        file_put_contents($this->caminhos->uploads() . '/visitante.txt', 'lixo');
        foreach (['20200101-000001-aaaa', '20200101-000002-bbbb', '20200101-000003-cccc'] as $n) {
            mkdir($this->caminhos->dados() . '/demo/backups/' . $n, 0775, true);
        }

        $c = new CommandTester(new DemoResetarComando($this->caminhos));
        self::assertSame(0, $c->execute(['--agora' => true]), $c->getDisplay());

        $logins = $this->logins();
        self::assertSame(['admin', 'autor', 'editor'], $logins);
        self::assertFileDoesNotExist($this->caminhos->uploads() . '/visitante.txt');
        $s = Servicos::doBancoInstalado($this->caminhos);
        try {
            self::assertNotNull($s->usuarios->autenticar('admin', 'admin-demo-2026'));
            self::assertNotNull($s->usuarios->autenticar('autor', 'demo-2026-pbl4'));
            self::assertNotNull($s->usuarios->autenticar('editor', 'demo-2026-pbl4'));
            self::assertNotNull($s->sites->porHost('www.exemplo.org'), 'domínio mantido');
        } finally {
            $s->conexao()->close();
        }
        self::assertFileExists($this->caminhos->config() . '/rede.json');
        self::assertFileExists($this->caminhos->config() . '/demo.json');
        foreach ($segredo as $f) {
            self::assertFileExists($f);
        }
        self::assertFalse(Manutencao::ativa($this->caminhos));
        self::assertCount(ReiniciadorDaDemo::MANTER_BACKUPS, glob($this->caminhos->dados() . '/demo/backups/*', GLOB_ONLYDIR) ?: []);
        self::assertStringContainsString('Demo: reinicia em', AvisoDaDemo::faixa($this->caminhos));
    }

    public function testFalhaNoMeioRestauraOBackup(): void
    {
        $this->ligarDemo();
        @mkdir($this->caminhos->uploads(), 0775, true);
        file_put_contents($this->caminhos->uploads() . '/arquivo.txt', 'original');
        $antes = $this->logins();
        $ultimo = (new AgendaDaDemo($this->caminhos))->ultimo();

        $r = new ReiniciadorDaDemo($this->caminhos, null, static function (): void {
            throw new \RuntimeException('falha simulada');
        });
        try {
            $r->reiniciar();
            self::fail('deveria falhar');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('restaurado', $e->getMessage());
        }
        self::assertSame($antes, $this->logins());
        self::assertSame('original', file_get_contents($this->caminhos->uploads() . '/arquivo.txt'));
        self::assertFalse(Manutencao::ativa($this->caminhos));
        self::assertSame($ultimo, (new AgendaDaDemo($this->caminhos))->ultimo());
        self::assertFileExists($this->caminhos->config() . '/instalado.lock');
    }

    public function testTravaImpedeExecucaoSimultanea(): void
    {
        $this->ligarDemo();
        mkdir($this->caminhos->dados() . '/demo', 0775, true);
        $trava = fopen($this->caminhos->dados() . '/demo/reiniciar.lock', 'c');
        self::assertIsResource($trava);
        self::assertTrue(flock($trava, LOCK_EX | LOCK_NB));
        try {
            (new ReiniciadorDaDemo($this->caminhos))->reiniciar();
            self::fail('deveria recusar');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('em andamento', $e->getMessage());
        } finally {
            fclose($trava);
        }
        self::assertContains('editor', $this->logins());
    }

    public function testPaginaDeManutencaoEstatica503(): void
    {
        Manutencao::ligar($this->caminhos, 'teste');
        $r = $this->req('GET', '/');
        self::assertSame(503, $r->getStatusCode());
        self::assertStringContainsString('manutenção', (string) $r->getContent());
        self::assertSame('60', $r->headers->get('Retry-After'));
    }

    public function testAvisoNoPainelSoEmDemo(): void
    {
        $html = $this->html($this->req('GET', '/_pbl/tarefas', $this->token('admin')));
        self::assertStringNotContainsString('pbl-demo-aviso', $html);
        $this->ligarDemo();
        $html = $this->html($this->req('GET', '/_pbl/tarefas', $this->token('admin')));
        self::assertStringContainsString('pbl-demo-aviso', $html);
    }
}
