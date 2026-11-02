<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Atualizacao\Assinatura;
use Pbl\Atualizacao\Atualizador;
use Pbl\Atualizacao\BaixadorLocal;
use Pbl\Atualizacao\Empacotador;
use Pbl\Caminhos;
use Pbl\Console\ReverterComando;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** `bin/pbl reverter`: sem `--confirmar` só mostra o que faria; com `--confirmar`, desfaz de verdade. */
final class ReverterComandoTest extends TestCase
{
    /** @var list<string> */
    private array $limpar = [];

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
        file_put_contents($raiz . '/app/src/Versao.php', "<?php\nfinal class Versao { public const ATUAL = '{$versao}'; }\n");
        file_put_contents($raiz . '/app/src/marcador.txt', $marcador);
        file_put_contents($raiz . '/vendor/autoload.php', '<?php');
        mkdir($raiz . '/bin', 0775, true);
        file_put_contents($raiz . '/bin/pbl', '#!/usr/bin/env php');
        chmod($raiz . '/bin/pbl', 0755);
        file_put_contents($raiz . '/composer.json', '{"require":{"php":">=8.2"}}');
        file_put_contents($raiz . '/composer.lock', '{}');
        file_put_contents($raiz . '/LICENSE', 'MIT');

        return $raiz;
    }

    /** Uma atualização de verdade concluída de 0.1.0 para 0.2.0, devolvendo a raiz. */
    private function instalacaoAtualizada(): string
    {
        $par = Assinatura::gerarPar();
        $origem = $this->projeto('0.2.0', 'codigo novo');
        $saida = Ajuda::raizDeProjeto();
        $this->limpar[] = $saida;
        $manifesto = (new Empacotador())->empacotar($origem, $saida, $par['privada'], 'estavel')['manifesto'];
        $raiz = $this->projeto('0.1.0', 'codigo velho');
        mkdir($raiz . '/dados', 0775, true);
        $a = new Atualizador(new Caminhos($raiz), new BaixadorLocal(), [$par['publica']], '0.1.0', static function (): void {
        }, '8.3.0');
        $a->aplicar($a->verificar($manifesto), $manifesto);

        return $raiz;
    }

    /** @return \Closure(string, string): void */
    private function conferirNoOp(): \Closure
    {
        return static function (): void {
        };
    }

    public function testSemConfirmarSoMostraOQueFariaENaoMudaNada(): void
    {
        $raiz = $this->instalacaoAtualizada();
        $t = new CommandTester(new ReverterComando(new Caminhos($raiz), '0.2.0', $this->conferirNoOp()));
        self::assertSame(0, $t->execute([]));
        self::assertStringContainsString('Dá para voltar da 0.2.0 para a 0.1.0', $t->getDisplay());
        self::assertStringContainsString('--confirmar', $t->getDisplay());
        self::assertSame('codigo novo', file_get_contents($raiz . '/app/src/marcador.txt'), 'nada foi alterado');
    }

    public function testComConfirmarRevertaDeVerdade(): void
    {
        $raiz = $this->instalacaoAtualizada();
        $t = new CommandTester(new ReverterComando(new Caminhos($raiz), '0.2.0', $this->conferirNoOp()));
        self::assertSame(0, $t->execute(['--confirmar' => true]));
        self::assertStringContainsString('Revertido de 0.2.0 para 0.1.0', $t->getDisplay());
        self::assertSame('codigo velho', file_get_contents($raiz . '/app/src/marcador.txt'));
    }

    public function testSemAtualizacaoConcluidaFalhaComMotivo(): void
    {
        $raiz = $this->projeto('0.1.0', 'x');
        $t = new CommandTester(new ReverterComando(new Caminhos($raiz), '0.1.0', $this->conferirNoOp()));
        self::assertSame(1, $t->execute([]));
        self::assertStringContainsString('Não há atualização concluída', $t->getDisplay());
    }
}
