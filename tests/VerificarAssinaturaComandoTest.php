<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Atualizacao\Assinatura;
use Pbl\Console\VerificarAssinaturaComando;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** `bin/pbl verificar-assinatura`: o ensaio do dono antes de publicar. Só chaves descartáveis, geradas na hora. */
final class VerificarAssinaturaComandoTest extends TestCase
{
    private string $pasta;
    /** @var array{publica: string, privada: string} */
    private array $uso;
    /** @var array{publica: string, privada: string} */
    private array $rec;

    protected function setUp(): void
    {
        $this->pasta = Ajuda::pastaTemporaria();
        $this->uso = Assinatura::gerarPar();
        $this->rec = Assinatura::gerarPar();
        file_put_contents($this->pasta . '/latest.json', '{"versao":"0.1.0"}');
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->pasta);
    }

    private function assinar(string $privada): void
    {
        file_put_contents($this->pasta . '/latest.json.sig', Assinatura::assinar((string) file_get_contents($this->pasta . '/latest.json'), $privada) . "\n");
    }

    /** @param array<string, string> $opcoes */
    private function rodar(array $opcoes = [], ?VerificarAssinaturaComando $c = null): CommandTester
    {
        $c ??= new VerificarAssinaturaComando([$this->uso['publica'], $this->rec['publica']], [$this->rec['publica']], [], []);
        $t = new CommandTester($c);
        $t->execute(['arquivo' => $this->pasta . '/latest.json'] + $opcoes);

        return $t;
    }

    public function testAceitaAssinaturaDaChaveDeUsoEDaRecuperacaoDizendoQuemAssinou(): void
    {
        $this->assinar($this->uso['privada']);
        $t = $this->rodar();
        self::assertSame(0, $t->getStatusCode());
        self::assertStringContainsString('chave de uso', $t->getDisplay());

        $this->assinar($this->rec['privada']);
        $t = $this->rodar();
        self::assertSame(0, $t->getStatusCode());
        self::assertStringContainsString('chave de recuperação', $t->getDisplay());
        self::assertStringNotContainsString($this->rec['privada'], $t->getDisplay());
    }

    public function testRecusaManifestoAdulteradoChaveDesconhecidaERevogada(): void
    {
        $this->assinar($this->uso['privada']);
        file_put_contents($this->pasta . '/latest.json', '{"versao":"9.9.9"}');
        self::assertSame(1, $this->rodar()->getStatusCode(), 'adulterado depois de assinado');

        file_put_contents($this->pasta . '/latest.json', '{"versao":"0.1.0"}');
        $this->assinar(Assinatura::gerarPar()['privada']);
        self::assertSame(1, $this->rodar()->getStatusCode(), 'chave desconhecida');

        $this->assinar($this->uso['privada']);
        $revogada = new VerificarAssinaturaComando([$this->uso['publica']], [], [$this->uso['publica']], []);
        self::assertSame(1, $this->rodar([], $revogada)->getStatusCode(), 'chave revogada');
    }

    public function testSemAssinaturaOuComCanalInvalidoFalha(): void
    {
        self::assertSame(1, $this->rodar()->getStatusCode(), '.sig ausente');
        $this->assinar($this->uso['privada']);
        self::assertSame(1, $this->rodar(['--canal' => 'x'])->getStatusCode());
    }

    public function testCanalDeTesteSoConfiaNaChaveDeTesteEAssinaturaPodeVirDeOutroArquivo(): void
    {
        $teste = Assinatura::gerarPar();
        $c = new VerificarAssinaturaComando([$this->uso['publica']], [], [], [$teste['publica']]);
        $this->assinar($teste['privada']);
        rename($this->pasta . '/latest.json.sig', $this->pasta . '/outra.sig');

        self::assertSame(0, $this->rodar(['--canal' => 'teste', '--assinatura' => $this->pasta . '/outra.sig'], $c)->getStatusCode());
        self::assertSame(1, $this->rodar(['--canal' => 'estavel', '--assinatura' => $this->pasta . '/outra.sig'], $c)->getStatusCode());
    }
}
