<?php

declare(strict_types=1);

namespace Pbl\Banco\Copia;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Column;

/**
 * Copia o conteúdo de um banco do PBL4 para outro, em qualquer direção entre SQLite, MySQL, MariaDB e PostgreSQL (ADR 0028, g).
 *
 * O destino já tem de estar migrado para a MESMA versão do esquema e sem conteúdo (só o que a migração semeia): quem cria as tabelas é o `Migrador`, não este copiador.
 * Os ids são preservados (as tabelas são ligadas por eles) e as sequências do destino são reposicionadas no fim. Cada tabela
 * entra numa transação própria; a conferência final compara contagem e impressão digital de cada tabela nos dois lados.
 */
final class CopiadorDeTabelas
{
    /** Tabelas que não são conteúdo: o destino já tem a sua própria (a versão é conferida, não copiada). */
    private const IGNORADAS = ['schema_versao'];

    public function __construct(private readonly Connection $origem, private readonly Connection $destino, private readonly int $lote = 400)
    {
    }

    /** @return list<string> */
    public static function tabelas(Connection $c): array
    {
        $nomes = array_values(array_filter($c->createSchemaManager()->listTableNames(), static fn (string $t): bool => !in_array($t, self::IGNORADAS, true)));
        sort($nomes);

        return $nomes;
    }

    /**
     * @param (callable(string, int): void)|null $progresso chamado com a tabela e o total de linhas já copiadas dela
     *
     * @return array<string, int> tabela => linhas copiadas
     */
    public function copiar(?callable $progresso = null): array
    {
        $this->conferirPartida();
        $copiadas = [];
        foreach (self::tabelas($this->origem) as $tabela) {
            $copiadas[$tabela] = $this->copiarTabela($tabela, $progresso);
        }
        $this->reposicionarSequencias();

        return $copiadas;
    }

    /**
     * Compara tabela a tabela (contagem e impressão digital). Lista o que diverge; vazio = idêntico.
     *
     * @return list<string>
     */
    public function divergencias(): array
    {
        $erros = [];
        $tabelas = self::tabelas($this->origem);
        if ($tabelas !== self::tabelas($this->destino)) {
            $erros[] = 'As tabelas dos dois bancos não são as mesmas.';
        }
        foreach ($tabelas as $tabela) {
            [$na, $ha] = $this->impressao($this->origem, $tabela);
            [$nb, $hb] = $this->impressao($this->destino, $tabela);
            if ($na !== $nb) {
                $erros[] = "{$tabela}: {$na} linhas na origem e {$nb} no destino.";
            } elseif ($ha !== $hb) {
                $erros[] = "{$tabela}: o conteúdo é diferente.";
            }
        }

        return $erros;
    }

    /**
     * Contagem e impressão digital do conteúdo. A soma dos hashes de cada linha não depende da ordem, porque cada banco ordena
     * texto de um jeito (a colação do PostgreSQL não é a binária do SQLite).
     *
     * @return array{0: int, 1: string}
     */
    public function impressao(Connection $c, string $tabela): array
    {
        $soma = [0, 0, 0];
        $n = 0;
        foreach ($this->linhas($c, $tabela) as $linha) {
            ++$n;
            $h = hash('sha256', json_encode(array_map(self::normalizar(...), $linha), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            for ($i = 0; $i < 3; ++$i) {
                $soma[$i] += (int) hexdec(substr($h, $i * 8, 8));
            }
        }

        return [$n, implode('-', $soma)];
    }

    private static function normalizar(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_resource($v)) {
            return (string) stream_get_contents($v);
        }

        return (string) $v;
    }

    private function conferirPartida(): void
    {
        $a = $this->origem->fetchOne('SELECT MAX(versao) FROM schema_versao');
        $b = $this->destino->fetchOne('SELECT MAX(versao) FROM schema_versao');
        if ($a === false || $a === null || (int) $a !== (int) $b) {
            throw new \RuntimeException('Os dois bancos precisam estar na mesma versão do esquema (origem ' . var_export($a, true) . ', destino ' . var_export($b, true) . '). Rode as migrações no destino antes.');
        }
        $tabelasOrigem = self::tabelas($this->origem);
        if ($tabelasOrigem !== self::tabelas($this->destino)) {
            throw new \RuntimeException('As tabelas do destino não são as da origem.');
        }
        // As migrações já semeiam tipos fixos e configuração, então "vazio" quer dizer sem conteúdo de verdade.
        foreach (['objeto', 'usuario', 'site'] as $tabela) {
            if ((int) $this->destino->fetchOne('SELECT COUNT(*) FROM ' . $this->destino->quoteSingleIdentifier($tabela)) > 0) {
                throw new \RuntimeException("O destino já tem conteúdo (a tabela {$tabela} tem linhas). Nada foi copiado.");
            }
        }
    }

    /** @param (callable(string, int): void)|null $progresso */
    private function copiarTabela(string $tabela, ?callable $progresso): int
    {
        $colunas = $this->colunasDoDestino($tabela);
        $total = 0;
        $this->destino->transactional(function () use ($tabela, $colunas, &$total, $progresso): void {
            // Troca o que a migração semeou pelo conteúdo da origem.
            $this->destino->executeStatement('DELETE FROM ' . $this->destino->quoteSingleIdentifier($tabela));
            $lote = [];
            foreach ($this->linhas($this->origem, $tabela) as $linha) {
                $lote[] = $this->paraDestino($linha, $colunas);
                if (count($lote) >= $this->lote) {
                    $total += $this->gravarLote($tabela, $colunas, $lote);
                    $lote = [];
                    $progresso === null || $progresso($tabela, $total);
                }
            }
            if ($lote !== []) {
                $total += $this->gravarLote($tabela, $colunas, $lote);
                $progresso === null || $progresso($tabela, $total);
            }
        });

        return $total;
    }

    /**
     * @param array<string, Column> $colunas
     * @param non-empty-list<array<string, mixed>> $lote
     */
    private function gravarLote(string $tabela, array $colunas, array $lote): int
    {
        $nomes = array_keys($colunas);
        $lista = implode(', ', array_map(fn (string $n): string => $this->destino->quoteSingleIdentifier($n), $nomes));
        $linhaSql = '(' . implode(', ', array_fill(0, count($nomes), '?')) . ')';
        $sql = 'INSERT INTO ' . $this->destino->quoteSingleIdentifier($tabela) . " ({$lista}) VALUES " . implode(', ', array_fill(0, count($lote), $linhaSql));
        $params = [];
        $tipos = [];
        foreach ($lote as $linha) {
            foreach ($nomes as $n) {
                $params[] = $linha[$n] ?? null;
                $tipos[] = $colunas[$n]->getType();
            }
        }
        $this->destino->executeStatement($sql, $params, $tipos);

        return count($lote);
    }

    /**
     * @param array<string, mixed> $linha
     * @param array<string, Column> $colunas
     *
     * @return array<string, mixed>
     */
    private function paraDestino(array $linha, array $colunas): array
    {
        foreach ($linha as $nome => $valor) {
            if (!isset($colunas[$nome])) {
                throw new \RuntimeException("A coluna {$nome} existe na origem e não no destino.");
            }
            if (is_resource($valor)) {
                $linha[$nome] = stream_get_contents($valor);
            } elseif ($valor !== null && $colunas[$nome]->getType()::class === \Doctrine\DBAL\Types\BooleanType::class) {
                $linha[$nome] = (bool) $valor;
            }
        }

        return $linha;
    }

    /** @return array<string, Column> */
    private function colunasDoDestino(string $tabela): array
    {
        $saida = [];
        foreach ($this->destino->createSchemaManager()->introspectTable($tabela)->getColumns() as $c) {
            $saida[$c->getObjectName()->toString()] = $c;
        }

        return $saida;
    }

    /** @return \Generator<int, array<string, mixed>> as linhas em ordem estável (chave primária), sem carregar a tabela inteira */
    private function linhas(Connection $c, string $tabela): \Generator
    {
        $q = $c->quoteSingleIdentifier($tabela);
        $chave = $this->colunasDaChave($c, $tabela);
        $ordem = $chave === [] ? '1' : implode(', ', array_map(static fn (string $n): string => $c->quoteSingleIdentifier($n), $chave));
        $passo = 1000;
        for ($desvio = 0;; $desvio += $passo) {
            $pagina = $c->fetchAllAssociative("SELECT * FROM {$q} ORDER BY {$ordem} LIMIT {$passo} OFFSET {$desvio}");
            foreach ($pagina as $linha) {
                yield $linha;
            }
            if (count($pagina) < $passo) {
                return;
            }
        }
    }

    /** @return list<string> */
    private function colunasDaChave(Connection $c, string $tabela): array
    {
        $pk = $c->createSchemaManager()->introspectTable($tabela)->getPrimaryKeyConstraint();
        if ($pk === null) {
            return [];
        }

        return array_map(static fn ($n): string => $n->getIdentifier()->getValue(), $pk->getColumnNames());
    }

    /** Depois de gravar ids explícitos, a próxima inserção não pode reaproveitar um id já usado. Só o PostgreSQL precisa de ajuste. */
    private function reposicionarSequencias(): void
    {
        if (!($this->destino->getDatabasePlatform() instanceof PostgreSQLPlatform)) {
            return;
        }
        foreach (self::tabelas($this->destino) as $tabela) {
            foreach ($this->destino->createSchemaManager()->introspectTable($tabela)->getColumns() as $coluna) {
                if (!$coluna->getAutoincrement()) {
                    continue;
                }
                $t = $this->destino->quoteSingleIdentifier($tabela);
                $n = $coluna->getObjectName()->toString();
                $c = $this->destino->quoteSingleIdentifier($n);
                $this->destino->executeQuery(
                    "SELECT setval(pg_get_serial_sequence(?, ?), COALESCE((SELECT MAX({$c}) FROM {$t}), 1), (SELECT MAX({$c}) FROM {$t}) IS NOT NULL)",
                    [$t, $n],
                )->free();
            }
        }
    }
}
