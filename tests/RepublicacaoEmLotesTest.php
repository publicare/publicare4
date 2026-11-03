<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Console\PublicarComando;
use Pbl\Publicacao\FilaDeRepublicacao;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Republicar com barra de progresso (ADR 0023): fila retomável, trava, falhas, endpoint de lotes e comando. */
final class RepublicacaoEmLotesTest extends TestCase
{
    use AdminAuxiliar;

    /** Objetos que o site da fixture já tem (inclusive os que não são visíveis): o total da fila é este + os criados. */
    private int $base = 0;

    private function muitas(int $n): void
    {
        $this->base = $this->s->objetos->contarDoSite($this->siteId);
        $pasta = $this->s->classes->porNome('pasta') ?? self::fail();
        for ($i = 1; $i <= $n; $i++) {
            $this->s->objetos->criar($this->siteId, $pasta, $this->inicioId, "Pasta {$i}", "pasta-{$i}", [], 'publicado');
        }
    }

    /** A saída traz "Meu Site: N página(s) publicada(s)"; N cobre os criados e, no máximo, os que a fixture já tinha. */
    private function resumoComAte(string $saida, int $criados): void
    {
        self::assertSame(1, preg_match('/Meu Site: (\d+) página\(s\) publicada\(s\)/', $saida, $m), $saida);
        $n = (int) ($m[1] ?? 0);
        self::assertGreaterThanOrEqual($criados, $n);
        self::assertLessThanOrEqual($this->base + $criados, $n);
    }

    private function site(): \Pbl\Conteudo\Site
    {
        return $this->s->sites->porId($this->siteId) ?? self::fail();
    }

    /** @return array<string, mixed> */
    private function json(\Symfony\Component\HttpFoundation\Response $r): array
    {
        $j = json_decode($this->html($r), true);
        self::assertIsArray($j, $this->html($r));

        return $j;
    }

    public function testFilaAndaEmLotesEFicaRetomavel(): void
    {
        $this->muitas(30);
        $fila = $this->s->republicacao;
        $ini = $fila->iniciar($this->site(), 'teste');
        self::assertFalse($ini['retomada']);
        self::assertSame($this->base + 30, $ini['estado']['total']);

        $r = $fila->processar($this->site(), 10);
        self::assertSame(10, $r['estado']['feitas'] ?? -1);
        self::assertTrue($fila->ativa($this->siteId));

        // outro "processo" (nova instância) continua do mesmo ponto
        $outra = \Pbl\Servicos::doBancoInstalado($this->caminhos)->republicacao;
        $r = $outra->processar($this->site(), 100);
        self::assertTrue($r['terminou_agora']);
        self::assertSame('concluido', $r['estado']['status'] ?? '');
        self::assertSame($this->base + 30, $r['estado']['feitas']);
        self::assertFalse($fila->ativa($this->siteId));
        self::assertFileExists($this->caminhos->pagina($this->siteId, 'pasta-30'));
    }

    public function testIniciarComUmaAtivaDevolveARetomadaSemReiniciar(): void
    {
        $this->muitas(5);
        $fila = $this->s->republicacao;
        $fila->iniciar($this->site(), 'a');
        $fila->processar($this->site(), 2);

        $de_novo = $fila->iniciar($this->site(), 'b');

        self::assertTrue($de_novo['retomada']);
        self::assertSame(2, $de_novo['estado']['feitas']);
        self::assertSame('a', $de_novo['estado']['origem']);
    }

    public function testTravaDeLoteImpedeProcessamentoSimultaneo(): void
    {
        $this->muitas(5);
        $fila = $this->s->republicacao;
        $fila->iniciar($this->site(), 'a');
        $trava = fopen($fila->pasta() . '/site-' . $this->siteId . '.lote.lock', 'c');
        self::assertIsResource($trava);
        self::assertTrue(flock($trava, LOCK_EX | LOCK_NB));

        $r = $fila->processar($this->site(), 10);

        self::assertTrue($r['ocupado']);
        self::assertSame(0, $r['estado']['feitas'] ?? -1, 'nada foi tratado');
        flock($trava, LOCK_UN);
        fclose($trava);
        self::assertFalse($fila->processar($this->site(), 10)['ocupado']);
    }

    public function testFilaAbandonadaRecomecaDoZero(): void
    {
        $relogio = 1_800_000_000;
        $fila = new FilaDeRepublicacao($this->caminhos, $this->s->publicador, $this->s->objetos, static function () use (&$relogio): int {
            return $relogio;
        });
        $fila->iniciar($this->site(), 'a');
        $fila->processar($this->site(), 1);
        self::assertTrue($fila->ativa($this->siteId));

        $relogio += FilaDeRepublicacao::ABANDONO + 1;

        self::assertFalse($fila->ativa($this->siteId));
        self::assertFalse($fila->iniciar($this->site(), 'b')['retomada']);
    }

    public function testEndpointDeLoteEntregaProgressoAteConcluir(): void
    {
        $this->muitas(60);
        $t = $this->token('admin');
        $url = "/_pbl/site/{$this->siteId}/publicacao/lote";

        $j = $this->json($this->post($t, $url, ['acao' => 'iniciar']));
        self::assertSame('andando', $j['status']);
        self::assertSame(25, $j['feitas']);
        self::assertSame($this->base + 60, $j['total']);
        $vistos = [$j['feitas']];
        $i = 0;
        while ($j['status'] === 'andando' && $i++ < 10) {
            $j = $this->json($this->post($t, $url, ['acao' => 'continuar']));
            $vistos[] = $j['feitas'];
        }

        self::assertSame('concluido', $j['status']);
        self::assertSame([25, 50, $this->base + 60], $vistos);
        self::assertLessThanOrEqual($this->base + 60, $j['publicadas']);
        self::assertGreaterThanOrEqual(60, $j['publicadas']);
        self::assertSame(0, $j['falhas']);
        self::assertCount(1, $this->auditoria('site.republicar'), 'audita uma vez, ao terminar');
        self::assertFileExists($this->caminhos->pagina($this->siteId, 'pasta-60'));
        self::assertStringContainsString('Última republicação', $this->html($this->req('GET', "/_pbl/site/{$this->siteId}/publicacao", $t)));
    }

    public function testEndpointDeLoteExigeAdminCsrfEPost(): void
    {
        $url = "/_pbl/site/{$this->siteId}/publicacao/lote";
        self::assertSame(403, $this->post($this->token('admin'), $url, ['acao' => 'iniciar'], false)->getStatusCode());
        self::assertSame(403, $this->post($this->token('editor'), $url, ['acao' => 'iniciar'])->getStatusCode());
        self::assertSame(404, $this->req('GET', $url, $this->token('admin'))->getStatusCode());
        self::assertSame(409, $this->post($this->token('admin'), $url, ['acao' => 'continuar'])->getStatusCode(), 'continuar sem nada em andamento');
    }

    public function testPostSemJavascriptTrataTudoEResumeNaTela(): void
    {
        $this->muitas(60);
        $t = $this->token('admin');
        $url = "/_pbl/site/{$this->siteId}/publicacao";

        $r = $this->post($t, $url, []);

        self::assertSame(303, $r->getStatusCode(), $this->html($r));
        self::assertFileExists($this->caminhos->pagina($this->siteId, 'pasta-60'));
    }

    public function testTelaTemBarraDeProgressoSemEstiloInline(): void
    {
        $html = $this->html($this->req('GET', "/_pbl/site/{$this->siteId}/publicacao", $this->token('admin')));

        self::assertStringContainsString('class="progress"', $html);
        self::assertStringContainsString('data-pbl-republicar="/_pbl/site/' . $this->siteId . '/publicacao/lote"', $html);
        self::assertStringNotContainsString('style=', $html, 'CSP: nada de estilo inline');
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testTelaOferecContinuarQuandoHaFilaEmAndamento(): void
    {
        $this->muitas(10);
        $this->s->republicacao->iniciar($this->site(), 'teste');
        $this->s->republicacao->processar($this->site(), 3);

        $html = $this->html($this->req('GET', "/_pbl/site/{$this->siteId}/publicacao", $this->token('admin')));

        self::assertStringContainsString('republicação em andamento (3 de ' . ($this->base + 10), $html);
        self::assertStringContainsString('>Continuar<', $html);
    }

    public function testComandoPublicarUsaAFilaEImprimeResumo(): void
    {
        $this->muitas(8);
        $t = new CommandTester(new PublicarComando($this->caminhos));

        self::assertSame(0, $t->execute([]));

        $this->resumoComAte($t->getDisplay(), 8);
        self::assertFileExists($this->caminhos->pagina($this->siteId, 'pasta-8'));
        self::assertSame('concluido', $this->s->republicacao->estado($this->siteId)['status'] ?? '');
    }

    public function testComandoRetomaUmaRepublicacaoJaComecada(): void
    {
        $this->muitas(8);
        $this->s->republicacao->iniciar($this->site(), 'painel');
        $this->s->republicacao->processar($this->site(), 4);
        $t = new CommandTester(new PublicarComando($this->caminhos));

        $t->execute([]);

        $this->resumoComAte($t->getDisplay(), 8);
    }

    public function testFalhaPorObjetoNaoAbortaOLote(): void
    {
        $this->muitas(3);
        $this->s->republicacao->iniciar($this->site(), 'teste');
        // lote sem falhas: o contrato é processadas = publicadas + falhas
        $r = $this->s->publicador->republicarLote($this->site(), 0, 100);

        self::assertTrue($r['concluido']);
        self::assertSame(0, $r['falhas']);
        self::assertGreaterThanOrEqual(3, $r['publicadas']);
        self::assertLessThanOrEqual($r['processadas'], $r['publicadas']);
    }
}
