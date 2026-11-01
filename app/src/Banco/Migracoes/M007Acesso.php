<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Pbl\Banco\Migracao;

/**
 * Equipe e permissões (ADR 0011): `usuario`, `acesso` (perfil de um usuário numa subárvore) e `auditoria` (só acrescenta).
 * Em tabelas existentes só adiciona colunas: `objeto.criado_por` (o autor só edita o que criou) e `objeto_versao.usuario_id`
 * (quem fez cada alteração).
 */
final class M007Acesso implements Migracao
{
    public function versao(): int
    {
        return 7;
    }

    public function aplicar(Connection $conexao): void
    {
        $conexao->executeStatement('ALTER TABLE objeto ADD COLUMN criado_por INTEGER NULL');
        $conexao->executeStatement('ALTER TABLE objeto_versao ADD COLUMN usuario_id INTEGER NULL');

        $e = new Schema();
        $u = $e->createTable('usuario');
        $u->addColumn('id', 'integer', ['autoincrement' => true]);
        $u->addColumn('login', 'string', ['length' => 64]);
        $u->addColumn('email', 'string', ['length' => 255]);
        $u->addColumn('nome', 'string', ['length' => 200]);
        $u->addColumn('senha_hash', 'string', ['length' => 255]);
        $u->addColumn('administrador', 'integer', ['default' => 0]);
        $u->addColumn('ativo', 'integer', ['default' => 1]);
        $u->addColumn('criado_em', 'string', ['length' => 32]);
        $u->addColumn('ultimo_acesso', 'string', ['length' => 32, 'notnull' => false]);
        $u->setPrimaryKey(['id']);
        $u->addUniqueIndex(['login']);

        $a = $e->createTable('acesso');
        $a->addColumn('usuario_id', 'integer');
        $a->addColumn('objeto_id', 'integer');
        $a->addColumn('perfil', 'string', ['length' => 20]);
        $a->setPrimaryKey(['usuario_id', 'objeto_id']);
        $a->addIndex(['objeto_id']);

        $l = $e->createTable('auditoria');
        $l->addColumn('id', 'integer', ['autoincrement' => true]);
        $l->addColumn('quando', 'string', ['length' => 32]);
        $l->addColumn('usuario_id', 'integer', ['notnull' => false]);
        $l->addColumn('ator', 'string', ['length' => 100]);
        $l->addColumn('acao', 'string', ['length' => 60]);
        $l->addColumn('objeto_id', 'integer', ['notnull' => false]);
        $l->addColumn('detalhe', 'text', ['notnull' => false]);
        $l->addColumn('ip_hash', 'string', ['length' => 64, 'notnull' => false]);
        $l->setPrimaryKey(['id']);
        $l->addIndex(['quando']);
        $l->addIndex(['usuario_id']);

        $conexao->createSchemaManager()->createSchemaObjects($e);
    }
}
