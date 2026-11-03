<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Pbl\Banco\Migracao;

/**
 * Fluxo de revisão (fase 6, ADR 0022): `revisao` guarda um registro por passo (enviou, reenviou, retirou, aprovou, devolveu),
 * com quem, quando e, na devolução, o motivo escrito pelo revisor. Só adiciona uma tabela; a versão anterior do código a ignora (ADR 0006).
 */
final class M019FluxoDeRevisao implements Migracao
{
    public function versao(): int
    {
        return 19;
    }

    public function aplicar(Connection $conexao): void
    {
        // Idempotente: o `Migrador` guarda só a versão mais alta, e testes reaplicam migrações com a tabela já criada.
        $gerente = $conexao->createSchemaManager();
        if ($gerente->tablesExist(['revisao'])) {
            return;
        }
        $e = new Schema();
        $t = $e->createTable('revisao');
        $t->addColumn('id', 'integer', ['autoincrement' => true]);
        $t->addColumn('objeto_id', 'integer');
        $t->addColumn('usuario_id', 'integer', ['notnull' => false]);
        $t->addColumn('acao', 'string', ['length' => 20]);
        $t->addColumn('motivo', 'text', ['notnull' => false]);
        $t->addColumn('quando', 'string', ['length' => 32]);
        $t->setPrimaryKey(['id']);
        $t->addIndex(['objeto_id']);
        $t->addIndex(['usuario_id']);
        $gerente->createSchemaObjects($e);
    }
}
