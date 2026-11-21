<?php

declare(strict_types=1);

namespace Pbl\Testes\Backup;

use Pbl\Atualizacao\Trava;
use Pbl\Backup\ConfigDeBackup;
use Pbl\Backup\EstadoDoBackup;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use Pbl\Caminhos;
use Pbl\Console\BackupComando;
use Pbl\Cron\Tarefas\BackupAgendadoTarefa;
use Pbl\Servicos;
use Pbl\Testes\Ajuda;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class BackupAgendadoTarefaTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $servicos;
    private int $agora;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::pastaTemporaria();
        $this->caminhos = new Caminhos($this->raiz);
        $this->caminhos->garantirDados();
        $c = Conexao::sqlite($this->caminhos->banco());
        (new Migrador($c))->migrar();
        $this->servicos = new Servicos($this->caminhos, $c);
        $this->agora = (new \DateTimeImmutable('2026-03-10 03:10', new \DateTimeZone('America/Sao_Paulo')))->getTimestamp();
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function tarefa(): BackupAgendadoTarefa
    {
        return new BackupAgendadoTarefa(fn (): int => $this->agora);
    }

    public function testDesativadaPorPadraoESemTrabalho(): void
    {
        self::assertFalse($this->tarefa()->temTrabalho($this->servicos));
        self::assertSame('desativada', $this->tarefa()->frequenciaTexto($this->servicos));
        self::assertSame('backup', $this->tarefa()->id());
        self::assertFalse($this->tarefa()->leve());
    }

    public function testAtivaNoHorarioCriaUmaVezEGravaEstado(): void
    {
        (new ConfigDeBackup(true, 'diaria', '03:00'))->gravar($this->caminhos);
        self::assertTrue($this->tarefa()->temTrabalho($this->servicos));

        $r = $this->tarefa()->executar($this->servicos, microtime(true) + 50);

        self::assertFalse($r->parcial);
        self::assertCount(1, glob($this->raiz . '/dados/backups/*-agendada-*.zip') ?: []);
        self::assertTrue(EstadoDoBackup::ler($this->caminhos)['ok']);
        self::assertFalse($this->tarefa()->temTrabalho($this->servicos), 'mesmo horário não repete');
        self::assertGreaterThan($this->agora, $this->tarefa()->proximaExecucao($this->servicos));
    }

    public function testComTravaOcupadaSaiEmSilencioSemEstado(): void
    {
        (new ConfigDeBackup(true, 'diaria', '03:00'))->gravar($this->caminhos);
        $trava = Trava::obter($this->caminhos);
        try {
            $r = $this->tarefa()->executar($this->servicos, microtime(true) + 50);
        } finally {
            Trava::soltar($trava);
        }

        self::assertTrue($r->parcial);
        self::assertSame([], glob($this->raiz . '/dados/backups/*.zip') ?: []);
        self::assertTrue($this->tarefa()->temTrabalho($this->servicos), 'tenta de novo no minuto seguinte');
    }

    public function testComandoCriarListarLimparERestaurarExigeConfirmo(): void
    {
        $t = new CommandTester(new BackupComando($this->caminhos, fn (): int => $this->agora));

        self::assertSame(0, $t->execute(['acao' => 'criar']));
        self::assertStringContainsString('Cópia criada', $t->getDisplay());
        $t->execute(['acao' => 'listar']);
        self::assertStringContainsString('manual', $t->getDisplay());
        self::assertStringContainsString('íntegra', $t->getDisplay());
        $nome = basename((glob($this->raiz . '/dados/backups/*.zip') ?: [''])[0]);

        $t->execute(['acao' => 'restaurar', 'arquivo' => $nome]);
        self::assertStringContainsString('Nada foi alterado', $t->getDisplay());
        self::assertSame(0, $t->execute(['acao' => 'limpar', '--simular' => true]));
        self::assertSame(1, $t->execute(['acao' => 'enviar']));
        self::assertSame(2, $t->execute(['acao' => 'xyz']));
        self::assertSame(1, $t->execute(['acao' => 'restaurar', 'arquivo' => '../x.zip', '--confirmo' => true]));
    }
}
