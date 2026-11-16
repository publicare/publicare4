<?php

declare(strict_types=1);

namespace Pbl\Testes\Banco;

use Doctrine\DBAL\Connection;
use Pbl\Banco\CarregadorDeConfigBanco;
use Pbl\Banco\Conexao;
use Pbl\Banco\ConfigBanco;
use Pbl\Banco\Dialeto\Dialeto;
use Pbl\Banco\Dialeto\Dialetos;
use Pbl\Banco\ErroDeConexao;
use Pbl\Caminhos;
use Pbl\Testes\Ajuda;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Contrato do dialeto (ADR 0028 §9): o mesmo teste roda em SQLite, MySQL e PostgreSQL para o comportamento não divergir.
 * O banco vem de `PBL_BANCO_*` (como na aplicação); sem variáveis, é um SQLite temporário, então roda em qualquer máquina.
 * No CI: `PBL_BANCO_DRIVER=mysql|pgsql PBL_BANCO_HOST=… vendor/bin/phpunit --group contrato-dialeto`.
 */
#[Group('contrato-dialeto')]
final class ContratoDeDialetoTest extends TestCase
{
    private string $pasta;
    private ConfigBanco $config;
    private Dialeto $dialeto;
    private string $tabela;
    /** @var list<Connection> */
    private array $abertas = [];

    protected function setUp(): void
    {
        $this->pasta = Ajuda::pastaTemporaria();
        $caminhos = new Caminhos($this->pasta);
        $caminhos->garantirDados();
        $this->config = (new CarregadorDeConfigBanco($caminhos))->carregar();
        $extensao = 'pdo_' . $this->config->driver;
        if (!extension_loaded($extensao)) {
            self::markTestSkipped("A extensão {$extensao} não está carregada.");
        }
        $this->dialeto = Dialetos::porNome($this->config->driver);
        $this->tabela = 'pbl_contrato_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (isset($this->dialeto) && $this->abertas !== []) {
            try {
                $this->abertas[0]->executeStatement('DROP TABLE IF EXISTS ' . $this->tabela);
            } catch (\Throwable) {
            }
        }
        foreach ($this->abertas as $c) {
            $c->close();
        }
        Ajuda::apagar($this->pasta);
    }

    private function conectar(): Connection
    {
        return $this->abertas[] = Conexao::abrir($this->config);
    }

    private function criarTabela(Connection $c): void
    {
        $opcoes = $this->dialeto->nome() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $c->executeStatement("CREATE TABLE {$this->tabela} (id INTEGER NOT NULL PRIMARY KEY, v VARCHAR(50) NOT NULL)" . $opcoes);
    }

    public function testConectaComAConfiguracao(): void
    {
        self::assertSame(1, (int) $this->conectar()->fetchOne('SELECT 1'));
    }

    public function testCriaLeEApagaTabela(): void
    {
        $c = $this->conectar();
        $this->criarTabela($c);
        $c->insert($this->tabela, ['id' => 1, 'v' => 'ação ✓']);

        self::assertSame('ação ✓', $c->fetchOne("SELECT v FROM {$this->tabela} WHERE id = ?", [1]));

        $c->executeStatement('DROP TABLE ' . $this->tabela);
        $this->expectException(\Throwable::class);
        $c->fetchOne('SELECT COUNT(*) FROM ' . $this->tabela);
    }

    public function testTransacaoComCommitERollback(): void
    {
        $c = $this->conectar();
        $this->criarTabela($c);

        $c->transactional(fn () => $c->insert($this->tabela, ['id' => 1, 'v' => 'a']));
        try {
            $c->transactional(function () use ($c): void {
                $c->insert($this->tabela, ['id' => 2, 'v' => 'b']);
                throw new \LogicException('desfaz');
            });
        } catch (\LogicException) {
        }

        self::assertSame([1], array_map('intval', $c->fetchFirstColumn("SELECT id FROM {$this->tabela} ORDER BY id")));
    }

    public function testDdlTransacionalConfereComODeclarado(): void
    {
        $c = $this->conectar();
        $c->beginTransaction();
        $this->criarTabela($c);
        try {
            $c->rollBack();
        } catch (\Throwable) {
            // MySQL já comitou o DDL: não há transação a desfazer.
        }

        $existe = $c->createSchemaManager()->tablesExist([$this->tabela]);

        self::assertSame(!$this->dialeto->ddlTransacional(), $existe);
    }

    public function testErroDeTabelaInexistenteTraduzido(): void
    {
        $c = $this->conectar();
        try {
            $c->fetchOne('SELECT 1 FROM tabela_que_nao_existe_xyz');
            self::fail('deveria falhar');
        } catch (\Throwable $e) {
            self::assertStringContainsString('inexistente', $this->dialeto->traduzirErro($e));
            self::assertFalse($this->dialeto->ehDeadlock($e));
        }
    }

    public function testSenhaErradaGeraErroTraduzidoSemVazarASenha(): void
    {
        if ($this->config->ehSqlite()) {
            self::markTestSkipped('SQLite não tem senha.');
        }
        $errada = new ConfigBanco(
            $this->config->driver, $this->config->nome, $this->config->host, $this->config->porta,
            $this->config->usuario, 'senha-errada-' . bin2hex(random_bytes(4)), $this->config->schema, $this->config->tls,
        );
        try {
            Conexao::abrir($errada);
            self::fail('deveria recusar');
        } catch (ErroDeConexao $e) {
            self::assertStringContainsString('senha recusados', $e->getMessage());
            self::assertStringNotContainsString($errada->segredo(), $e->getMessage() . $e->getTraceAsString());
        }
    }

    public function testTravaExclusivaEntreSessoes(): void
    {
        $a = $this->conectar();
        $b = $this->conectar();
        $nome = 'pbl_contrato_' . bin2hex(random_bytes(4));
        // Dois objetos de dialeto, como dois processos: a trava vale pela sessão/arquivo, não pela instância.
        $outro = Dialetos::porNome($this->config->driver);

        self::assertTrue($this->dialeto->obterTrava($a, $nome, 0));
        self::assertFalse($outro->obterTrava($b, $nome, 0), 'a segunda sessão não obtém enquanto a primeira segura');

        $this->dialeto->soltarTrava($a, $nome);
        self::assertTrue($outro->obterTrava($b, $nome, 1), 'solta, outra sessão obtém');
        $outro->soltarTrava($b, $nome);
    }
}
