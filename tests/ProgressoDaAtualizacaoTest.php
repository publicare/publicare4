<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Atualizacao\ArquivoAtomico;
use Pbl\Atualizacao\Assinatura;
use Pbl\Atualizacao\Atualizador;
use Pbl\Atualizacao\BaixadorLocal;
use Pbl\Atualizacao\Empacotador;
use Pbl\Atualizacao\ErroDeAtualizacao;
use Pbl\Atualizacao\Etapa;
use Pbl\Atualizacao\PedidoDeAtualizacao;
use Pbl\Atualizacao\ProgressoEmArquivo;
use Pbl\Atualizacao\SaneadorDeMensagem;
use Pbl\Atualizacao\SituacaoDaAtualizacao;
use Pbl\Caminhos;
use Pbl\Cron\EstadoDoCron;
use PHPUnit\Framework\TestCase;

/** `progresso.json` (atômico, 0600, limitado, sem caminhos), a situação que a página lê e a limpeza seletiva do `Atualizador`. */
final class ProgressoDaAtualizacaoTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;
    private int $agora = 1_800_000_000;
    /** @var list<string> */
    private array $limpar = [];

    protected function setUp(): void
    {
        $this->raiz = Ajuda::pastaTemporaria();
        $this->caminhos = new Caminhos($this->raiz);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
        foreach ($this->limpar as $p) {
            Ajuda::apagar($p);
        }
    }

    private function registro(string $origem = 'cron'): ProgressoEmArquivo
    {
        return new ProgressoEmArquivo($this->caminhos, $origem, fn (): int => $this->agora);
    }

    private function situacao(?EstadoDoCron $cron = null): SituacaoDaAtualizacao
    {
        return new SituacaoDaAtualizacao($this->caminhos, fn (): int => $this->agora, $cron ?? new EstadoDoCron($this->caminhos, fn (): int => $this->agora));
    }

    public function testGravaEtapasComPercentualMensagemInicioEFim(): void
    {
        $r = $this->registro();
        $r->iniciar('atualizar');
        $p = ProgressoEmArquivo::ler($this->caminhos);
        self::assertNotNull($p);
        self::assertSame(Etapa::Preparando, $p->etapa);
        self::assertSame($this->agora, $p->inicio);
        self::assertNull($p->fim);

        $this->agora += 10;
        $r->etapa(Etapa::Baixando);
        $p = ProgressoEmArquivo::ler($this->caminhos);
        self::assertNotNull($p);
        self::assertSame('Baixando a nova versão', $p->mensagem);
        self::assertSame(15, $p->percentual);
        self::assertSame($this->agora - 10, $p->inicio, 'o início não muda');

        $this->agora += 5;
        $r->concluir();
        $p = ProgressoEmArquivo::ler($this->caminhos);
        self::assertNotNull($p);
        self::assertSame(100, $p->percentual);
        self::assertSame($this->agora, $p->fim);
        self::assertSame('0600', substr(sprintf('%o', fileperms(ProgressoEmArquivo::arquivo($this->caminhos))), -4));
        self::assertSame('0750', substr(sprintf('%o', fileperms($this->raiz . '/dados/atualizacao')), -4));
    }

    public function testEtapasSeguemUmaOrdemCrescente(): void
    {
        $ordem = [Etapa::Preparando, Etapa::Baixando, Etapa::Conferindo, Etapa::Extraindo, Etapa::CopiaBanco, Etapa::TrocandoCodigo, Etapa::MigrandoBanco, Etapa::ConferindoVersao, Etapa::Concluido];
        $anterior = -1;
        foreach ($ordem as $e) {
            self::assertGreaterThan($anterior, $e->percentual(), $e->value);
            self::assertNotSame('', $e->mensagem());
            $anterior = $e->percentual();
        }
    }

    public function testFalhaGuardaOMotivoSaneadoEOUltimoPercentual(): void
    {
        $r = $this->registro();
        $r->iniciar('atualizar');
        $r->etapa(Etapa::TrocandoCodigo);
        $r->falhar("Erro em {$this->raiz}/app/src/X.php: não achei /var/www/site/y e /home/ana/z\n\tpilha" . str_repeat(' longa', 200));

        $p = ProgressoEmArquivo::ler($this->caminhos);
        self::assertNotNull($p);
        self::assertSame(Etapa::Falhou, $p->etapa);
        self::assertSame(70, $p->percentual);
        self::assertNotNull($p->fim);
        self::assertStringNotContainsString($this->raiz, $p->falha);
        self::assertStringNotContainsString('/var/www', $p->falha);
        self::assertStringNotContainsString('/home/', $p->falha);
        self::assertStringNotContainsString("\n", $p->falha);
        self::assertLessThanOrEqual(300, mb_strlen($p->falha));
        self::assertStringContainsString('não achei', $p->falha);
    }

    public function testSaneadorMantemTextoComumEControlaTamanho(): void
    {
        self::assertSame('O pacote não confere (tamanho ou SHA-256).', SaneadorDeMensagem::sanear('O pacote não confere (tamanho ou SHA-256).', '/srv/pbl'));
        self::assertSame('falhou em …', SaneadorDeMensagem::sanear('falhou em /srv/pbl', '/srv/pbl'));
        self::assertSame(300, mb_strlen(SaneadorDeMensagem::sanear(str_repeat('á', 1000))));
    }

    public function testArquivoCorrompidoGrandeDemaisOuForaDoEsquemaViraSemDado(): void
    {
        $arquivo = ProgressoEmArquivo::arquivo($this->caminhos);
        mkdir(dirname($arquivo), 0775, true);

        file_put_contents($arquivo, '{"etapa": ');
        self::assertNull(ProgressoEmArquivo::ler($this->caminhos));

        file_put_contents($arquivo, json_encode(['origem' => 'cron', 'tipo' => 'atualizar', 'etapa' => 'rm -rf', 'percentual' => 1, 'mensagem' => 'm', 'inicio' => 1, 'fim' => null, 'falha' => '', 'atualizado' => 1]));
        self::assertNull(ProgressoEmArquivo::ler($this->caminhos), 'etapa desconhecida');

        $this->registro()->iniciar('atualizar');
        self::assertNotNull(ProgressoEmArquivo::ler($this->caminhos));
        file_put_contents($arquivo, (string) file_get_contents($arquivo) . str_repeat(' ', ProgressoEmArquivo::LIMITE));
        self::assertNull(ProgressoEmArquivo::ler($this->caminhos), 'mais de 8 KB');
        self::assertSame('ocioso', $this->situacao()->ler()['estado'], 'o endpoint trata como ocioso');
    }

    public function testGravarSemPermissaoNaoLancaExcecao(): void
    {
        file_put_contents($this->raiz . '/dados', 'um arquivo onde deveria haver pasta');
        $r = $this->registro();
        $r->iniciar('atualizar');
        $r->etapa(Etapa::Baixando);
        $r->falhar('x');

        self::assertNull(ProgressoEmArquivo::ler($this->caminhos));
    }

    public function testEscritaAtomicaNaoDeixaTemporarioNemArquivoMeioEscrito(): void
    {
        $r = $this->registro();
        $r->iniciar('atualizar');
        for ($i = 0; $i < 50; $i++) {
            $r->etapa(Etapa::Baixando, $i);
            self::assertNotNull(ProgressoEmArquivo::ler($this->caminhos), 'a cada instante o arquivo é um JSON completo');
        }
        self::assertSame([], glob($this->raiz . '/dados/atualizacao/*.tmp'));
        self::assertNotNull(ArquivoAtomico::ler(ProgressoEmArquivo::arquivo($this->caminhos), 8192));
    }

    // ------------------------------------------------------------ situação (o que o endpoint devolve)

    public function testSituacaoOciosaSemNada(): void
    {
        $s = $this->situacao()->ler();

        self::assertSame('ocioso', $s['estado']);
        self::assertFalse($s['cron_ativo'], 'o cron nunca rodou');
    }

    public function testSituacaoAgendadaAvisaDepoisDeCincoMinutos(): void
    {
        (new PedidoDeAtualizacao($this->caminhos, fn (): int => $this->agora))->agendar('atualizar', '0.2.0', 7, 'Ana');

        $s = $this->situacao()->ler();
        self::assertSame('agendada', $s['estado']);
        self::assertStringContainsString('Agendada: aguardando o próximo ciclo do cron (até 1 minuto)', $s['mensagem']);
        self::assertSame(0, $s['agendada_ha_s']);
        self::assertFalse($s['cron_sem_iniciar']);

        $this->agora += 301;
        $s = $this->situacao()->ler();
        self::assertSame('agendada', $s['estado']);
        self::assertTrue($s['cron_sem_iniciar']);
        self::assertSame(301, $s['agendada_ha_s']);
    }

    public function testSituacaoDePedidoVencidoSemCronDizQuePedidoVenceu(): void
    {
        (new PedidoDeAtualizacao($this->caminhos, fn (): int => $this->agora))->agendar('atualizar', '0.2.0', 7, 'Ana');
        $this->agora += 1800;

        $s = $this->situacao()->ler();

        self::assertSame('falhou', $s['estado']);
        self::assertStringContainsString('venceu', $s['falha']);
    }

    public function testSituacaoEmAndamentoUsaOProgressoDoCron(): void
    {
        $repo = new PedidoDeAtualizacao($this->caminhos, fn (): int => $this->agora);
        $repo->iniciar($repo->agendar('atualizar', '0.2.0', 7, 'Ana'));
        $s = $this->situacao()->ler();
        self::assertSame('em_andamento', $s['estado']);
        self::assertSame('preparando', $s['etapa'], 'o cron pegou o pedido, mas ainda não gravou o andamento');

        $r = $this->registro();
        $r->iniciar('atualizar');
        $this->agora += 42;
        $r->etapa(Etapa::TrocandoCodigo);
        $s = $this->situacao()->ler();
        self::assertSame('em_andamento', $s['estado']);
        self::assertSame('trocando_codigo', $s['etapa']);
        self::assertSame(70, $s['percentual']);
        self::assertSame(42, $s['decorrido_s']);
    }

    public function testSituacaoConcluidaEFalhouVindasDoTerminal(): void
    {
        $r = $this->registro('terminal');
        $r->iniciar('atualizar');
        $this->agora += 90;
        $r->concluir();
        $s = $this->situacao()->ler();
        self::assertSame('concluido', $s['estado']);
        self::assertSame(90, $s['decorrido_s']);
        self::assertSame(100, $s['percentual']);

        $r->iniciar('reverter');
        $r->falhar('O banco mudou');
        $s = $this->situacao()->ler();
        self::assertSame('falhou', $s['estado']);
        self::assertSame('reverter', $s['tipo']);
        self::assertSame('O banco mudou', $s['falha']);
    }

    public function testAndamentoSemSinalHaMaisDeUmaHoraViraFalha(): void
    {
        $r = $this->registro();
        $r->iniciar('atualizar');
        $this->agora += SituacaoDaAtualizacao::SEM_SINAL + 1;

        $s = $this->situacao()->ler();

        self::assertSame('falhou', $s['estado']);
        self::assertStringContainsString('Sem sinal de andamento', $s['falha']);
    }

    public function testSituacaoSoTemOsCamposDaListaFixaESemSegredos(): void
    {
        $repo = new PedidoDeAtualizacao($this->caminhos, fn (): int => $this->agora);
        $p = $repo->agendar('atualizar', '0.2.0', 7, 'Ana');
        $json = (string) json_encode($this->situacao()->ler());

        $esperado = ['estado', 'tipo', 'etapa', 'etapa_nome', 'percentual', 'mensagem', 'inicio', 'fim', 'decorrido_s', 'agendada_em', 'valida_ate', 'agendada_ha_s', 'falha', 'cron_ativo', 'cron_sem_iniciar'];
        $campos = array_keys($this->situacao()->ler());
        sort($esperado);
        sort($campos);
        self::assertSame($esperado, $campos);
        self::assertStringNotContainsString($p->nonce, $json);
        self::assertStringNotContainsString($this->raiz, $json);
        self::assertStringNotContainsString('Ana', $json);
    }

    public function testCronAtivoVemDoBatimentoDoCron(): void
    {
        $cron = new EstadoDoCron($this->caminhos, fn (): int => $this->agora);
        $cron->registrarCiclo('cron');

        self::assertTrue($this->situacao($cron)->ler()['cron_ativo']);
        $this->agora += EstadoDoCron::ATRASO_MAXIMO + 1;
        self::assertFalse($this->situacao($cron)->ler()['cron_ativo']);
    }

    // ------------------------------------------------------------ limpeza seletiva do Atualizador (R1)

    public function testAtualizadorNaoApagaPedidoNemProgressoMasLimpaOResto(): void
    {
        $par = Assinatura::gerarPar();
        $mk = function (string $versao): string {
            $r = Ajuda::raizDeProjeto();
            $this->limpar[] = $r;
            mkdir($r . '/app/src', 0775, true);
            mkdir($r . '/vendor', 0775, true);
            mkdir($r . '/bin', 0775, true);
            file_put_contents($r . '/app/src/Versao.php', "<?php\nfinal class Versao { public const ATUAL = '{$versao}'; }\n");
            file_put_contents($r . '/vendor/autoload.php', '<?php');
            file_put_contents($r . '/bin/pbl', '#!/usr/bin/env php');
            file_put_contents($r . '/composer.json', '{"require":{"php":">=8.2"}}');
            file_put_contents($r . '/composer.lock', '{}');
            file_put_contents($r . '/LICENSE', 'MIT');

            return $r;
        };
        $saida = Ajuda::raizDeProjeto();
        $this->limpar[] = $saida;
        $manifesto = (new Empacotador())->empacotar($mk('0.2.0'), $saida, $par['privada'], 'estavel', [])['manifesto'];
        $raiz = $mk('0.1.0');
        $c = new Caminhos($raiz);
        $pasta = $raiz . '/dados/atualizacao';
        mkdir($pasta . '/anterior/lixo', 0775, true);
        file_put_contents($pasta . '/pedido.json', 'P');
        file_put_contents($pasta . '/progresso.json', 'G');
        file_put_contents($pasta . '/progresso.json.ab12.tmp', 'T');
        file_put_contents($pasta . '/sobra-de-antes.txt', 'S');
        $vistoDentro = [];
        $a = new Atualizador($c, new BaixadorLocal(), [$par['publica']], '0.1.0', static function () use ($pasta, &$vistoDentro): void {
            $vistoDentro = [is_file($pasta . '/pedido.json'), is_file($pasta . '/sobra-de-antes.txt'), is_dir($pasta . '/anterior/lixo')];
        }, '8.3.0');

        $a->aplicar($a->verificar($manifesto), $manifesto);

        self::assertSame([true, false, false], $vistoDentro);
        self::assertSame('P', file_get_contents($pasta . '/pedido.json'));
        self::assertSame('G', file_get_contents($pasta . '/progresso.json'));
        self::assertFileExists($pasta . '/progresso.json.ab12.tmp');
        self::assertFileDoesNotExist($pasta . '/sobra-de-antes.txt');
    }

    public function testFalhaDeHashTambemPreservaPedidoEProgresso(): void
    {
        $par = Assinatura::gerarPar();
        $origem = Ajuda::raizDeProjeto();
        $this->limpar[] = $origem;
        foreach (['app/src', 'vendor', 'bin'] as $d) {
            mkdir("$origem/$d", 0775, true);
        }
        file_put_contents("$origem/app/src/Versao.php", "<?php\nfinal class Versao { public const ATUAL = '0.2.0'; }\n");
        file_put_contents("$origem/vendor/autoload.php", '<?php');
        file_put_contents("$origem/bin/pbl", 'x');
        file_put_contents("$origem/composer.json", '{"require":{"php":">=8.2"}}');
        file_put_contents("$origem/composer.lock", '{}');
        file_put_contents("$origem/LICENSE", 'MIT');
        $saida = Ajuda::raizDeProjeto();
        $this->limpar[] = $saida;
        $manifesto = (new Empacotador())->empacotar($origem, $saida, $par['privada'], 'estavel', [])['manifesto'];
        $zip = glob($saida . '/*.zip') ?: [];
        self::assertNotEmpty($zip);
        file_put_contents($zip[0], 'x', FILE_APPEND);
        $raiz = Ajuda::raizDeProjeto();
        $this->limpar[] = $raiz;
        mkdir($raiz . '/app/src', 0775, true);
        file_put_contents($raiz . '/app/src/Versao.php', "<?php\nfinal class Versao { public const ATUAL = '0.1.0'; }\n");
        $c = new Caminhos($raiz);
        $registro = new ProgressoEmArquivo($c, 'terminal', fn (): int => $this->agora);
        mkdir($raiz . '/dados/atualizacao', 0775, true);
        file_put_contents($raiz . '/dados/atualizacao/pedido.json', 'P');
        $a = new Atualizador($c, new BaixadorLocal(), [$par['publica']], '0.1.0', static function (): void {
        }, '8.3.0', progresso: $registro);

        try {
            $a->aplicar($a->verificar($manifesto), $manifesto);
            self::fail('deveria recusar o pacote adulterado');
        } catch (ErroDeAtualizacao) {
        }

        self::assertSame('P', file_get_contents($raiz . '/dados/atualizacao/pedido.json'));
        $p = ProgressoEmArquivo::ler($c);
        self::assertNotNull($p);
        self::assertSame(Etapa::Falhou, $p->etapa);
    }
}
