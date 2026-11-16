<?php

declare(strict_types=1);

namespace Pbl\Testes\Banco;

use Pbl\Arquivos\GravacaoAtomica;
use Pbl\Banco\CarregadorDeConfigBanco;
use Pbl\Banco\ConfigBanco;
use Pbl\Banco\ConfigDeBancoInvalida;
use Pbl\Banco\Conexao;
use Pbl\Banco\GravadorDeConfigBanco;
use Pbl\Caminhos;
use Pbl\Testes\Ajuda;
use PHPUnit\Framework\TestCase;

final class ConfigDeBancoArquivoTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::pastaTemporaria();
        $this->caminhos = new Caminhos($this->raiz);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    /** @param array<string, string> $env */
    private function carregar(array $env = []): ConfigBanco
    {
        return (new CarregadorDeConfigBanco($this->caminhos, $env))->carregar();
    }

    private function escreverJson(string $conteudo, int $modo = 0600): void
    {
        @mkdir($this->caminhos->config(), 0750, true);
        file_put_contents($this->caminhos->config() . '/banco.json', $conteudo);
        chmod($this->caminhos->config() . '/banco.json', $modo);
    }

    public function testSemArquivoNemVariaveisUsaSqliteDeSempre(): void
    {
        $c = $this->carregar();

        self::assertSame('sqlite', $c->driver);
        self::assertSame($this->caminhos->banco(), $c->nome);
    }

    public function testLeOArquivo(): void
    {
        $this->escreverJson('{"driver":"mysql","host":"db","porta":3307,"nome":"pbl","usuario":"op","senha":"x","tls":"exigir","modo_simples":true}');

        $c = $this->carregar();

        self::assertSame(['mysql', 'db', 3307, 'pbl', 'op', 'exigir', true], [$c->driver, $c->host, $c->porta, $c->nome, $c->usuario, $c->tls, $c->modoSimples]);
        self::assertSame('x', $c->segredo());
    }

    public function testVariaveisVencemOArquivo(): void
    {
        $this->escreverJson('{"driver":"mysql","host":"db","nome":"pbl","usuario":"op","senha":"arq"}');

        $c = $this->carregar(['PBL_BANCO_HOST' => 'outro', 'PBL_BANCO_SENHA' => 'env', 'PBL_BANCO_PORTA' => '3310', 'PBL_BANCO_MODO_SIMPLES' => 'sim', 'PBL_BANCO_TLS' => 'verificar']);

        self::assertSame(['outro', 3310, 'env', true, 'verificar', 'pbl'], [$c->host, $c->porta, $c->segredo(), $c->modoSimples, $c->tls, $c->nome]);
    }

    public function testSoVariaveisSemArquivo(): void
    {
        $c = $this->carregar(['PBL_BANCO_DRIVER' => 'pgsql', 'PBL_BANCO_HOST' => 'h', 'PBL_BANCO_NOME' => 'n', 'PBL_BANCO_USUARIO' => 'u', 'PBL_BANCO_SCHEMA' => 'pbl']);

        self::assertSame(['pgsql', 5432, 'pbl'], [$c->driver, $c->porta, $c->schema]);
    }

    public function testPblBancoArquivoApontaParaOBancoJson(): void
    {
        $fora = $this->raiz . '/fora/meu.json';
        mkdir(dirname($fora));
        file_put_contents($fora, '{"driver":"pgsql","host":"h","nome":"n","usuario":"u"}');
        chmod($fora, 0600);

        self::assertSame('pgsql', $this->carregar(['PBL_BANCO_ARQUIVO' => $fora])->driver);
        $this->expectException(ConfigDeBancoInvalida::class);
        $this->carregar(['PBL_BANCO_ARQUIVO' => 'relativo.json']);
    }

    public function testArquivoIndicadoQueNaoExisteEErro(): void
    {
        $this->expectException(ConfigDeBancoInvalida::class);
        $this->carregar(['PBL_BANCO_ARQUIVO' => $this->raiz . '/nao-existe.json']);
    }

    public function testJsonInvalidoNaoCitaOConteudo(): void
    {
        $this->escreverJson('{"senha":"SEGREDO-NO-ARQUIVO", quebrado');

        try {
            $this->carregar();
            self::fail();
        } catch (ConfigDeBancoInvalida $e) {
            self::assertStringContainsString('banco.json', $e->getMessage());
            self::assertStringNotContainsString('SEGREDO-NO-ARQUIVO', $e->getMessage());
        }
    }

    public function testChaveDesconhecidaEUsuarioDeInstalacaoSaoRejeitados(): void
    {
        foreach (['usuario_ddl', 'instalacao', 'senha_ddl', 'qualquer'] as $chave) {
            $this->escreverJson('{"driver":"sqlite","' . $chave . '":"x"}');
            try {
                $this->carregar();
                self::fail($chave);
            } catch (ConfigDeBancoInvalida $e) {
                self::assertStringContainsString('campo desconhecido', $e->getMessage());
            }
        }
    }

    public function testTiposErradosENaoVazaSenha(): void
    {
        $this->escreverJson('{"driver":"mysql","host":"h","nome":"n","usuario":"u","senha":"SEGREDO-123","porta":"abc"}');
        try {
            $this->carregar();
            self::fail();
        } catch (ConfigDeBancoInvalida $e) {
            self::assertStringContainsString('porta', $e->getMessage());
            self::assertStringNotContainsString('SEGREDO-123', $e->getMessage());
        }
        $this->expectException(ConfigDeBancoInvalida::class);
        $this->carregar(['PBL_BANCO_MODO_SIMPLES' => 'talvez']);
    }

    public function testPermissaoFrouxaAvisaMasLe(): void
    {
        $this->escreverJson('{"driver":"sqlite"}', 0644);
        $carregador = new CarregadorDeConfigBanco($this->caminhos, []);

        self::assertSame('sqlite', $carregador->carregar()->driver);
        self::assertCount(1, $carregador->avisos());
        self::assertStringContainsString('0600', $carregador->avisos()[0]);
    }

    public function testGravaAtomicamenteComModo0600EReleEquivalente(): void
    {
        $antes = umask(0022);
        try {
            $c = new ConfigBanco('pgsql', 'pbl', 'h', 5433, 'op', 'SEGREDO-123', 'pbl', 'verificar', true);

            GravadorDeConfigBanco::gravar($this->caminhos, $c);

            $arquivo = $this->caminhos->config() . '/banco.json';
            self::assertSame(0600, fileperms($arquivo) & 0777);
            self::assertSame(['banco.json'], array_values(array_diff(scandir($this->caminhos->config()) ?: [], ['.', '..'])));
            self::assertSame(0022, umask());
            $lido = $this->carregar();
            self::assertSame($c->paraArray(), $lido->paraArray());
            self::assertSame('SEGREDO-123', $lido->segredo());
            self::assertStringNotContainsString('ddl', (string) file_get_contents($arquivo));
        } finally {
            umask($antes);
        }
    }

    public function testGravarSqliteNaoGuardaCredenciais(): void
    {
        GravadorDeConfigBanco::gravar($this->caminhos, ConfigBanco::sqlite($this->caminhos->banco()));

        self::assertSame(['driver' => 'sqlite'], json_decode((string) file_get_contents($this->caminhos->config() . '/banco.json'), true));
    }

    public function testGravacaoAtomicaCriaPastaESubstitui(): void
    {
        $arquivo = $this->raiz . '/a/b/x.txt';
        GravacaoAtomica::gravar($arquivo, "um\n");
        GravacaoAtomica::gravar($arquivo, "dois\n");

        self::assertSame("dois\n", file_get_contents($arquivo));
        self::assertSame(0600, fileperms($arquivo) & 0777);
        self::assertSame(['x.txt'], array_values(array_diff(scandir(dirname($arquivo)) ?: [], ['.', '..'])));
    }

    public function testConexaoAbrirComSqlite(): void
    {
        $c = Conexao::abrir(ConfigBanco::sqlite(':memory:'));

        self::assertSame(1, (int) $c->fetchOne('SELECT 1'));
    }

    public function testConexaoComBancoRemotoInalcancavelTraduzSemVazarSenha(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            self::markTestSkipped('pdo_mysql ausente');
        }
        $c = new ConfigBanco('mysql', 'pbl', '127.0.0.1', 1, 'op', 'SEGREDO-123');
        try {
            Conexao::abrir($c);
            self::fail();
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString('SEGREDO-123', $e->getMessage() . $e->getTraceAsString());
            self::assertNull($e->getPrevious());
        }
    }
}
