<?php

declare(strict_types=1);

namespace Pbl\Testes\Manual;

use Pbl\Caminhos;
use Pbl\Console\ManualGerarComando;
use Pbl\Testes\Ajuda;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** `bin/pbl manual-gerar` numa cópia temporária de docs/usuario: etapa de compilação, recusa sem --confirmar e erro de manual. */
final class ManualGerarComandoTest extends TestCase
{
    private string $raiz;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::pastaTemporaria();
        $origem = dirname(__DIR__, 2);
        mkdir($this->raiz . '/docs/usuario', 0775, true);
        mkdir($this->raiz . '/manual', 0775, true);
        foreach (glob($origem . '/docs/usuario/*') ?: [] as $f) {
            copy($f, $this->raiz . '/docs/usuario/' . basename($f));
        }
        foreach (['tecnica', 'adr'] as $pasta) {
            mkdir($this->raiz . '/docs/' . $pasta, 0775, true);
            foreach (glob($origem . '/docs/' . $pasta . '/*.md') ?: [] as $f) {
                copy($f, $this->raiz . '/docs/' . $pasta . '/' . basename($f));
            }
        }
        foreach (glob($origem . '/*.md') ?: [] as $f) {
            copy($f, $this->raiz . '/' . basename($f));
        }
        copy($origem . '/manual/links-externos.json', $this->raiz . '/manual/links-externos.json');
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    /** @param array<string, mixed> $args @return array{0: int, 1: string} */
    private function rodar(array $args): array
    {
        $t = new CommandTester(new ManualGerarComando(new Caminhos($this->raiz)));
        $codigo = $t->execute($args);

        return [$codigo, $t->getDisplay()];
    }

    public function testSoCompilarGravaOPacoteDeterministico(): void
    {
        [$codigo, $saida] = $this->rodar(['--so-compilar' => true, '--sem-capturas' => true]);
        self::assertSame(0, $codigo, $saida);
        self::assertStringContainsString('6 capítulos', $saida);
        self::assertStringContainsString('capturas ausentes', $saida, 'o aviso do modo sem capturas');
        $a = (string) file_get_contents($this->raiz . '/manual/gerado/manual.json');
        $this->rodar(['--so-compilar' => true, '--sem-capturas' => true]);
        self::assertSame($a, (string) file_get_contents($this->raiz . '/manual/gerado/manual.json'), 'bytes idênticos');
        self::assertJson($a);
    }

    public function testSemCapturasNemModoDeDesenvolvimentoFalhaListandoAsFaltantes(): void
    {
        [$codigo, $saida] = $this->rodar(['--so-compilar' => true, '--json' => true]);
        self::assertSame(6, $codigo);
        self::assertStringContainsString('faltam as capturas de tela', $saida);
        self::assertStringContainsString('painel-inicio', $saida);
    }

    public function testCapturasDaPastaEntramNoPacote(): void
    {
        $pasta = $this->raiz . '/capt';
        mkdir($pasta);
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
        foreach (array_column(json_decode((string) file_get_contents($this->raiz . '/docs/usuario/capturas.json'), true)['capturas'], 'id') as $id) {
            file_put_contents("{$pasta}/{$id}.png", $png);
        }
        [$codigo, $saida] = $this->rodar(['--so-compilar' => true, '--capturas' => $pasta]);
        self::assertSame(0, $codigo, $saida);
        self::assertFileExists($this->raiz . '/manual/gerado/capturas/painel-inicio.png');
        self::assertStringNotContainsString('Aviso', $saida);
    }

    public function testSemConfirmarNemSimularNadaEFeito(): void
    {
        [$codigo, $saida] = $this->rodar(['--sem-capturas' => true, '--como' => 'x', '--json' => true]);
        self::assertSame(6, $codigo, $saida);
        self::assertStringContainsString('confirmacao_necessaria', $saida);
    }

    public function testManualInvalidoInterrompeComArquivoELinha(): void
    {
        file_put_contents($this->raiz . '/docs/usuario/glossario.md', "---\ntitulo: G\nresumo: R\ncapitulo: consulta\nordem: 40\n---\n\n# G\n\n[x](nada.md)\n");
        [$codigo, $saida] = $this->rodar(['--so-compilar' => true, '--sem-capturas' => true]);
        self::assertSame(6, $codigo);
        self::assertStringContainsString('glossario.md:10: link quebrado: nada.md', $saida);
    }

    public function testSemDocsUsoInvalido(): void
    {
        Ajuda::apagar($this->raiz . '/docs');
        [$codigo] = $this->rodar(['--so-compilar' => true]);
        self::assertSame(2, $codigo);
    }
}
