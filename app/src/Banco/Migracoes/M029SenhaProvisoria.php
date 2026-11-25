<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Migracao;

/**
 * Senha provisória (ADR 0046): `usuario.senha_provisoria` marca a conta cuja senha o operador definiu e que precisa ser trocada no
 * primeiro acesso. Só **adiciona** (ADR 0006), com padrão 0: contas existentes não mudam e o código anterior ignora a coluna.
 *
 * Rollback manual (site em manutenção, depois de backup; SQLite >= 3.35, MySQL e PostgreSQL):
 *   ALTER TABLE usuario DROP COLUMN senha_provisoria;
 *   DELETE FROM schema_versao WHERE versao = 29;
 * Ou `bin/pbl reverter`, que restaura código e banco de antes da atualização.
 */
final class M029SenhaProvisoria implements Migracao
{
    /** SQL do rollback manual (conferido por teste). */
    public const ROLLBACK = ['ALTER TABLE usuario DROP COLUMN senha_provisoria', 'DELETE FROM schema_versao WHERE versao = 29'];

    public function versao(): int
    {
        return 29;
    }

    public function aplicar(Connection $conexao): void
    {
        $colunas = $conexao->createSchemaManager()->listTableColumns('usuario');
        if (!isset($colunas['senha_provisoria'])) {
            $conexao->executeStatement('ALTER TABLE usuario ADD COLUMN senha_provisoria INTEGER NOT NULL DEFAULT 0');
        }
    }
}
