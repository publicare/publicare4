<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Pbl\Banco\Migracao;

/**
 * Configurações do site (fase 5m, ADR 0017): `site_config` guarda uma linha por site e chave do catálogo (`ConfigDoSite`),
 * com o valor em JSON. Só adiciona uma tabela; a versão anterior do código a ignora (ADR 0006).
 */
final class M017ConfigDoSite implements Migracao
{
    public function versao(): int
    {
        return 17;
    }

    public function aplicar(Connection $conexao): void
    {
        // Idempotente: o `Migrador` guarda só a versão mais alta, e testes reaplicam migrações com a tabela já criada.
        $gerente = $conexao->createSchemaManager();
        if ($gerente->tablesExist(['site_config'])) {
            return;
        }
        $e = new Schema();
        $t = $e->createTable('site_config');
        $t->addColumn('site_id', 'integer');
        $t->addColumn('chave', 'string', ['length' => 50]);
        $t->addColumn('valor', 'text');
        $t->addColumn('atualizado_em', 'string', ['length' => 32]);
        $t->setPrimaryKey(['site_id', 'chave']);
        $gerente->createSchemaObjects($e);
    }
}
