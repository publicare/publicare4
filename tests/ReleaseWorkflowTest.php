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

    /** Fatia o YAML por job (texto de `  nome:` até o próximo job de nível 2). */
    private function job(string $nome): string
    {
        $y = $this->yaml();
        $ini = strpos($y, "\n  {$nome}:\n");
        self::assertNotFalse($ini, "job {$nome} ausente");
        $resto = substr($y, $ini + 1);
        $fim = preg_match('/\n  [a-z]+:\n/', $resto, $m, PREG_OFFSET_CAPTURE, 3) === 1 ? $m[0][1] : strlen($resto);

        return substr($resto, 0, $fim);
    }

    public function testGatilhoEhSoTagVEOManualNaoAssinaNemPublica(): void
    {
        $y = $this->yaml();

        self::assertMatchesRegularExpression("/push:\n    tags: \['v\*'\]/", $y);
        self::assertStringNotContainsString('branches:', $y, 'nunca merge na main');
        self::assertStringContainsString('workflow_dispatch:', $y);
        self::assertMatchesRegularExpression("/\npermissions:\n  contents: read\n/", $y);
        self::assertStringContainsString("if: github.event_name == 'push' && startsWith(github.ref, 'refs/tags/v')", $this->job('assinar'));
        self::assertStringContainsString("github.event_name == 'push'", $this->job('publicar'));
    }

    public function testJobDeAssinaturaDeclaraOEnvironmentReleaseELeituraApenas(): void
    {
        $assinar = $this->job('assinar');

        self::assertStringContainsString("\n    environment: release\n", $assinar);
        self::assertStringContainsString("permissions:\n      contents: read", $assinar);
        self::assertStringNotContainsString('contents: write', $assinar);
        self::assertStringNotContainsString('gh release', $assinar);
        self::assertStringNotContainsString('GITHUB_TOKEN', $assinar);
        self::assertStringNotContainsString('environment:', $this->job('publicar'), 'o publicar não recebe o segredo');
    }

    public function testEscritaSoNoJobPublicar(): void
    {
        $y = $this->yaml();

        self::assertSame(1, substr_count($y, 'contents: write'));
        self::assertStringContainsString('contents: write', $this->job('publicar'));
        self::assertStringNotContainsString('contents: write', $this->job('empacotar'));
    }

    public function testOUnicoSegredoDeAssinaturaEPBL4ChaveUsoESoNoPassoQueGravaOArquivo(): void
    {
        $y = $this->yaml();

        preg_match_all('/secrets\.([A-Za-z0-9_]+)/', $y, $m);
        self::assertSame(['PBL4_CHAVE_USO', 'GITHUB_TOKEN'], array_values(array_unique($m[1])));
        self::assertSame(1, substr_count($y, 'secrets.PBL4_CHAVE_USO'));
        self::assertDoesNotMatchRegularExpression('/recupera/i', (string) preg_replace('/^\s*#.*$/m', '', $y), 'a chave de recuperação nunca entra no CI');
        self::assertStringNotContainsString('GITHUB_TOKEN', $this->job('empacotar') . $this->job('assinar'));

        // o segredo está no env: de UM passo (8 espaços de recuo + 10), não do job nem do workflow
        self::assertMatchesRegularExpression('/\n {8}env:\n {10}CHAVE_USO: \$\{\{ secrets\.PBL4_CHAVE_USO \}\}\n/', $y);
        $passo = substr($y, (int) strpos($y, '- name: Gravar a chave de uso'));
        $passo = substr($passo, 0, (int) strpos($passo, "\n      - name: Assinar"));
        self::assertStringContainsString('umask 077', $passo);
        self::assertStringContainsString("printf '%s\\n' \"\$CHAVE_USO\" > \"\$RUNNER_TEMP/uso.chave\"", $passo);
    }

    public function testASegredoNuncaVaiParaLogEnvOutputNemArtefato(): void
    {
        $assinar = $this->job('assinar');

        foreach (explode("\n", $assinar) as $linha) {
            if (preg_match('/\$CHAVE_USO|uso\.chave/', $linha) === 1) {
                self::assertDoesNotMatchRegularExpression('/\becho\b|\bcat\b|set -x|GITHUB_ENV|::add-mask::|--chave-privada=\$/', $linha . '', "linha suspeita: {$linha}");
            }
        }
        self::assertStringNotContainsString('set -x', $assinar);
        self::assertStringNotContainsString('GITHUB_ENV', $assinar);
        self::assertStringNotContainsString('echo "$CHAVE_USO"', $assinar);
        // o único passo com o arquivo de chave que escreve em GITHUB_OUTPUT só grava booleanos
        self::assertStringContainsString('echo "tem_chave=true" >> "$GITHUB_OUTPUT"', $assinar);
        // o artefato sobe a pasta `saida`, nunca RUNNER_TEMP inteiro (onde está a chave)
        preg_match_all('/path: (.+)/', $assinar, $p);
        foreach ($p[1] as $caminho) {
            self::assertMatchesRegularExpression('#/saida/?$#', trim($caminho));
        }
    }

    public function testChaveVaiParaArquivoUsadaPeloAssinarEApagadaSempre(): void
    {
        $assinar = $this->job('assinar');

        self::assertStringContainsString('php bin/pbl assinar "$RUNNER_TEMP/saida/$nome" --chave-privada="$RUNNER_TEMP/uso.chave"', $assinar);
        self::assertMatchesRegularExpression('/- name: Apagar a chave\n\s+if: always\(\)\n\s+run: rm -f "\$RUNNER_TEMP\/uso\.chave"/', $assinar);
        $ordem = array_map(static fn (string $t): int|false => strpos($assinar, $t), ['Gravar a chave', 'Assinar o manifesto', 'Conferir a assinatura', 'Apagar a chave']);
        $ordenada = $ordem;
        sort($ordenada);
        self::assertSame($ordenada, $ordem);
        self::assertNotContains(false, $ordem);
    }

    public function testConferenciaContraChavesUsoEAntesDeMarcarComoAssinadoEDePublicar(): void
    {
        $assinar = $this->job('assinar');
        $publicar = $this->job('publicar');

        self::assertStringContainsString('php bin/pbl verificar-assinatura "$RUNNER_TEMP/saida/$nome" --canal="$CANAL"', $assinar);
        self::assertLessThan((int) strpos($assinar, 'id: marcar'), (int) strpos($assinar, 'verificar-assinatura'));
        self::assertStringContainsString('needs: [empacotar, assinar]', $publicar);
        // falha em qualquer passo do assinar => resultado != success => publicar recusa e não cria release
        self::assertStringContainsString("if: needs.assinar.result != 'success'", $publicar);
        $recusa = (int) strpos($publicar, "needs.assinar.result != 'success'");
        self::assertLessThan((int) strpos($publicar, 'gh release create'), $recusa);
        // só publica (draft=false) com assinado == 'true'
        self::assertStringContainsString("if: needs.assinar.outputs.assinado == 'true'", $publicar);
        $publica = substr($publicar, (int) strpos($publicar, '- name: Publicar a release assinada'));
        $publica = substr($publica, 0, (int) strpos($publica, '- name: Criar só o rascunho'));
        self::assertStringContainsString('--draft=false', $publica);
        self::assertStringContainsString('latest*.json.sig', $publica);
        self::assertStringContainsString('--prerelease', $publica);
        self::assertStringNotContainsString('--draft=false', substr($publicar, (int) strpos($publicar, '- name: Criar só o rascunho')));
    }

    public function testEnsaioSemSegredoGeraSoRascunhoComAvisoNoResumo(): void
    {
        $assinar = $this->job('assinar');
        $rascunho = substr($this->job('publicar'), (int) strpos($this->job('publicar'), '- name: Criar só o rascunho'));

        self::assertStringContainsString('if: steps.chave.outputs.tem_chave == \'false\'', $assinar);
        self::assertStringContainsString('::warning::', $assinar);
        self::assertStringContainsString("if: needs.assinar.outputs.assinado != 'true'", $rascunho);
        self::assertStringContainsString('gh release create "$TAG" --draft --verify-tag', $rascunho);
        self::assertStringContainsString('GITHUB_STEP_SUMMARY', $rascunho);
        self::assertStringContainsString('PBL4_CHAVE_USO', $rascunho);
        self::assertStringContainsString('--prerelease', $rascunho);
    }

    public function testEmpacotarMantemSemAssinarEUrlBaseDoMesmoHost(): void
    {
        $e = $this->job('empacotar');

        self::assertStringContainsString('--sem-assinar', $e);
        self::assertStringContainsString('--url-base-pacote=/pacotes/', $e);
        self::assertStringNotContainsString('--chave-privada', $e);
        self::assertStringContainsString('composer install --no-dev --optimize-autoloader', $e);
        self::assertStringContainsString('gd, zip', $e);
    }

    public function testActionsFixadasPorVersao(): void
    {
        preg_match_all('/uses:\s*(\S+)/', $this->yaml(), $u);

        self::assertNotEmpty($u[1]);
        foreach ($u[1] as $uso) {
            self::assertMatchesRegularExpression('/@\S+$/', $uso, "action sem versão fixada: {$uso}");
        }
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
