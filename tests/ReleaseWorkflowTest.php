<?php

declare(strict_types=1);

namespace Pbl\Testes;

use PHPUnit\Framework\TestCase;

/**
 * O workflow de release (`.github/workflows/release.yml`) não roda aqui; este teste trava o que dá para provar offline:
 * a estrutura de segurança do YAML (leitura de texto: o repositório não traz um leitor de YAML) e o script de conferência.
 */
final class ReleaseWorkflowTest extends TestCase
{
    private const RAIZ = __DIR__ . '/..';

    /** @var list<string> */
    private array $limpar = [];

    protected function tearDown(): void
    {
        foreach ($this->limpar as $p) {
            Ajuda::apagar($p);
        }
    }

    private function yaml(): string
    {
        return (string) file_get_contents(self::RAIZ . '/.github/workflows/release.yml');
    }

    public function testGatilhosSaoTagVEManualESoOJobDoRascunhoEscreve(): void
    {
        $y = $this->yaml();

        self::assertMatchesRegularExpression("/push:\n    tags: \['v\*'\]/", $y);
        self::assertStringContainsString('workflow_dispatch:', $y);
        self::assertMatchesRegularExpression("/\npermissions:\n  contents: read\n/", $y);
        self::assertSame(1, substr_count($y, 'contents: write'), 'escrita só num job');
        $rascunho = substr($y, (int) strpos($y, "\n  rascunho:"));
        self::assertStringContainsString('contents: write', $rascunho);
        self::assertStringContainsString("if: github.event_name == 'push'", $rascunho, 'o ensaio manual não publica');
        self::assertStringContainsString('--draft', $rascunho);
    }

    public function testNaoHaSegredoDeAssinaturaEOsActionsEstaoFixados(): void
    {
        $y = $this->yaml();

        preg_match_all('/secrets\.([A-Za-z0-9_]+)/', $y, $m);
        self::assertSame(['GITHUB_TOKEN'], array_values(array_unique($m[1])));
        self::assertStringContainsString('--sem-assinar', $y);
        self::assertStringNotContainsString('--chave-privada', $y);
        preg_match_all('/uses:\s*(\S+)/', $y, $u);
        self::assertNotEmpty($u[1]);
        foreach ($u[1] as $uso) {
            self::assertMatchesRegularExpression('/@\S+$/', $uso, "action sem versão fixada: {$uso}");
        }
        self::assertStringContainsString('composer install --no-dev --optimize-autoloader', $y);
        self::assertStringContainsString('gd, zip', $y);
    }

    public function testATagSoEntraPorEnvOuWithNuncaDentroDoComando(): void
    {
        foreach (explode("\n", $this->yaml()) as $linha) {
            if (preg_match('/\$\{\{\s*(inputs\.|github\.(ref_name|ref)\b)/', $linha) === 1) {
                self::assertMatchesRegularExpression('/^\s*(ref|TAG|group):/', $linha, "interpolação fora de env/with: {$linha}");
            }
        }
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function conferir(string $tag, string $versao, string $changelog): array
    {
        $raiz = Ajuda::pastaTemporaria();
        $this->limpar[] = $raiz;
        mkdir($raiz . '/app/src', 0775, true);
        file_put_contents($raiz . '/app/src/Versao.php', "<?php\nfinal class Versao\n{\n    public const ATUAL = '{$versao}';\n}\n");
        file_put_contents($raiz . '/CHANGELOG.md', $changelog);
        $p = proc_open(
            [self::RAIZ . '/.github/scripts/conferir-release.sh', $tag, $raiz, $raiz . '/notas.md'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($p), $out, $err];
    }

    public function testConferenciaAceitaTagQueBateEEscolheCanalEMarcaSeguranca(): void
    {
        $log = "# Changelog\n\n## [Não lançado]\n\n## [1.2.0] - 2026-12-01\n\n### Segurança\n- Corrige X.\n\n### Alterado\n- Y.\n\n## [1.1.0] - 2026-11-01\n";
        [$codigo, $out] = $this->conferir('v1.2.0', '1.2.0', $log);
        self::assertSame(0, $codigo);
        self::assertSame("versao=1.2.0\ncanal=estavel\nseguranca=true\n", $out);

        [$codigo, $out] = $this->conferir('v1.2.0-rc.1', '1.2.0-rc.1', "## [1.2.0-rc.1] - 2026-12-01\n\n### Adicionado\n- Z.\n");
        self::assertSame(0, $codigo);
        self::assertSame("versao=1.2.0-rc.1\ncanal=beta\nseguranca=false\n", $out);
    }

    public function testConferenciaRecusaTagForaDoPadraoVersaoDiferenteESemChangelog(): void
    {
        $log = "## [1.2.0] - 2026-12-01\n- a\n";
        self::assertSame(1, $this->conferir('1.2.0', '1.2.0', $log)[0], 'sem o v');
        self::assertSame(1, $this->conferir('v1.2.0; rm -rf /', '1.2.0', $log)[0], 'tag com lixo');
        [$codigo, , $err] = $this->conferir('v1.2.0', '1.1.0', $log);
        self::assertSame(1, $codigo);
        self::assertStringContainsString('Versao.php', $err);
        [$codigo, , $err] = $this->conferir('v1.2.0', '1.2.0', "## [Não lançado]\n- a\n");
        self::assertSame(1, $codigo);
        self::assertStringContainsString('CHANGELOG', $err);
    }
}
