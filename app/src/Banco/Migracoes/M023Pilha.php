<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Pbl\Banco\Migracao;

/**
 * Pilha de copiar/recortar/colar (fase 5r, ADR 0025): `pilha` guarda, por conta, os objetos separados para colar depois,
 * com o modo (copiar ou recortar) e a versão do objeto no momento em que entrou (checagem otimista ao colar).
 * Só adiciona uma tabela; a versão anterior do código a ignora (ADR 0002 e 0006).
 */
final class M023Pilha implements Migracao
{
    public function versao(): int
    {
        return 23;
    }

    public function aplicar(Connection $conexao): void
    {
        $gerente = $conexao->createSchemaManager();
        if ($gerente->tablesExist(['pilha'])) {
            return;
        }
        $e = new Schema();
        $t = $e->createTable('pilha');
        $t->addColumn('id', 'integer', ['autoincrement' => true]);
        $t->addColumn('usuario_id', 'integer');
        $t->addColumn('site_id', 'integer');
        $t->addColumn('objeto_id', 'integer');
        $t->addColumn('modo', 'string', ['length' => 10]);
        $t->addColumn('versao', 'integer');
        $t->addColumn('criado_em', 'string', ['length' => 32]);
        $t->setPrimaryKey(['id']);
        $t->addUniqueIndex(['usuario_id', 'objeto_id']);
        $gerente->createSchemaObjects($e);
    }
}
