<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Versao;
use PHPUnit\Framework\TestCase;

/** Controle de versão explícito (docs/versionamento.md): a versão é válida e o CHANGELOG a conhece. */
final class VersaoTest extends TestCase
{
    private const SEMVER = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+[0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*)?$/D';

    private function changelog(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
    }

    public function testVersaoAtualEUmVersionamentoSemanticoValido(): void
    {
        self::assertSame(1, preg_match(self::SEMVER, Versao::ATUAL), 'Versao::ATUAL = ' . Versao::ATUAL);
    }

    public function testChangelogTemASecaoDaVersaoOuNaoLancado(): void
    {
        $changelog = $this->changelog();
        $emDesenvolvimento = str_contains(Versao::ATUAL, '-dev');

        self::assertStringContainsString('## [Não lançado]', $changelog, 'o CHANGELOG sempre tem a seção "Não lançado"');
        if (!$emDesenvolvimento) {
            self::assertStringContainsString('## [' . Versao::ATUAL . '] - ', $changelog, 'versão publicada precisa de entrada datada no CHANGELOG');
        }
    }

    public function testVersoesDoChangelogEstaoEmOrdemDecrescenteEComData(): void
    {
        preg_match_all('/^## \[(\d+\.\d+\.\d+[^\]]*)\] - (\d{4}-\d{2}-\d{2})$/m', $this->changelog(), $m, PREG_SET_ORDER);
        $versoes = array_map(static fn (array $x): string => $x[1], $m);

        $ordenadas = $versoes;
        usort($ordenadas, static fn (string $a, string $b): int => version_compare($b, $a));
        self::assertSame($ordenadas, $versoes, 'versões do CHANGELOG da mais nova para a mais antiga');
        foreach ($m as $x) {
            self::assertNotFalse(strtotime($x[2]), "data inválida para {$x[1]}");
        }
    }

    public function testNaoLancadoVemAntesDeQualquerVersaoPublicada(): void
    {
        $changelog = $this->changelog();
        $posNaoLancado = strpos($changelog, '## [Não lançado]');
        self::assertNotFalse($posNaoLancado);
        if (preg_match('/^## \[\d+\.\d+\.\d+[^\]]*\] - /m', $changelog, $m, PREG_OFFSET_CAPTURE) === 1) {
            self::assertLessThan($m[0][1], $posNaoLancado);
        }
    }
}
