<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Atualizacao\Assinatura;
use Pbl\Atualizacao\Atualizador;
use Pbl\Atualizacao\BaixadorLocal;
use Pbl\Atualizacao\Empacotador;
use Pbl\Atualizacao\ErroDeAtualizacao;
use Pbl\Atualizacao\ExecutorDePedido;
use Pbl\Atualizacao\PedidoAgendado;
use Pbl\Atualizacao\PedidoDeAtualizacao;
use Pbl\Atualizacao\ProgressoEmArquivo;
use Pbl\Atualizacao\RegistroDeProgresso;
use Pbl\Atualizacao\Reversor;
use Pbl\Atualizacao\Trava;
use Pbl\Caminhos;
use PHPUnit\Framework\TestCase;

/**
 * O cron executa o pedido agendado: mesma rotina do `bin/pbl atualizar` / `reverter --confirmar`, reconferindo assinatura, SHA-256 e
 * versão. Relógio e processo de conferência falsos; pacote assinado com um par de chaves de TESTE gerado em memória.
 */
final class AtualizacaoAgendadaCronTest extends TestCase
{
    private const AGORA = 1_800_000_000;

    /** @var list<string> */
    private array $limpar = [];
    /** @var array{publica: string, privada: string} */
    private array $par;
    private int $agora = self::AGORA;
    /** @var list<array{0: string, 1: int, 2: array<string, mixed>}> */
    private array $auditoria = [];
    /** @var list<array<string, mixed>> o progresso.json visto de dentro da troca */
    private array $vistoNaTroca = [];
    /** @var list<bool> o pedido.json existia durante a troca? */
    private array $pedidoNaTroca = [];

    protected function setUp(): void
    {
        $this->par = Assinatura::gerarPar();
    }

    protected function tearDown(): void
    {
        foreach ($this->limpar as $p) {
            Ajuda::apagar($p);
        }
    }

    private function projeto(string $versao, string $marcador): string
    {
        $raiz = Ajuda::raizDeProjeto();
        $this->limpar[] = $raiz;
        mkdir($raiz . '/app/src', 0775, true);
        mkdir($raiz . '/vendor', 0775, true);
        mkdir($raiz . '/bin', 0775, true);
        file_put_contents($raiz . '/app/src/Versao.php', "<?php\nfinal class Versao { public const ATUAL = '{$versao}'; }\n");
        file_put_contents($raiz . '/app/src/marcador.txt', $marcador);
        file_put_contents($raiz . '/vendor/autoload.php', '<?php');
        file_put_contents($raiz . '/bin/pbl', '#!/usr/bin/env php');
        chmod($raiz . '/bin/pbl', 0755);
        file_put_contents($raiz . '/composer.json', '{"require":{"php":">=8.2"}}');
        file_put_contents($raiz . '/composer.lock', '{}');
        file_put_contents($raiz . '/LICENSE', 'MIT');

        return $raiz;
    }

    /** @return string caminho do manifesto assinado da versão */
    private function publicar(string $versao, ?string $chavePrivada = null): string
    {
        $origem = $this->projeto($versao, 'codigo ' . $versao);
        $saida = Ajuda::raizDeProjeto();
        $this->limpar[] = $saida;

        return (new Empacotador())->empacotar($origem, $saida, $chavePrivada ?? $this->par['privada'], 'estavel', [])['manifesto'];
    }

    /** Instalação em 0.1.0 com o endereço de atualização apontando para o manifesto. */
    private function instalada(string $manifesto): string
    {
        $raiz = $this->projeto('0.1.0', 'codigo 0.1.0');
        mkdir($raiz . '/dados/config', 0775, true);
        file_put_contents($raiz . '/dados/config/atualizacao.json', json_encode(['url' => $manifesto, 'canal' => 'estavel']));

        return $raiz;
    }

    private function pedidos(string $raiz): PedidoDeAtualizacao
    {
        return new PedidoDeAtualizacao(new Caminhos($raiz), fn (): int => $this->agora);
    }

    private function executor(string $raiz, string $versaoInstalada = '0.1.0', ?string $chave = null, ?\Closure $conferir = null): ExecutorDePedido
    {
        $c = new Caminhos($raiz);
        $conferirNovo = $conferir ?? function () use ($raiz): void {
            $this->vistoNaTroca[] = json_decode((string) file_get_contents($raiz . '/dados/atualizacao/progresso.json'), true);
            $this->pedidoNaTroca[] = is_file($raiz . '/dados/atualizacao/pedido.json');
        };

        return new ExecutorDePedido(
            $c,
            $this->pedidos($raiz),
            fn (RegistroDeProgresso $p): Atualizador => new Atualizador($c, new BaixadorLocal(), [$chave ?? $this->par['publica']], $versaoInstalada, $conferirNovo, '8.3.0', progresso: $p),
            fn (RegistroDeProgresso $p): Reversor => new Reversor($c, $versaoInstalada, static function (): void {
            }, fn (): int => $this->agora, $p),
            function (string $acao, int $uid, array $detalhe): void {
                $this->auditoria[] = [$acao, $uid, $detalhe];
            },
            fn (): int => $this->agora,
        );
    }

    /** @return array<string, mixed> */
    private function progresso(string $raiz): array
    {
        $d = json_decode((string) file_get_contents($raiz . '/dados/atualizacao/progresso.json'), true);
        self::assertIsArray($d);

        return $d;
    }

    private function versaoDe(string $raiz): string
    {
        return preg_match("/ATUAL = '([^']+)'/", (string) file_get_contents($raiz . '/app/src/Versao.php'), $m) === 1 ? $m[1] : '?';
    }

    private function falha(ExecutorDePedido $e): string
    {
        try {
            $e->executar();
        } catch (ErroDeAtualizacao $x) {
            return $x->getMessage();
        }
        self::fail('deveria ter recusado');
    }

    public function testCronAplicaOPedidoGravaOAndamentoEPreservaOsArquivosDuranteATroca(): void
    {
        $raiz = $this->instalada($this->publicar('0.2.0'));
        $this->pedidos($raiz)->agendar('atualizar', '0.2.0', 7, 'Ana');
        $executor = $this->executor($raiz);

        self::assertTrue($executor->temTrabalho());
        $resumo = $executor->executar();

        self::assertSame('Atualizado de 0.1.0 para 0.2.0.', $resumo);
        self::assertSame('0.2.0', $this->versaoDe($raiz));
        self::assertFalse($this->pedidos($raiz)->existe(), 'pedido consumido');
        $p = $this->progresso($raiz);
        self::assertSame('concluido', $p['etapa']);
        self::assertSame(100, $p['percentual']);
        self::assertSame('cron', $p['origem']);
        self::assertSame('atualizar', $p['tipo']);
        self::assertSame('', $p['falha']);
        self::assertNotNull($p['fim']);
        // R1: dentro da troca (limpar() já rodou) o andamento e o pedido em andamento continuavam lá
        self::assertSame('migrando_banco', $this->vistoNaTroca[0]['etapa']);
        self::assertSame([true], $this->pedidoNaTroca);
        self::assertSame([['atualizacao.agendada.executar', 7, ['tipo' => 'atualizar', 'para' => '0.2.0']]], $this->auditoria);
        self::assertSame('0600', substr(sprintf('%o', fileperms($raiz . '/dados/atualizacao/progresso.json')), -4));
    }

    public function testSemPedidoNaoHaTrabalho(): void
    {
        $raiz = $this->instalada($this->publicar('0.2.0'));

        self::assertFalse($this->executor($raiz)->temTrabalho());
    }

    public function testVersaoDiferenteDaDoManifestoNaoAplicaNada(): void
    {
        $raiz = $this->instalada($this->publicar('0.2.0'));
        $this->pedidos($raiz)->agendar('atualizar', '0.3.0', 7, 'Ana');

        $msg = $this->falha($this->executor($raiz));

        self::assertStringContainsString('A versão disponível mudou de 0.3.0 para 0.2.0', $msg);
        self::assertSame('0.1.0', $this->versaoDe($raiz));
        self::assertSame('codigo 0.1.0', file_get_contents($raiz . '/app/src/marcador.txt'));
        self::assertFalse($this->pedidos($raiz)->existe());
        $p = $this->progresso($raiz);
        self::assertSame('falhou', $p['etapa']);
        self::assertStringContainsString('mudou', $p['falha']);
        self::assertSame('atualizacao.agendada.falhou', $this->auditoria[0][0]);
    }

    public function testPedidoVencidoNaoAplicaNada(): void
    {
        $raiz = $this->instalada($this->publicar('0.2.0'));
        $this->pedidos($raiz)->agendar('atualizar', '0.2.0', 7, 'Ana');
        $this->agora += PedidoAgendado::VALIDADE;

        $msg = $this->falha($this->executor($raiz));

        self::assertStringContainsString('venceu', $msg);
        self::assertSame('0.1.0', $this->versaoDe($raiz));
        self::assertFalse($this->pedidos($raiz)->existe());
        self::assertSame('falhou', $this->progresso($raiz)['etapa']);
        self::assertStringContainsString('venceu', $this->progresso($raiz)['falha']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function pedidosForjados(): array
    {
        $ok = ['tipo' => 'atualizar', 'versao_alvo' => '0.2.0', 'por_id' => 7, 'por_nome' => 'Ana', 'em' => self::AGORA, 'valido_ate' => self::AGORA + 1800, 'nonce' => str_repeat('ab', 16), 'estado' => 'aguardando', 'iniciada_em' => 0];

        return [
            'sem nonce' => [array_diff_key($ok, ['nonce' => 1])],
            'nonce curto' => [['nonce' => 'abc'] + $ok],
            'nonce não hexadecimal' => [['nonce' => str_repeat('g', 32)] + $ok],
            'tipo desconhecido' => [['tipo' => 'formatar'] + $ok],
            'campo extra' => [$ok + ['url_do_pacote' => 'https://mal.example/x.zip']],
            'validade esticada' => [['valido_ate' => self::AGORA + 86400] + $ok],
        ];
    }

    /** @param array<string, mixed> $forjado */
    #[\PHPUnit\Framework\Attributes\DataProvider('pedidosForjados')]
    public function testPedidoForjadoEhDescartadoSemAplicarNada(array $forjado): void
    {
        $raiz = $this->instalada($this->publicar('0.2.0'));
        mkdir($raiz . '/dados/atualizacao', 0775, true);
        file_put_contents($raiz . '/dados/atualizacao/pedido.json', json_encode($forjado));
        $executor = $this->executor($raiz);

        self::assertTrue($executor->temTrabalho());
        $msg = $this->falha($executor);

        self::assertStringContainsString('ilegível ou fora do formato', $msg);
        self::assertSame('0.1.0', $this->versaoDe($raiz));
        self::assertFalse($this->pedidos($raiz)->existe());
        self::assertSame('falhou', $this->progresso($raiz)['etapa']);
    }

    public function testAssinaturaDeOutraChaveRecusaMesmoComPedidoValido(): void
    {
        $outra = Assinatura::gerarPar();
        $raiz = $this->instalada($this->publicar('0.2.0', $outra['privada']));
        $this->pedidos($raiz)->agendar('atualizar', '0.2.0', 7, 'Ana');

        $msg = $this->falha($this->executor($raiz));

        self::assertStringContainsString('assinatura', $msg);
        self::assertSame('0.1.0', $this->versaoDe($raiz));
        self::assertSame('falhou', $this->progresso($raiz)['etapa']);
    }

    public function testPacoteAdulteradoNoServidorEhRecusadoPeloHash(): void
    {
        $manifesto = $this->publicar('0.2.0');
        $raiz = $this->instalada($manifesto);
        $zips = glob(dirname($manifesto) . '/*.zip') ?: [];
        self::assertNotEmpty($zips);
        file_put_contents($zips[0], 'pacote trocado por outro conteúdo', FILE_APPEND);
        $this->pedidos($raiz)->agendar('atualizar', '0.2.0', 7, 'Ana');

        $msg = $this->falha($this->executor($raiz));

        self::assertSame('0.1.0', $this->versaoDe($raiz));
        self::assertNotSame('', $msg);
        self::assertSame('falhou', $this->progresso($raiz)['etapa']);
    }

    public function testTravaOcupadaDeixaOPedidoParaOProximoCiclo(): void
    {
        $raiz = $this->instalada($this->publicar('0.2.0'));
        $pedido = $this->pedidos($raiz)->agendar('atualizar', '0.2.0', 7, 'Ana');
        $trava = Trava::obter(new Caminhos($raiz));
        try {
            $r = $this->executor($raiz)->executar();
        } finally {
            Trava::soltar($trava);
        }

        self::assertStringContainsString('outra atualização em andamento', mb_strtolower($r));
        self::assertEquals($pedido, $this->pedidos($raiz)->ler(), 'o pedido segue intacto');
        self::assertSame('0.1.0', $this->versaoDe($raiz));
        self::assertFileDoesNotExist($raiz . '/dados/atualizacao/progresso.json', 'nada sobrescreve o andamento de quem está rodando');

        self::assertSame('Atualizado de 0.1.0 para 0.2.0.', $this->executor($raiz)->executar());
    }

    public function testPedidoEmAndamentoSemNinguemRodandoEhDescartado(): void
    {
        $raiz = $this->instalada($this->publicar('0.2.0'));
        $repo = $this->pedidos($raiz);
        $repo->iniciar($repo->agendar('atualizar', '0.2.0', 7, 'Ana'));

        $msg = $this->falha($this->executor($raiz));

        self::assertStringContainsString('interrompido', $msg);
        self::assertSame('0.1.0', $this->versaoDe($raiz));
        self::assertFalse($repo->existe());
    }

    public function testFalhaNaTrocaMostraMotivoSemCaminhosDoServidor(): void
    {
        $raiz = $this->instalada($this->publicar('0.2.0'));
        $this->pedidos($raiz)->agendar('atualizar', '0.2.0', 7, 'Ana');
        $executor = $this->executor($raiz, '0.1.0', null, static function () use ($raiz): void {
            throw new \RuntimeException("PDOException em {$raiz}/app/src/Banco/Migrador.php e em /home/usuario/web/site.com/public_html/x.php: tabela ausente");
        });

        $msg = $this->falha($executor);

        $falha = $this->progresso($raiz)['falha'];
        self::assertIsString($falha);
        self::assertStringContainsString('tabela ausente', $falha);
        self::assertStringNotContainsString($raiz, $falha);
        self::assertStringNotContainsString('/home/', $falha);
        self::assertNotSame('', $msg);
        self::assertLessThanOrEqual(300, mb_strlen($falha));
        self::assertSame('0.1.0', $this->versaoDe($raiz), 'o código antigo voltou');
    }

    public function testReverterPeloCronConfereAVersaoDoPedido(): void
    {
        $raiz = $this->instalada($this->publicar('0.2.0'));
        $this->pedidos($raiz)->agendar('atualizar', '0.2.0', 7, 'Ana');
        $this->executor($raiz)->executar();
        self::assertSame('0.2.0', $this->versaoDe($raiz));

        // pedido de volta atrás para uma versão que não é a registrada: recusado, nada muda
        $this->pedidos($raiz)->agendar('reverter', '0.0.9', 7, 'Ana');
        $msg = $this->falha($this->executor($raiz, '0.2.0'));
        self::assertStringContainsString('mudou', $msg);
        self::assertSame('0.2.0', $this->versaoDe($raiz));

        // e com a versão certa, volta
        $this->pedidos($raiz)->agendar('reverter', '0.1.0', 7, 'Ana');
        $resumo = $this->executor($raiz, '0.2.0')->executar();

        self::assertSame('Revertido de 0.2.0 para 0.1.0.', $resumo);
        self::assertSame('0.1.0', $this->versaoDe($raiz));
        $p = $this->progresso($raiz);
        self::assertSame('concluido', $p['etapa']);
        self::assertSame('reverter', $p['tipo']);
        self::assertSame('cron', $p['origem']);
        self::assertFalse($this->pedidos($raiz)->existe());
    }

    public function testReverterSemAtualizacaoConcluidaFalhaComOMotivoDoReversor(): void
    {
        $raiz = $this->instalada($this->publicar('0.2.0'));
        $this->pedidos($raiz)->agendar('reverter', '0.1.0', 7, 'Ana');

        $msg = $this->falha($this->executor($raiz));

        self::assertStringContainsString('Não há atualização concluída', $msg);
        self::assertSame('falhou', $this->progresso($raiz)['etapa']);
        self::assertSame('reverter', $this->progresso($raiz)['tipo']);
    }

    public function testAtualizacaoPeloTerminalTambemGravaOAndamento(): void
    {
        $manifesto = $this->publicar('0.2.0');
        $raiz = $this->instalada($manifesto);
        $c = new Caminhos($raiz);
        $a = new Atualizador($c, new BaixadorLocal(), [$this->par['publica']], '0.1.0', static function (): void {
        }, '8.3.0', progresso: new ProgressoEmArquivo($c, 'terminal', fn (): int => $this->agora));

        $a->aplicar($a->verificar($manifesto), $manifesto);

        $p = ProgressoEmArquivo::ler($c);
        self::assertNotNull($p);
        self::assertSame('terminal', $p->origem);
        self::assertSame('concluido', $p->etapa->value);
        self::assertSame(100, $p->percentual);
    }
}
