<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Atualizacao\Assinatura;
use PHPUnit\Framework\TestCase;

/**
 * `bin/assinar-release.sh` com um `gh` falso no PATH (grava as chamadas) e um `pbl` falso que usa os comandos reais com chaves
 * descartáveis injetadas. Nenhuma rede, nenhuma chave real.
 */
final class AssinarReleaseScriptTest extends TestCase
{
    private string $pasta;
    /** @var array{publica: string, privada: string} */
    private array $par;

    protected function setUp(): void
    {
        $this->pasta = Ajuda::pastaTemporaria();
        $this->par = Assinatura::gerarPar();
        mkdir($this->pasta . '/rascunho');
        mkdir($this->pasta . '/bin');
        $zip = 'conteudo do pacote';
        file_put_contents($this->pasta . '/rascunho/pbl4-0.1.0.zip', $zip);
        file_put_contents($this->pasta . '/rascunho/latest.json', json_encode([
            'formato' => 1, 'canal' => 'estavel', 'versao' => '0.1.0',
            'pacote' => ['url' => 'pbl4-0.1.0.zip', 'tamanho' => strlen($zip), 'sha256' => hash('sha256', $zip)],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->pasta . '/rascunho/draft', 'true');
        file_put_contents($this->pasta . '/chave.privada', $this->par['privada'] . "\n");
        chmod($this->pasta . '/chave.privada', 0600);

        file_put_contents($this->pasta . '/bin/gh', <<<'SH'
#!/usr/bin/env bash
echo "gh $*" >> "$GH_LOG"
case "$1 $2" in
  "release view") if [[ "$*" == *isDraft* ]]; then cat "$GH_DIR/draft"; else echo false; fi ;;
  "release download") cp "$GH_DIR"/latest.json "$GH_DIR"/pbl4-*.zip "${@: -1}/" ;;
  "release upload") for f in "${@:4}"; do if [ -f "$f" ]; then cp "$f" "$GH_DIR/subido-$(basename "$f")"; fi; done ;;
esac
SH);
        chmod($this->pasta . '/bin/gh', 0755);
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        file_put_contents($this->pasta . '/bin/pbl-falso', <<<PHP
#!/usr/bin/env php
<?php
require '{$autoload}';
\$app = new Symfony\Component\Console\Application();
\$app->add(new Pbl\Console\AssinarComando());
\$app->add(new Pbl\Console\VerificarAssinaturaComando([getenv('PUB_FALSA')], [], [], []));
\$app->run();
PHP);
        chmod($this->pasta . '/bin/pbl-falso', 0755);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->pasta);
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function rodar(string $publicaConfiavel, string ...$args): array
    {
        $p = proc_open(
            array_merge([__DIR__ . '/../bin/assinar-release.sh'], array_values($args)),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'PATH' => $this->pasta . '/bin:' . getenv('PATH'), 'GH_DIR' => $this->pasta . '/rascunho', 'GH_LOG' => $this->pasta . '/gh.log',
                'PBL_BIN' => $this->pasta . '/bin/pbl-falso', 'PUB_FALSA' => $publicaConfiavel, 'HOME' => $this->pasta,
            ],
        );
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($p), $out, $err];
    }

    private function chamadas(): string
    {
        return is_file($this->pasta . '/gh.log') ? (string) file_get_contents($this->pasta . '/gh.log') : '';
    }

    public function testAssinaVerificaSobeOManifestoEPublicaPorUltimoSemMostrarAChave(): void
    {
        [$codigo, $out, $err] = $this->rodar($this->par['publica'], 'v0.1.0', '--chave-privada=' . $this->pasta . '/chave.privada', '--sim');

        self::assertSame(0, $codigo, $err . $out);
        $log = $this->chamadas();
        self::assertLessThan(strpos($log, 'release edit'), strpos($log, 'release upload'), 'sobe antes de publicar');
        self::assertStringContainsString('release edit v0.1.0 --draft=false --latest', $log);
        self::assertFileExists($this->pasta . '/rascunho/subido-latest.json');
        self::assertFileExists($this->pasta . '/rascunho/subido-latest.json.sig');
        self::assertTrue(Assinatura::verificar(
            (string) file_get_contents($this->pasta . '/rascunho/subido-latest.json'),
            (string) file_get_contents($this->pasta . '/rascunho/subido-latest.json.sig'),
            [$this->par['publica']],
        ));
        self::assertStringNotContainsString($this->par['privada'], $out . $err . $log);
    }

    public function testChaveForaDasEmbutidasNaoSobeNemPublicaNada(): void
    {
        [$codigo, , $err] = $this->rodar(Assinatura::gerarPar()['publica'], 'v0.1.0', '--chave-privada=' . $this->pasta . '/chave.privada', '--sim');

        self::assertSame(1, $codigo);
        self::assertStringContainsString('nada foi publicado', $err);
        self::assertStringNotContainsString('release upload', $this->chamadas());
        self::assertStringNotContainsString('release edit', $this->chamadas());
    }

    public function testRecusaReleaseJaPublicadaPacoteAdulteradoETagInvalida(): void
    {
        $args = ['--chave-privada=' . $this->pasta . '/chave.privada', '--sim'];

        file_put_contents($this->pasta . '/rascunho/draft', 'false');
        [$codigo, , $err] = $this->rodar($this->par['publica'], 'v0.1.0', ...$args);
        self::assertSame(1, $codigo);
        self::assertStringContainsString('não é um rascunho', $err);

        file_put_contents($this->pasta . '/rascunho/draft', 'true');
        file_put_contents($this->pasta . '/rascunho/pbl4-0.1.0.zip', 'trocado');
        [$codigo, , $err] = $this->rodar($this->par['publica'], 'v0.1.0', ...$args);
        self::assertSame(1, $codigo);
        self::assertStringContainsString('não confere com o manifesto', $err);

        [$codigo] = $this->rodar($this->par['publica'], 'v0.1.0; rm', ...$args);
        self::assertSame(1, $codigo);
        [$codigo] = $this->rodar($this->par['publica'], 'v0.2.0', ...$args);
        self::assertSame(1, $codigo, 'manifesto de outra versão');
        self::assertStringNotContainsString('release upload', $this->chamadas());
    }

    public function testExigeArquivoDeChaveExistente(): void
    {
        [$codigo, , $err] = $this->rodar($this->par['publica'], 'v0.1.0', '--chave-privada=' . $this->pasta . '/nao-existe', '--sim');
        self::assertSame(1, $codigo);
        self::assertStringContainsString('não existe', $err);
        [$codigo] = $this->rodar($this->par['publica'], 'v0.1.0', '--sim');
        self::assertSame(1, $codigo, 'sem a opção da chave');
        self::assertSame('', $this->chamadas());
    }
}
