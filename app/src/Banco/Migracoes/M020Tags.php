<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Pbl\Banco\Migracao;

/**
 * Tags nos objetos (fase 5r, ADR 0024): `tag` é por site (nome + slug únicos no site) e `objeto_tag` liga objeto e tag.
 * Só adiciona tabelas; a versão anterior do código as ignora (ADR 0006).
 */
final class M020Tags implements Migracao
{
    public function versao(): int
    {
        return 20;
    }

    public function aplicar(Connection $conexao): void
    {
        // Idempotente: o `Migrador` guarda só a versão mais alta, e testes reaplicam migrações com as tabelas já criadas.
        $gerente = $conexao->createSchemaManager();
        if ($gerente->tablesExist(['tag'])) {
            return;
        }
        $e = new Schema();
        $t = $e->createTable('tag');
        $t->addColumn('id', 'integer', ['autoincrement' => true]);
        $t->addColumn('site_id', 'integer');
        $t->addColumn('nome', 'string', ['length' => 80]);
        $t->addColumn('slug', 'string', ['length' => 80]);
        $t->addColumn('criado_em', 'string', ['length' => 32]);
        $t->setPrimaryKey(['id']);
        $t->addUniqueIndex(['site_id', 'slug']);

        $l = $e->createTable('objeto_tag');
        $l->addColumn('objeto_id', 'integer');
        $l->addColumn('tag_id', 'integer');
        $l->setPrimaryKey(['objeto_id', 'tag_id']);
        $l->addIndex(['tag_id']);
        $gerente->createSchemaObjects($e);
    }
}
