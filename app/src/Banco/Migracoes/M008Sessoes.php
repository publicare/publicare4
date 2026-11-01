<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Pbl\Banco\Migracao;

/** Sessões da administração (só o hash do token é guardado) e as tentativas de login que falharam (ADR 0011). Só adiciona tabelas. */
final class M008Sessoes implements Migracao
{
    public function versao(): int
    {
        return 8;
    }

    public function aplicar(Connection $conexao): void
    {
        $e = new Schema();
        $s = $e->createTable('sessao');
        $s->addColumn('id_hash', 'string', ['length' => 64]);
        $s->addColumn('usuario_id', 'integer');
        $s->addColumn('criado_em', 'integer');
        $s->addColumn('expira_em', 'integer');
        $s->addColumn('ultimo_uso', 'integer');
        $s->setPrimaryKey(['id_hash']);
        $s->addIndex(['usuario_id']);
        $s->addIndex(['expira_em']);

        $t = $e->createTable('tentativa_login');
        $t->addColumn('id', 'integer', ['autoincrement' => true]);
        $t->addColumn('chave_hash', 'string', ['length' => 64]);
        $t->addColumn('quando', 'integer');
        $t->setPrimaryKey(['id']);
        $t->addIndex(['chave_hash', 'quando']);

        $conexao->createSchemaManager()->createSchemaObjects($e);
    }
}
