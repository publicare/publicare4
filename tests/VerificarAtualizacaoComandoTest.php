<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Atualizacao\Assinatura;
use Pbl\Atualizacao\BaixadorLocal;
use Pbl\Atualizacao\Empacotador;
use Pbl\Atualizacao\EstadoDoPainel;
use Pbl\Caminhos;
use Pbl\Console\VerificarAtualizacaoComando;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** `bin/pbl verificar-atualizacao`: atualiza o cache de 24h para o cron; idempotente e silencioso quando não há nada novo. */
final class VerificarAtualizacaoComandoTest extends TestCase
{
    /** @var list<string> */
    private array $limpar = [];
    private string $raiz;
    private Caminhos $caminhos;
    /** @var array{publica: string, privada: string} */
    private array $par;
    private int $agora = 1_800_000_000;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        $this->caminhos->garantirDados();
        $this->par = Assinatura::gerarPar();
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
        foreach ($this->limpar as $p) {
            Ajuda::apagar($p);
        }
    }

    private function projetoENovaVersao(string $versao, bool $seguranca = false): string
    {
        $raiz = Ajuda::raizDeProjeto();
        $this->limpar[] = $raiz;
        mkdir($raiz . '/app/src', 0775, true);
        mkdir($raiz . '/vendor', 0775, true);
        mkdir($raiz . '/bin', 0775, true);
        file_put_contents($raiz . '/app/src/Versao.php', "<?php\nfinal class Versao { public const ATUAL = '{$versao}'; }\n");
        file_put_contents($raiz . '/vendor/autoload.php', '<?php');
        file_put_contents($raiz . '/bin/pbl', '#!/usr/bin/env php');
        file_put_contents($raiz . '/composer.json', '{"require":{"php":">=8.2"}}');
        file_put_contents($raiz . '/composer.lock', '{}');
        file_put_contents($raiz . '/LICENSE', 'MIT');
        $saida = Ajuda::raizDeProjeto();
        $this->limpar[] = $saida;

        return (new Empacotador())->empacotar($raiz, $saida, $this->par['privada'], 'estavel', ['seguranca' => $seguranca])['manifesto'];
    }

    private function configurar(string $url): void
    {
        file_put_contents($this->caminhos->config() . '/atualizacao.json', json_encode(['url' => $url, 'canal' => 'estavel']));
    }

    private function comando(string $versaoInstalada = '0.1.0'): CommandTester
    {
        return new CommandTester(new VerificarAtualizacaoComando($this->caminhos, new BaixadorLocal(), [$this->par['publica']], $versaoInstalada, fn (): int => $this->agora));
    }

    private function estado(): EstadoDoPainel
    {
        return new EstadoDoPainel($this->caminhos, fn (): int => $this->agora);
    }

    public function testGuardaOCacheQueAPaginaInicialEATelaDeAtualizacaoLeem(): void
    {
        $manifesto = $this->projetoENovaVersao('0.2.0', seguranca: true);
        $this->configurar($manifesto);
        $t = $this->comando('0.1.0');

        self::assertSame(0, $t->execute([]));
        self::assertStringContainsString('Nova versão disponível: 0.2.0', $t->getDisplay());

        $cache = $this->estado()->verificacaoRecente($manifesto, 'estavel', '0.1.0');
        self::assertNotNull($cache);
        self::assertTrue($cache['ok']);
        self::assertTrue($cache['disponivel']);
        self::assertNotNull($cache['manifesto']);
        self::assertSame('0.2.0', $cache['manifesto']['versao']);
    }

    public function testIdempotenteNaoConsultaDeNovoDentroDas24Horas(): void
    {
        $manifesto = $this->projetoENovaVersao('0.2.0');
        $this->configurar($manifesto);
        $t = $this->comando('0.1.0');
        self::assertSame(0, $t->execute([]));

        // apaga o manifesto: se consultasse de novo, falharia
        unlink($manifesto);
        self::assertSame(0, $t->execute([]), 'ainda dentro do cache: nem tenta consultar');
    }

    public function testSilenciosoQuandoNaoHaVersaoNova(): void
    {
        $manifesto = $this->projetoENovaVersao('0.1.0'); // mesma versão instalada: nada de novo
        $this->configurar($manifesto);
        $t = $this->comando('0.1.0');
        self::assertSame(0, $t->execute([]));
        self::assertSame('', trim($t->getDisplay()), 'silencioso: nada para o cron poluir o log');
    }

    public function testSemUrlOuVerificacaoDesligadaOuSemChaveNaoConsultaNada(): void
    {
        // sem url configurada
        self::assertSame(0, $this->comando()->execute([]));
        self::assertNull($this->estado()->verificacaoRecente('', 'estavel', '0.1.0'));

        // verificação desligada
        $manifesto = $this->projetoENovaVersao('0.2.0');
        file_put_contents($this->caminhos->config() . '/atualizacao.json', json_encode(['url' => $manifesto, 'verificar' => false]));
        self::assertSame(0, $this->comando()->execute([]));
        self::assertNull($this->estado()->verificacaoRecente($manifesto, 'estavel', '0.1.0'));

        // sem chave (Chaves::PUBLICAS é vazia por padrão em produção; aqui simulado explicitamente)
        $this->configurar($manifesto);
        $t = new CommandTester(new VerificarAtualizacaoComando($this->caminhos, new BaixadorLocal(), [], '0.1.0'));
        self::assertSame(0, $t->execute([]));
        self::assertNull($this->estado()->verificacaoRecente($manifesto, 'estavel', '0.1.0'));
    }

    public function testFalhaDeConsultaGuardaOErroEDevolveCodigoDeFalha(): void
    {
        $this->configurar('/nao/existe/latest.json');
        $t = $this->comando('0.1.0');
        self::assertSame(1, $t->execute([]));
        self::assertStringContainsString('Arquivo não encontrado', $t->getDisplay());
        $cache = $this->estado()->verificacaoRecente('/nao/existe/latest.json', 'estavel', '0.1.0');
        self::assertNotNull($cache);
        self::assertFalse($cache['ok']);
    }
}
