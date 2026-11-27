<?php

declare(strict_types=1);

namespace Pbl\Testes\Banco;

use Doctrine\DBAL\Connection;
use Pbl\Banco\CarregadorDeConfigBanco;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use Pbl\Caminhos;
use Pbl\Testes\Ajuda;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * As migrações rodam em SQLite, MySQL, MariaDB e PostgreSQL (ADR 0028 §2 e §3). O banco vem de `PBL_BANCO_*`, como no teste de contrato;
 * sem variáveis é um SQLite temporário. Num servidor, o teste apaga as tabelas do banco configurado antes de começar: use um banco só de teste.
 */
#[Group('contrato-dialeto')]
final class MigracoesPortaveisTest extends TestCase
{
    private string $pasta;
    private Connection $conexao;

    protected function setUp(): void
    {
        $this->pasta = Ajuda::pastaTemporaria();
        $caminhos = new Caminhos($this->pasta);
        $caminhos->garantirDados();
        $config = (new CarregadorDeConfigBanco($caminhos))->carregar();
        if (!extension_loaded('pdo_' . $config->driver)) {
            self::markTestSkipped("A extensão pdo_{$config->driver} não está carregada.");
        }
        $this->conexao = Conexao::abrir($config);
        $this->apagarTudo();
    }

    protected function tearDown(): void
    {
        if (isset($this->conexao)) {
            $this->apagarTudo();
            $this->conexao->close();
        }
        Ajuda::apagar($this->pasta);
    }

    private function apagarTudo(): void
    {
        $nome = $this->conexao->getDatabasePlatform()::class;
        $mysql = str_contains($nome, 'MySQL') || str_contains($nome, 'MariaDB');
        if ($mysql) {
            $this->conexao->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        }
        foreach ($this->conexao->createSchemaManager()->listTableNames() as $tabela) {
            $this->conexao->executeStatement('DROP TABLE ' . $this->conexao->quoteSingleIdentifier($tabela) . (str_contains($nome, 'PostgreSQL') ? ' CASCADE' : ''));
        }
        if ($mysql) {
            $this->conexao->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    public function testAplicaTodasAsMigracoesEReaplicarNaoFazNada(): void
    {
        $migrador = new Migrador($this->conexao);
        $aplicadas = $migrador->migrar();
        self::assertNotSame([], $aplicadas, 'o banco vazio recebe as migrações');
        self::assertSame(max([0, ...$aplicadas]), $migrador->versaoAtual());
        self::assertSame([], $migrador->migrar(), 'segunda chamada: nada pendente');
        foreach (['objeto', 'classe', 'usuario', 'sessao', 'dominio', 'arquivo', 'tag'] as $tabela) {
            self::assertTrue($this->conexao->createSchemaManager()->tablesExist([$tabela]), "tabela {$tabela}");
        }
    }

    public function testChavesDeTextoDistinguemMaiusculasEAcentosEmTodosOsBancos(): void
    {
        (new Migrador($this->conexao))->migrar();
        // `tag` tem chave única (site_id, slug): num banco com colação que ignora maiúsculas e acentos, o segundo insert falharia.
        foreach (['Abc', 'abc', 'ábc'] as $slug) {
            $this->conexao->insert('tag', ['site_id' => 1, 'nome' => $slug, 'slug' => $slug, 'criado_em' => '2026-01-01T00:00:00+00:00']);
        }
        self::assertSame(3, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM tag'));
        self::assertSame(1, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM tag WHERE slug = ?', ['abc']), 'comparação binária: só o "abc" exato');
        $nome = $this->conexao->getDatabasePlatform()::class;
        if (str_contains($nome, 'MySQL') || str_contains($nome, 'MariaDB')) {
            $outras = $this->conexao->fetchFirstColumn(
                'SELECT DISTINCT collation_name FROM information_schema.columns WHERE table_schema = DATABASE() AND collation_name IS NOT NULL AND collation_name <> ?',
                ['utf8mb4_bin'],
            );
            self::assertSame([], $outras, 'toda coluna de texto em utf8mb4_bin, mesmo com o banco no padrão do servidor');
        }
    }

    public function testDuasMigracoesAoMesmoTempoSaoBarradas(): void
    {
        $nome = $this->conexao->getDatabasePlatform()::class;
        if (str_contains($nome, 'SQLite')) {
            self::markTestSkipped('O SQLite não usa a trava de migração.');
        }
        $outra = Conexao::abrir((new CarregadorDeConfigBanco(new Caminhos($this->pasta)))->carregar());
        try {
            $dialeto = \Pbl\Banco\Dialeto\Dialetos::paraConexao($this->conexao);
            self::assertTrue($dialeto->obterTrava($outra, 'pbl_migracao', 0));
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Outra migração está em andamento');
            (new Migrador($this->conexao, null, new class($dialeto) implements \Pbl\Banco\Dialeto\Dialeto {
                public function __construct(private readonly \Pbl\Banco\Dialeto\Dialeto $d)
                {
                }

                public function nome(): string { return $this->d->nome(); }
                public function opcoesDeConexao(\Pbl\Banco\ConfigBanco $c): array { return $this->d->opcoesDeConexao($c); }
                public function aposConectar(Connection $c, \Pbl\Banco\ConfigBanco $cfg): void { $this->d->aposConectar($c, $cfg); }
                public function opcoesDeTabela(): array { return $this->d->opcoesDeTabela(); }
                public function ddlTransacional(): bool { return $this->d->ddlTransacional(); }
                public function limiteDeIdentificador(): int { return $this->d->limiteDeIdentificador(); }
                public function obterTrava(Connection $c, string $nome, int $esperaSeg = 0): bool { return $this->d->obterTrava($c, $nome, 0); }
                public function soltarTrava(Connection $c, string $nome): void { $this->d->soltarTrava($c, $nome); }
                public function ehDeadlock(\Throwable $e): bool { return $this->d->ehDeadlock($e); }
                public function traduzirErro(\Throwable $e): string { return $this->d->traduzirErro($e); }
            }))->migrar();
        } finally {
            $outra->close();
        }
    }
}
