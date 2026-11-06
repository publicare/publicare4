<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Pbl\Banco\Migracao;

/**
 * Mapa de ids do importador do PBL3 (fase 9, ADR 0027): para cada coisa importada, o id que ela tinha na base de origem e o que
 * ganhou aqui. É o que torna a importação repetível e retomável. `fonte` rotula a base de origem (uma instalação pode receber
 * mais de uma); `entidade` é classe, usuario ou objeto. Só adiciona uma tabela (ADR 0006).
 */
final class M025ImportacaoPbl3 implements Migracao
{
    public function versao(): int
    {
        return 25;
    }

    public function aplicar(Connection $conexao): void
    {
        $gerente = $conexao->createSchemaManager();
        if ($gerente->tablesExist(['importacao_pbl3'])) {
            return;
        }
        $e = new Schema();
        $t = $e->createTable('importacao_pbl3');
        $t->addColumn('id', 'integer', ['autoincrement' => true]);
        $t->addColumn('fonte', 'string', ['length' => 64]);
        $t->addColumn('entidade', 'string', ['length' => 20]);
        $t->addColumn('id_origem', 'integer');
        $t->addColumn('id_destino', 'integer');
        $t->addColumn('importado_em', 'string', ['length' => 32]);
        $t->setPrimaryKey(['id']);
        $t->addUniqueIndex(['fonte', 'entidade', 'id_origem']);
        $t->addIndex(['entidade', 'id_destino']);
        $gerente->createSchemaObjects($e);
    }
}
