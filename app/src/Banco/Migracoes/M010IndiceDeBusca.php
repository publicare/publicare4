<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Pbl\Banco\Migracao;
use Pbl\Busca\BuscaSql;
use Pbl\Busca\IndexadorDeObjeto;
use Pbl\Conteudo\ClasseRepositorio;

/**
 * Busca nível B (ADR 0010, fase 1c): tabela `objeto_termo` (os termos de cada objeto, com os pontos do melhor campo onde
 * aparecem) e indexação dos objetos que já existem. **Só adiciona uma tabela**: a versão anterior do código a ignora (ADR 0006).
 */
final class M010IndiceDeBusca implements Migracao
{
    public function versao(): int
    {
        return 10;
    }

    public function aplicar(Connection $conexao): void
    {
        $e = new Schema();
        $t = $e->createTable('objeto_termo');
        $t->addColumn('objeto_id', 'integer');
        $t->addColumn('termo', 'string', ['length' => 40]);
        $t->addColumn('pontos', 'integer');
        $t->setPrimaryKey(['objeto_id', 'termo']);
        $t->addIndex(['termo']);
        $conexao->createSchemaManager()->createSchemaObjects($e);

        $indexador = new IndexadorDeObjeto(new BuscaSql($conexao), new ClasseRepositorio($conexao));
        $ultimo = 0;
        while (($lote = $conexao->fetchAllAssociative('SELECT id, classe_id, titulo, descricao, dados FROM objeto WHERE id > ? ORDER BY id LIMIT 500', [$ultimo])) !== []) {
            foreach ($lote as $linha) {
                $ultimo = (int) $linha['id'];
                /** @var array<string, mixed> $dados */
                $dados = json_decode((string) $linha['dados'], true) ?: [];
                $indexador->indexar($ultimo, (int) $linha['classe_id'], (string) $linha['titulo'], (string) ($linha['descricao'] ?? ''), $dados);
            }
        }
    }
}
