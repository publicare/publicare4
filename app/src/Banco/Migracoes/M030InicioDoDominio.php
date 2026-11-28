<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Migracao;

/**
 * Página inicial por domínio: `dominio.inicio_objeto_id` é o conteúdo que aparece em `/` naquele endereço (por exemplo,
 * `faq.exemplo.org` abrindo direto o FAQ). Vazio = a página inicial do site, como sempre. Só **adiciona** (ADR 0006): o código
 * anterior ignora a coluna.
 *
 * Rollback manual (site em manutenção, depois de backup; SQLite >= 3.35, MySQL e PostgreSQL):
 *   ALTER TABLE dominio DROP COLUMN inicio_objeto_id;
 *   DELETE FROM schema_versao WHERE versao = 30;
 * Ou `bin/pbl reverter`, que restaura código e banco de antes da atualização.
 */
final class M030InicioDoDominio implements Migracao
{
    /** SQL do rollback manual (conferido por teste). */
    public const ROLLBACK = ['ALTER TABLE dominio DROP COLUMN inicio_objeto_id', 'DELETE FROM schema_versao WHERE versao = 30'];

    public function versao(): int
    {
        return 30;
    }

    public function aplicar(Connection $conexao): void
    {
        $colunas = $conexao->createSchemaManager()->listTableColumns('dominio');
        if (!isset($colunas['inicio_objeto_id'])) {
            $conexao->executeStatement('ALTER TABLE dominio ADD COLUMN inicio_objeto_id INTEGER NULL');
        }
    }
}
