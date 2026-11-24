<?php

declare(strict_types=1);

namespace Pbl\Testes\Manual;

use Pbl\Manual\PedidoV2;
use PHPUnit\Framework\TestCase;

/** Contrato do pedido de captura que NÓS geramos (o executor é da esteira da prévia). */
final class PedidoV2Test extends TestCase
{
    /** @return list<array<string, string>> */
    private function capturas(int $n): array
    {
        return array_map(static fn (int $i): array => ['id' => 'tela-' . $i, 'pagina_do_painel' => '/_pbl/conteudo', 'papel' => $i % 2 === 0 ? 'admin' : 'editor'], range(1, $n));
    }

    public function testMontaPedidosV2ValidosEmLotesDeOito(): void
    {
        $pedidos = PedidoV2::montar($this->capturas(10));
        self::assertCount(2, $pedidos);
        self::assertCount(8, $pedidos[0]['paginas']);
        self::assertCount(2, $pedidos[1]['paginas']);
        foreach ($pedidos as $p) {
            self::assertSame(['versao', 'raiz', 'modo', 'docroot', 'gravaveis', 'preparo', 'paginas', 'viewports', 'pagina_inteira', 'resumo'], array_keys($p), 'só campos do schema v2');
            self::assertSame(2, $p['versao']);
            self::assertSame('php', $p['modo']);
            self::assertLessThanOrEqual(180, strlen($p['resumo']));
            self::assertLessThanOrEqual(8192, strlen((string) json_encode($p)));
            self::assertSame(['gerar_pacote', 'extrair_pacote'], array_column($p['preparo'], 'acao'));
            self::assertSame('previa/gerar-pacote.php', $p['preparo'][0]['script']);
            foreach ($p['paginas'] as $pg) {
                self::assertSame(['caminho', 'nome'], array_keys($pg));
                self::assertMatchesRegularExpression('/^[a-z0-9-]{1,40}$/', $pg['nome']);
                self::assertLessThanOrEqual(120, strlen($pg['caminho']));
                self::assertStringStartsWith('/_pbl/previa-entrar?conta=', $pg['caminho']);
            }
        }
        self::assertSame('/_pbl/previa-entrar?conta=editor&ir=/_pbl/conteudo', $pedidos[0]['paginas'][0]['caminho']);
    }

    public function testRecusaEntradaInvalida(): void
    {
        foreach ([['id' => 'Ruim Id'], ['pagina_do_painel' => '/fora'], ['pagina_do_painel' => '/_pbl/../x'], ['papel' => 'dono']] as $troca) {
            try {
                PedidoV2::montar([$troca + ['id' => 'ok', 'pagina_do_painel' => '/_pbl/', 'papel' => 'admin']]);
                self::fail('devia recusar');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testOScriptDeLinhaDeComandoGravaOsPedidos(): void
    {
        $pasta = sys_get_temp_dir() . '/pbl4-pedidos-' . bin2hex(random_bytes(4));
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../manual/capturas.php') . ' ' . escapeshellarg($pasta) . ' 2>&1', $saida, $codigo);
        self::assertSame(0, $codigo, implode("\n", $saida));
        $total = count(json_decode((string) file_get_contents(__DIR__ . '/../../docs/usuario/capturas.json'), true)['capturas']);
        $paginas = 0;
        foreach (glob($pasta . '/previa-pedido-*.json') ?: [] as $f) {
            $paginas += count(json_decode((string) file_get_contents($f), true)['paginas']);
            unlink($f);
        }
        rmdir($pasta);
        self::assertSame($total, $paginas);
    }
}
