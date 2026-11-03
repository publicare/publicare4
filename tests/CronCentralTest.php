<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Console\CronComando;
use Pbl\Cron\Catalogo;
use Pbl\Cron\EstadoDoCron;
use Pbl\Cron\Executor;
use Pbl\Cron\PseudoCron;
use Pbl\Cron\Resultado;
use Pbl\Cron\Tarefa;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Cron central (ADR 0023): catálogo, frequência, "tem trabalho?", trava, estado, atraso, aviso, pseudo-cron, tela e comando. */
final class CronCentralTest extends TestCase
{
    use AdminAuxiliar;

    private int $relogio = 1_800_000_000;

    private function estado(): EstadoDoCron
    {
        return new EstadoDoCron($this->caminhos, fn (): int => $this->relogio);
    }

    /**
     * Tarefa de brinquedo que conta quantas vezes executou.
     *
     * @return Tarefa&object{execucoes: int, consultas: int}
     */
    private function falsa(string $id, int $intervalo, bool $trabalho, bool $leve = true, ?\Throwable $erro = null): Tarefa
    {
        return new class ($id, $intervalo, $trabalho, $leve, $erro) implements Tarefa {
            public int $execucoes = 0;
            public int $consultas = 0;

            public function __construct(private string $id, private int $intervalo, private bool $trabalho, private bool $leve, private ?\Throwable $erro)
            {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function nome(): string
            {
                return 'Tarefa ' . $this->id;
            }

            public function descricao(): string
            {
                return 'descricao';
            }

            public function intervalo(): int
            {
                return $this->intervalo;
            }

            public function leve(): bool
            {
                return $this->leve;
            }

            public function temTrabalho(Servicos $servicos): bool
            {
                $this->consultas++;

                return $this->trabalho;
            }

            public function executar(Servicos $servicos, float $prazo): Resultado
            {
                $this->execucoes++;
                if ($this->erro !== null) {
                    throw $this->erro;
                }

                return new Resultado('feito');
            }
        };
    }

    /** @param list<Tarefa> $tarefas */
    private function executor(array $tarefas): Executor
    {
        return new Executor($this->caminhos, $this->s, $this->estado(), $tarefas);
    }

    public function testCatalogoPadraoTemIdsUnicosEFrequencias(): void
    {
        $ids = array_map(static fn (Tarefa $t): string => $t->id(), Catalogo::padrao());

        self::assertSame(['agendados', 'republicacao', 'limpeza', 'verificar-atualizacao', 'uploads-orfaos'], $ids);
        foreach (Catalogo::padrao() as $t) {
            self::assertGreaterThanOrEqual(60, $t->intervalo());
            self::assertNotSame('', $t->nome());
        }
        self::assertNull(Catalogo::porId(Catalogo::padrao(), 'nao-existe'));
    }

    public function testRespeitaFrequenciaETemTrabalhoAntesDeExecutar(): void
    {
        $com = $this->falsa('com', 300, true);
        $sem = $this->falsa('sem', 300, false);
        $e = $this->executor([$com, $sem]);

        $r = $e->rodar('cron', 10);
        self::assertSame(1, $com->execucoes);
        self::assertSame(0, $sem->execucoes, 'sem trabalho não executa');
        self::assertSame(1, $sem->consultas);
        self::assertCount(1, $r['linhas'], 'saída enxuta: só o que fez algo');
        self::assertSame('ok', $r['linhas'][0]['resultado']);

        $this->relogio += 100; // ainda não venceu
        $e->rodar('cron', 10);
        self::assertSame(1, $com->execucoes);
        self::assertSame(1, $sem->consultas);

        $this->relogio += 250; // venceu
        $e->rodar('cron', 10);
        self::assertSame(2, $com->execucoes);
        self::assertSame(2, $sem->consultas);
    }

    public function testGravaEstadoComResultadoDuracaoEErroSemCaminho(): void
    {
        $erro = $this->falsa('quebra', 60, true, true, new \RuntimeException('falhou em ' . $this->raiz . '/dados/x'));
        $ok = $this->falsa('bom', 60, true);

        $r = $this->executor([$erro, $ok])->rodar('cron', 10);

        self::assertSame('erro', $r['linhas'][0]['resultado']);
        self::assertStringNotContainsString($this->raiz, $r['linhas'][0]['resumo']);
        self::assertSame('ok', $r['linhas'][1]['resultado'], 'uma tarefa que falha não impede as outras');
        $e = $this->estado()->tarefa('quebra');
        self::assertSame('erro', $e['resultado']);
        self::assertStringNotContainsString($this->raiz, $e['erro']);
        self::assertSame($this->relogio, $e['executada_em']);
        self::assertSame('ok', $this->estado()->tarefa('bom')['resultado']);
        self::assertFileExists($this->caminhos->dados() . '/cron/estado.json');
    }

    public function testTravaImpedeDuasRodadasAoMesmoTempo(): void
    {
        $t = $this->falsa('a', 60, true);
        mkdir($this->caminhos->dados() . '/cron', 0775, true);
        $trava = fopen($this->caminhos->dados() . '/cron/cron.lock', 'c');
        self::assertIsResource($trava);
        self::assertTrue(flock($trava, LOCK_EX | LOCK_NB));

        $r = $this->executor([$t])->rodar('cron', 10);

        self::assertTrue($r['ocupado']);
        self::assertSame(0, $t->execucoes);
        flock($trava, LOCK_UN);
        fclose($trava);
        self::assertFalse($this->executor([$t])->rodar('cron', 10)['ocupado']);
        self::assertSame(1, $t->execucoes);
    }

    public function testOrcamentoDeTempoDeixaORestoParaAProximaRodada(): void
    {
        $a = $this->falsa('a', 60, true);
        $b = $this->falsa('b', 60, true);
        $tempo = 100.0;
        $e = new Executor($this->caminhos, $this->s, $this->estado(), [$a, $b], static function () use (&$tempo): float {
            return $tempo += 10.0; // cada leitura do relógio gasta 10 s
        });

        $e->rodar('cron', 15);

        self::assertSame(1, $a->execucoes);
        self::assertSame(0, $b->execucoes, 'acabou o orçamento');
    }

    public function testSoLevesEApenasEForcar(): void
    {
        $leve = $this->falsa('leve', 3600, true, true);
        $pesada = $this->falsa('pesada', 3600, true, false);
        $e = $this->executor([$leve, $pesada]);

        $e->rodar('pseudo', 3, null, false, true);
        self::assertSame(1, $leve->execucoes);
        self::assertSame(0, $pesada->execucoes);

        $e->rodar('manual', 3, ['leve'], true);
        self::assertSame(2, $leve->execucoes, 'forçar ignora a frequência');
        self::assertSame(0, $pesada->execucoes, 'só a pedida');
    }

    public function testAtrasoDoCronEAvisoNaHome(): void
    {
        $admin = $this->s->usuarios->porId($this->ids['admin']) ?? self::fail();
        $editor = $this->s->usuarios->porId($this->ids['editor']) ?? self::fail();
        $e = $this->estado();

        self::assertTrue($e->cronAtrasado(), 'nunca rodou');
        self::assertStringContainsString('nunca rodou', \Pbl\Admin\TelasDeCron::avisoDaHome($this->s, $admin, fn (): int => $this->relogio));
        self::assertSame('', \Pbl\Admin\TelasDeCron::avisoDaHome($this->s, $editor, fn (): int => $this->relogio), 'só administrador');

        $e->registrarCiclo('cron');
        self::assertFalse($e->cronAtrasado());
        self::assertSame('', \Pbl\Admin\TelasDeCron::avisoDaHome($this->s, $admin, fn (): int => $this->relogio));

        $this->relogio += 299;
        self::assertFalse($this->estado()->cronAtrasado());
        $this->relogio += 2;
        self::assertTrue($this->estado()->cronAtrasado());
        self::assertStringContainsString('não roda há mais de', \Pbl\Admin\TelasDeCron::avisoDaHome($this->s, $admin, fn (): int => $this->relogio));

        $e->registrarCiclo('manual');
        $e->registrarCiclo('pseudo');
        self::assertTrue($this->estado()->cronAtrasado(), 'só o cron real conta');
    }

    public function testPseudoCronSoRodaLigadoEComCronAtrasadoEUsaSoLeves(): void
    {
        $leve = $this->falsa('leve', 60, true, true);
        $pesada = $this->falsa('pesada', 60, true, false);
        $est = $this->estado();

        self::assertFalse(PseudoCron::talvez($this->s, [$leve, $pesada], $est), 'desligado por padrão');
        self::assertSame(0, $leve->execucoes);

        $est->definirPseudo(true);
        self::assertTrue(PseudoCron::talvez($this->s, [$leve, $pesada], $est));
        self::assertSame(1, $leve->execucoes);
        self::assertSame(0, $pesada->execucoes);

        $est->registrarCiclo('cron');
        $this->relogio += 400;
        $est->registrarCiclo('cron'); // cron real em dia
        $this->relogio += 60;
        self::assertFalse(PseudoCron::talvez($this->s, [$leve], $est), 'cron em dia: pseudo não faz nada');
    }

    public function testPseudoCronNaoLancaNemRodaNasPaginasPublicas(): void
    {
        $quebra = $this->falsa('q', 60, true, true, new \RuntimeException('x'));
        $est = $this->estado();
        $est->definirPseudo(true);

        self::assertTrue(PseudoCron::talvez($this->s, [$quebra], $est), 'erro da tarefa fica no estado, não sobe');
        self::assertSame('erro', $est->tarefa('q')['resultado']);

        $this->estado()->definirPseudo(true);
        $this->publico();
        self::assertSame(0, $this->estado()->tarefa('agendados')['executada_em'], 'página pública não dispara o pseudo-cron');
    }

    private function publico(): void
    {
        (new \Pbl\Aplicacao($this->caminhos))->tratar(\Symfony\Component\HttpFoundation\Request::create('https://www.exemplo.org/'));
    }

    public function testPseudoCronNoPainelSoNoGet(): void
    {
        $this->estado()->definirPseudo(true);
        $token = $this->token('admin');

        $this->req('GET', '/_pbl/', $token);

        self::assertNotNull($this->estado()->ultimoCiclo('pseudo'), 'o GET no painel rodou uma rodada');
    }

    public function testVariavelDeAmbienteManda(): void
    {
        putenv('PBL_PSEUDO_CRON=1');
        try {
            self::assertTrue($this->estado()->pseudoLigado());
            self::assertTrue($this->estado()->pseudoPorAmbiente());
            putenv('PBL_PSEUDO_CRON=0');
            $this->estado()->definirPseudo(true);
            self::assertFalse($this->estado()->pseudoLigado());
        } finally {
            putenv('PBL_PSEUDO_CRON');
        }
    }

    public function testAgendadosTemTrabalhoEExecutaPelaTarefa(): void
    {
        $classe = $this->s->classes->porNome('pagina') ?? self::fail();
        $futura = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+1 day')->format('c');
        $o = $this->s->objetos->criar($this->siteId, $classe, $this->inicioId, 'Futura', 'futura', [], 'publicado', ['data_publicacao' => $futura, 'data_validade' => null]);
        $this->s->publicador->publicarSite($this->s->sites->porId($this->siteId) ?? self::fail());
        $t = Catalogo::porId(Catalogo::padrao(), 'agendados') ?? self::fail();

        self::assertFalse($t->temTrabalho($this->s));
        $this->s->conexao()->executeStatement('UPDATE objeto SET data_publicacao = ? WHERE id = ?', [(new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')))->format('c'), $o->id]);
        self::assertTrue($t->temTrabalho($this->s));

        $r = $t->executar($this->s, microtime(true) + 10);

        self::assertStringContainsString('1 entrou', $r->resumo);
        self::assertFileExists($this->caminhos->pagina($this->siteId, 'futura'));
        self::assertFalse($t->temTrabalho($this->s));
    }

    public function testRepublicacaoPendenteEContinuaEmRodadas(): void
    {
        $pasta = $this->s->classes->porNome('pasta') ?? self::fail();
        for ($i = 1; $i <= 12; $i++) {
            $this->s->objetos->criar($this->siteId, $pasta, $this->inicioId, "P {$i}", "p-{$i}", [], 'publicado');
        }
        $t = Catalogo::porId(Catalogo::padrao(), 'republicacao') ?? self::fail();
        self::assertFalse($t->temTrabalho($this->s));

        $this->s->menu->marcarPendente($this->siteId);
        self::assertTrue($t->temTrabalho($this->s));
        $r = $t->executar($this->s, microtime(true) + 30);

        self::assertFalse($r->parcial);
        self::assertFalse($t->temTrabalho($this->s), 'a mesma fila terminou e limpou a pendência');
        self::assertFileExists($this->caminhos->pagina($this->siteId, 'p-12'));
        self::assertCount(1, $this->auditoria('site.republicar'));
    }

    public function testLimpezaApagaSessoesExpiradasETemTrabalhoSoQuandoHa(): void
    {
        $t = Catalogo::porId(Catalogo::padrao(), 'limpeza') ?? self::fail();
        self::assertFalse($t->temTrabalho($this->s));
        $this->s->conexao()->executeStatement('INSERT INTO sessao (id_hash, usuario_id, criado_em, expira_em, ultimo_uso) VALUES (?, ?, 1, 2, 1)', [str_repeat('a', 64), $this->ids['admin']]);

        self::assertTrue($t->temTrabalho($this->s));
        $r = $t->executar($this->s, microtime(true) + 5);

        self::assertStringContainsString('1 sessão', $r->resumo);
        self::assertFalse($t->temTrabalho($this->s));
    }

    public function testVerificarAtualizacaoSemConfiguracaoNaoTemTrabalho(): void
    {
        $t = Catalogo::porId(Catalogo::padrao(), 'verificar-atualizacao') ?? self::fail();

        self::assertFalse($t->temTrabalho($this->s));
    }

    public function testComandoCronEmSilencioQuandoNadaAFazerEListar(): void
    {
        $t = new CommandTester(new CronComando($this->caminhos));

        self::assertSame(0, $t->execute([]));
        self::assertNotNull($this->estado()->ultimoCiclo('cron'), 'a rodada registra o batimento');
        $primeira = trim($t->getDisplay());
        self::assertSame(0, $t->execute([]));
        self::assertSame('', trim($t->getDisplay()), 'segunda rodada: nada a fazer, nada na saída (primeira: ' . $primeira . ')');

        self::assertSame(0, $t->execute(['--listar' => true]));
        self::assertStringContainsString('agendados', $t->getDisplay());
        self::assertStringContainsString('uploads-orfaos', $t->getDisplay());

        self::assertSame(2, $t->execute(['--tarefa' => 'inexistente']));
        self::assertStringContainsString('Tarefa desconhecida', $t->getDisplay());
    }

    public function testTelaDeTarefasSoParaAdministradorComCsrf(): void
    {
        $url = '/_pbl/tarefas';
        self::assertSame(303, $this->req('GET', $url)->getStatusCode());
        self::assertSame(403, $this->req('GET', $url, $this->token('editor'))->getStatusCode());
        $t = $this->token('admin');
        $r = $this->req('GET', $url, $t);
        self::assertSame(200, $r->getStatusCode());
        $html = $this->html($r);
        self::assertStringContainsString('Tarefas agendadas', $html);
        self::assertStringContainsString('Publicação e saída por data', $html);
        self::assertStringContainsString('bin/pbl cron', $html);
        self::assertStringContainsString('nunca rodou', $html);
        self::assertStringContainsString('href="/_pbl/tarefas"', $html, 'item de menu');

        self::assertSame(403, $this->post($t, $url . '/executar', ['tarefa' => 'limpeza'], false)->getStatusCode(), 'sem CSRF');
        self::assertSame(403, $this->post($this->token('editor'), $url . '/executar', ['tarefa' => 'limpeza'])->getStatusCode(), 'editor');
        self::assertSame(404, $this->req('GET', $url . '/x', $t)->getStatusCode());
        self::assertSame(404, $this->req('GET', $url . '/executar', $t)->getStatusCode());
        self::assertSame(422, $this->post($t, $url . '/executar', ['tarefa' => '../../x'])->getStatusCode());
    }

    public function testExecutarAgoraRodaAMesmaTarefaEAudita(): void
    {
        $t = $this->token('admin');
        $this->s->conexao()->executeStatement('INSERT INTO sessao (id_hash, usuario_id, criado_em, expira_em, ultimo_uso) VALUES (?, ?, 1, 2, 1)', [str_repeat('b', 64), $this->ids['admin']]);

        $r = $this->post($t, '/_pbl/tarefas/executar', ['tarefa' => 'limpeza']);

        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('1 sessão', $this->html($r));
        self::assertCount(1, $this->auditoria('cron.executar'));
        self::assertSame('ok', (new EstadoDoCron($this->caminhos))->tarefa('limpeza')['resultado']);
        self::assertStringContainsString('nada a fazer', $this->html($this->post($t, '/_pbl/tarefas/executar', ['tarefa' => 'limpeza'])));
        self::assertNull((new EstadoDoCron($this->caminhos))->ultimoCiclo('cron'), '"executar agora" não conta como o cron do servidor');
    }

    public function testLigarEDesligarPseudoCronPelaTela(): void
    {
        $t = $this->token('admin');

        $r = $this->post($t, '/_pbl/tarefas/pseudo', ['ligar' => '1']);
        self::assertStringContainsString('Pseudo-cron ligado', $this->html($r));
        self::assertTrue((new EstadoDoCron($this->caminhos))->pseudoLigado());
        self::assertStringContainsString('Pseudo-cron desligado', $this->html($this->post($t, '/_pbl/tarefas/pseudo', ['ligar' => '0'])));
        self::assertFalse((new EstadoDoCron($this->caminhos))->pseudoLigado());
        self::assertCount(2, $this->auditoria('cron.pseudo'));
    }

    public function testHomeDoAdminMostraAvisoDeCronParado(): void
    {
        $html = $this->html($this->req('GET', '/_pbl/', $this->token('admin')));
        self::assertStringContainsString('O cron do servidor nunca rodou', $html);
        $editor = $this->html($this->req('GET', '/_pbl/', $this->token('editor')));
        self::assertStringNotContainsString('cron do servidor', $editor);
    }
}
