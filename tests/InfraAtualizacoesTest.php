<?php

declare(strict_types=1);

namespace Pbl\Testes;

use PHPUnit\Framework\TestCase;

/** O Worker não roda aqui (sem rede nem Cloudflare): este teste trava a estrutura que dá para provar lendo os arquivos. */
final class InfraAtualizacoesTest extends TestCase
{
    private function arquivo(string $nome): string
    {
        return (string) file_get_contents(__DIR__ . '/../infra/atualizacoes/' . $nome);
    }

    public function testWranglerNaoTemSegredoNemDominioInventado(): void
    {
        $w = $this->arquivo('wrangler.toml');

        self::assertDoesNotMatchRegularExpression('/token|secret|api_key|password/i', (string) preg_replace('/^\s*#.*$/m', '', $w));
        self::assertStringContainsString('GITHUB_REPO = "publicare/publicare4"', $w);
        self::assertDoesNotMatchRegularExpression('/^routes\s*=/m', $w, 'rota/DNS é do dono');
    }

    public function testWorkerMapeiaAsRotasSegueRedirecionamentoECacheiaOManifestoPoucoTempo(): void
    {
        $j = $this->arquivo('worker.js');

        foreach (['/latest.json', '/latest.json.sig', '/latest-beta.json', '/latest-beta.json.sig', '/pacotes/'] as $rota) {
            self::assertStringContainsString($rota, $j);
        }
        self::assertStringContainsString("redirect: 'follow'", $j);
        self::assertStringContainsString('CACHE_MANIFESTO_SEGUNDOS', $j);
        self::assertStringContainsString('Math.min(1800', $j, 'cache do manifesto limitado a minutos');
        self::assertStringContainsString('^pbl4-(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)\.zip$', $j);
        self::assertStringContainsString("request.method !== 'GET' && request.method !== 'HEAD'", $j);
        self::assertStringNotContainsString('request.headers', $j, 'não repassa cabeçalhos do cliente');
        self::assertStringContainsString('env.GITHUB_TOKEN', $j);
    }

    public function testReadmeDocumentaDeployManualSemFazerDeploy(): void
    {
        $r = $this->arquivo('README.md');

        self::assertStringContainsString('wrangler deploy', $r);
        self::assertStringContainsString('passo manual do dono', $r);
        self::assertStringContainsString('--url-base-pacote=/pacotes/', $r);
    }
}
