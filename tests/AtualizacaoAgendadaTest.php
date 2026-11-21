<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Atualizacao\Assinatura;
use Pbl\Atualizacao\Atualizador;
use Pbl\Atualizacao\Baixador;
use Pbl\Atualizacao\Empacotador;
use Pbl\Atualizacao\ErroDeAtualizacao;
use Pbl\Atualizacao\ExecutorDePedido;
use Pbl\Atualizacao\Manutencao;
use Pbl\Atualizacao\PedidoDeAtualizacao;
use Pbl\Atualizacao\ProgressoEmArquivo;
use Pbl\Atualizacao\RegistroDeProgresso;
use Pbl\Atualizacao\Reversor;
use Pbl\Atualizacao\SituacaoDaAtualizacao;
use Pbl\Banco\Conexao;
use Pbl\Caminhos;
use Pbl\Cron\Catalogo;
use Pbl\Cron\EstadoDoCron;
use Pbl\Cron\Executor;
use Pbl\Cron\Tarefas\AtualizacaoAgendadaTarefa;
use PHPUnit\Framework\TestCase;

/**
 * De ponta a ponta, sem Internet: o administrador agenda (o pedido do painel), a rodada do cron (`Cron\Executor`, o mesmo do
 * `bin/pbl cron`) executa a atualização da 0.1.0-rc.1 para a rc.2 com um pacote assinado com chave de TESTE e baixado de um servidor
 * HTTP local, o andamento chega a `concluido`, o pedido some, e depois uma volta atrás agendada devolve a rc.1.
 */
final class AtualizacaoAgendadaTest extends TestCase
{
    use AdminAuxiliar {
        setUp as private prepararBase;
        tearDown as private limparBase;
    }

    private const RC1 = '0.1.0-rc.1';
    private const RC2 = '0.1.0-rc.2';

    /** @var list<string> */
    private array $limpar = [];
    /** @var array{publica: string, privada: string} */
    private array $par;
    /** @var resource|null */
    private $servidor;
    private string $urlBase = '';
    private int $relogio = 1_800_000_000;
    /** @var list<string> */
    private array $etapasVistas = [];

    protected function setUp(): void
    {
        $this->prepararBase();
        $this->par = Assinatura::gerarPar();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->servidor)) {
            proc_terminate($this->servidor);
            proc_close($this->servidor);
        }
        foreach ($this->limpar as $p) {
            Ajuda::apagar($p);
        }
        $this->limparBase();
    }

    private function projeto(string $versao, string $marcador, bool $comBanco): string
    {
        $raiz = Ajuda::raizDeProjeto();
        $this->limpar[] = $raiz;
        foreach (['app/src', 'vendor', 'bin'] as $d) {
            mkdir("$raiz/$d", 0775, true);
        }
        file_put_contents($raiz . '/app/src/Versao.php', "<?php\nfinal class Versao { public const ATUAL = '{$versao}'; }\n");
        file_put_contents($raiz . '/app/src/marcador.txt', $marcador);
        file_put_contents($raiz . '/vendor/autoload.php', '<?php');
        file_put_contents($raiz . '/bin/pbl', '#!/usr/bin/env php');
        chmod($raiz . '/bin/pbl', 0755);
        file_put_contents($raiz . '/composer.json', '{"require":{"php":">=8.2"}}');
        file_put_contents($raiz . '/composer.lock', '{}');
        file_put_contents($raiz . '/LICENSE', 'MIT');
        if ($comBanco) {
            mkdir($raiz . '/dados/config', 0775, true);
            $c = Conexao::sqlite($raiz . '/dados/pbl4.sqlite');
            $c->executeStatement('CREATE TABLE schema_versao (versao INTEGER PRIMARY KEY)');
            $c->executeStatement('INSERT INTO schema_versao (versao) VALUES (1)');
        }

        return $raiz;
    }

    private function iniciarServidor(string $docroot): void
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            self::markTestSkipped('Sem como abrir uma porta local: ' . $errstr);
        }
        $porta = (int) explode(':', (string) stream_socket_get_name($sock, false))[1];
        fclose($sock);
        $p = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$porta}", '-t', $docroot], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($p)) {
            self::markTestSkipped('Não foi possível iniciar o servidor local.');
        }
        $this->servidor = $p;
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $porta, $e, $s, 0.2);
            if ($c !== false) {
                fclose($c);
                $this->urlBase = "http://127.0.0.1:{$porta}";

                return;
            }
            usleep(100_000);
        }
        self::markTestSkipped('O servidor local não respondeu.');
    }

    /** Baixador de teste: HTTP puro, só para 127.0.0.1 (o `BaixadorHttps` de produção continua exigindo https). */
    private function baixadorHttp(): Baixador
    {
        return new class () implements Baixador {
            public function obter(string $origem, int $maximoBytes): string
            {
                $t = @file_get_contents($origem, false, null, 0, max(0, $maximoBytes) + 1);
                if ($t === false || strlen($t) > $maximoBytes) {
                    throw new ErroDeAtualizacao("Não foi possível baixar {$origem}");
                }

                return $t;
            }

            public function copiar(string $origem, string $arquivoDestino, int $maximoBytes): void
            {
                file_put_contents($arquivoDestino, $this->obter($origem, $maximoBytes));
            }

            public function resolver(string $base, string $relativo): string
            {
                if (str_contains($relativo, '..') || !str_starts_with($relativo, '/')) {
                    throw new ErroDeAtualizacao('Endereço do pacote inválido');
                }
                $u = parse_url($base);

                return ($u['scheme'] ?? 'http') . '://' . ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : '') . $relativo;
            }
        };
    }

    /** Pasta que o servidor local publica, com a rc.2 assinada no canal beta (é pré-lançamento). */
    private function publicarRc2(): string
    {
        $origem = $this->projeto(self::RC2, 'codigo rc2', false);
        $saida = Ajuda::raizDeProjeto();
        $this->limpar[] = $saida;
        $r = (new Empacotador())->empacotar($origem, $saida, $this->par['privada'], 'beta', ['url_base' => '/pacotes/']);
        mkdir($saida . '/pacotes', 0775, true);
        rename($r['zip'], $saida . '/pacotes/' . basename($r['zip']));

        return $saida;
    }

    private function rodadaDoCron(string $raiz, string $versaoInstalada, bool $reverter = false): Executor
    {
        $c = new Caminhos($raiz);
        $conferir = function () use ($raiz): void {
            $p = ProgressoEmArquivo::ler(new Caminhos($raiz));
            $this->etapasVistas[] = $p === null ? 'sem andamento' : $p->etapa->value . ':' . ($p->origem);
            $this->etapasVistas[] = Manutencao::ativa(new Caminhos($raiz)) ? 'manutencao' : 'sem manutencao';
            // a migração da rc.2 roda dentro da troca, com o código novo
            Conexao::sqlite($raiz . '/dados/pbl4.sqlite')->executeStatement('INSERT INTO schema_versao (versao) VALUES (2)');
        };
        $executorDoPedido = new ExecutorDePedido(
            $c,
            new PedidoDeAtualizacao($c, fn (): int => $this->relogio),
            fn (RegistroDeProgresso $p): Atualizador => new Atualizador($c, $this->baixadorHttp(), [$this->par['publica']], $versaoInstalada, $conferir, '8.3.0', progresso: $p),
            fn (RegistroDeProgresso $p): Reversor => new Reversor($c, $versaoInstalada, static function (): void {
            }, fn (): int => $this->relogio, $p),
            static function (): void {
            },
            fn (): int => $this->relogio,
        );

        return new Executor($this->caminhos, $this->s, new EstadoDoCron($this->caminhos, fn (): int => $this->relogio), [new AtualizacaoAgendadaTarefa($executorDoPedido)]);
    }

    private function situacao(string $raiz): SituacaoDaAtualizacao
    {
        return new SituacaoDaAtualizacao(new Caminhos($raiz), fn (): int => $this->relogio, new EstadoDoCron($this->caminhos, fn (): int => $this->relogio));
    }

    public function testAgendaAtualizaPeloCronMostraOAndamentoEDepoisVoltaAtras(): void
    {
        $this->iniciarServidor($this->publicarRc2());
        $raiz = $this->projeto(self::RC1, 'codigo rc1', true);
        $c = new Caminhos($raiz);
        file_put_contents($raiz . '/dados/config/atualizacao.json', json_encode(['url' => $this->urlBase . '/latest-beta.json', 'canal' => 'beta']));
        $pedidos = new PedidoDeAtualizacao($c, fn (): int => $this->relogio);

        // 1. nada agendado: o cron não faz nada
        self::assertSame([], $this->rodadaDoCron($raiz, self::RC1)->rodar('cron', 30)['linhas']);
        self::assertSame('ocioso', $this->situacao($raiz)->ler()['estado']);

        // 2. o administrador agenda (o que o painel grava depois de senha, confirmação, CSRF e manifesto conferido)
        $pedidos->agendar('atualizar', self::RC2, $this->ids['admin'], 'Admin');
        $antes = $this->situacao($raiz)->ler();
        self::assertSame('agendada', $antes['estado']);
        self::assertSame('Agendada: aguardando o próximo ciclo do cron (até 1 minuto)', $antes['mensagem']);
        self::assertStringContainsString(self::RC1, (string) file_get_contents($raiz . '/app/src/Versao.php'), 'agendar não troca nada');

        // 3. a próxima rodada do cron executa
        $this->relogio += 60;
        $r = $this->rodadaDoCron($raiz, self::RC1)->rodar('cron', 30);
        self::assertFalse($r['ocupado']);
        self::assertCount(1, $r['linhas']);
        self::assertSame('ok', $r['linhas'][0]['resultado'], $r['linhas'][0]['resumo']);
        self::assertSame('Atualizado de ' . self::RC1 . ' para ' . self::RC2 . '.', $r['linhas'][0]['resumo']);
        self::assertStringContainsString(self::RC2, (string) file_get_contents($raiz . '/app/src/Versao.php'));
        self::assertSame('codigo rc2', file_get_contents($raiz . '/app/src/marcador.txt'));
        self::assertSame(2, (int) Conexao::sqlite($raiz . '/dados/pbl4.sqlite')->fetchOne('SELECT MAX(versao) FROM schema_versao'));
        self::assertSame(['migrando_banco:cron', 'manutencao'], $this->etapasVistas, 'durante a troca o andamento era do cron e o site estava em manutenção');
        self::assertFalse(Manutencao::ativa($c));
        self::assertFalse($pedidos->existe(), 'pedido consumido');
        $fim = $this->situacao($raiz)->ler();
        self::assertSame('concluido', $fim['estado']);
        self::assertSame(100, $fim['percentual']);
        self::assertSame('cron', ProgressoEmArquivo::ler($c)?->origem);

        // 4. a rodada seguinte não repete nada
        self::assertSame([], $this->rodadaDoCron($raiz, self::RC2)->rodar('cron', 30, null, true)['linhas']);

        // 5. volta atrás agendada: o pedido guarda a versão que será restaurada
        $pedidos->agendar('reverter', self::RC1, $this->ids['admin'], 'Admin');
        $volta = $this->rodadaDoCron($raiz, self::RC2)->rodar('cron', 31, null, true)['linhas'];
        self::assertCount(1, $volta);
        self::assertSame('ok', $volta[0]['resultado'], $volta[0]['resumo']);
        self::assertStringContainsString(self::RC1, (string) file_get_contents($raiz . '/app/src/Versao.php'));
        self::assertSame('codigo rc1', file_get_contents($raiz . '/app/src/marcador.txt'));
        self::assertSame(1, (int) Conexao::sqlite($raiz . '/dados/pbl4.sqlite')->fetchOne('SELECT MAX(versao) FROM schema_versao'), 'o banco voltou');
        $fimDaVolta = $this->situacao($raiz)->ler();
        self::assertSame('concluido', $fimDaVolta['estado']);
        self::assertSame('reverter', $fimDaVolta['tipo']);
        self::assertFalse($pedidos->existe());
    }

    public function testPedidoParaOutraVersaoNaoAplicaENaoDeixaMarcaDeManutencao(): void
    {
        $this->iniciarServidor($this->publicarRc2());
        $raiz = $this->projeto(self::RC1, 'codigo rc1', true);
        $c = new Caminhos($raiz);
        file_put_contents($raiz . '/dados/config/atualizacao.json', json_encode(['url' => $this->urlBase . '/latest-beta.json', 'canal' => 'beta']));
        (new PedidoDeAtualizacao($c, fn (): int => $this->relogio))->agendar('atualizar', '0.1.0-rc.9', $this->ids['admin'], 'Admin');

        $r = $this->rodadaDoCron($raiz, self::RC1)->rodar('cron', 30);

        self::assertSame('erro', $r['linhas'][0]['resultado']);
        self::assertStringContainsString('mudou', $r['linhas'][0]['resumo']);
        self::assertSame('codigo rc1', file_get_contents($raiz . '/app/src/marcador.txt'));
        self::assertFalse(Manutencao::ativa($c));
        $s = $this->situacao($raiz)->ler();
        self::assertSame('falhou', $s['estado']);
        self::assertStringNotContainsString($raiz, $s['falha']);
    }
}
