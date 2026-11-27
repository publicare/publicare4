<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\EsquemaPortavel;
use Pbl\Banco\Migracao;

/**
 * Tokens de conta de uso único (ADR 0035): recuperação de senha, convite e troca de e-mail. Só o hash é guardado. Também
 * acrescenta `usuario.convite_pendente` e `usuario.email_confirmado_em` (ADR 0038). Só **adiciona** (ADR 0006).
 */
final class M028TokenDeConta implements Migracao
{
    public function versao(): int
    {
        return 28;
    }

    public function aplicar(Connection $conexao): void
    {
        $gerente = $conexao->createSchemaManager();
        if (!$gerente->tablesExist(['token_conta'])) {
            $e = EsquemaPortavel::novo($conexao);
            $t = $e->createTable('token_conta');
            $t->addColumn('id', 'integer', ['autoincrement' => true]);
            $t->addColumn('tipo', 'string', ['length' => 20]);
            $t->addColumn('usuario_id', 'integer');
            $t->addColumn('token_hash', 'string', ['length' => 64]);
            $t->addColumn('email_alvo', 'string', ['length' => 255, 'notnull' => false]);
            $t->addColumn('criado_em', 'integer');
            $t->addColumn('expira_em', 'integer');
            $t->addColumn('usado_em', 'integer', ['notnull' => false]);
            $t->addColumn('ip_hash', 'string', ['length' => 64, 'notnull' => false]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['token_hash']);
            $t->addIndex(['usuario_id', 'tipo']);
            $t->addIndex(['expira_em']);
            $gerente->createSchemaObjects($e);
        }
        $colunas = $gerente->listTableColumns('usuario');
        if (!isset($colunas['convite_pendente'])) {
            $conexao->executeStatement('ALTER TABLE usuario ADD COLUMN convite_pendente INTEGER NOT NULL DEFAULT 0');
        }
        if (!isset($colunas['email_confirmado_em'])) {
            $conexao->executeStatement('ALTER TABLE usuario ADD COLUMN email_confirmado_em VARCHAR(40) NULL');
        }
    }
}
