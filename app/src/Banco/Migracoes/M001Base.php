<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Pbl\Banco\Migracao;

/** Schema inicial do PBL4 (ADR 0003): objeto com dados em JSON, caminho materializado e índice tipado. */
final class M001Base implements Migracao
{
    public function versao(): int
    {
        return 1;
    }

    public function aplicar(Connection $conexao): void
    {
        $e = new Schema();

        $site = $e->createTable('site');
        $site->addColumn('id', 'integer', ['autoincrement' => true]);
        $site->addColumn('nome', 'string', ['length' => 200]);
        $site->addColumn('objeto_raiz_id', 'integer', ['notnull' => false]);
        $site->addColumn('pele', 'string', ['length' => 100]);
        $site->setPrimaryKey(['id']);

        $dominio = $e->createTable('dominio');
        $dominio->addColumn('host', 'string', ['length' => 255]);
        $dominio->addColumn('site_id', 'integer');
        $dominio->setPrimaryKey(['host']);

        $classe = $e->createTable('classe');
        $classe->addColumn('id', 'integer', ['autoincrement' => true]);
        $classe->addColumn('nome', 'string', ['length' => 100]);
        $classe->addColumn('propriedades', 'text'); // JSON: lista de {nome, tipo, indexavel}
        $classe->setPrimaryKey(['id']);
        $classe->addUniqueIndex(['nome']);

        $objeto = $e->createTable('objeto');
        $objeto->addColumn('id', 'integer', ['autoincrement' => true]);
        $objeto->addColumn('site_id', 'integer');
        $objeto->addColumn('classe_id', 'integer');
        $objeto->addColumn('pai_id', 'integer', ['notnull' => false]);
        $objeto->addColumn('caminho', 'string', ['length' => 500]); // /1/45/230/
        $objeto->addColumn('titulo', 'string', ['length' => 300]);
        $objeto->addColumn('url_amigavel', 'string', ['length' => 300]);
        $objeto->addColumn('status', 'string', ['length' => 20]);
        $objeto->addColumn('dados', 'text'); // JSON validado pela classe
        $objeto->addColumn('criado_em', 'string', ['length' => 32]);
        $objeto->addColumn('atualizado_em', 'string', ['length' => 32]);
        $objeto->setPrimaryKey(['id']);
        $objeto->addUniqueIndex(['site_id', 'url_amigavel']);
        $objeto->addIndex(['caminho']);
        $objeto->addIndex(['pai_id']);

        $indice = $e->createTable('objeto_indice');
        $indice->addColumn('objeto_id', 'integer');
        $indice->addColumn('chave', 'string', ['length' => 100]);
        $indice->addColumn('texto', 'string', ['length' => 500, 'notnull' => false]);
        $indice->addColumn('numero', 'float', ['notnull' => false]);
        $indice->setPrimaryKey(['objeto_id', 'chave']);
        $indice->addIndex(['chave', 'texto']);

        $conexao->createSchemaManager()->createSchemaObjects($e);
    }
}
