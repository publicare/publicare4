<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Pbl\Banco\Migracao;

/**
 * Domínios com papel (`dominio.modo`: `principal`, `servir` ou `redirecionar`) e regras manuais de redirecionamento por
 * site (`redirecionamento`). Só adiciona uma coluna (com padrão `principal`, o que todo domínio existente já era) e uma tabela.
 */
final class M006DominiosERedirecionamentos implements Migracao
{
    public function versao(): int
    {
        return 6;
    }

    public function aplicar(Connection $conexao): void
    {
        $conexao->executeStatement("ALTER TABLE dominio ADD COLUMN modo VARCHAR(12) NOT NULL DEFAULT 'principal'");

        // O padrão marcou todo domínio existente como principal; um site só pode ter um. Fica principal o primeiro (em ordem
        // alfabética) e os demais passam a servir o mesmo conteúdo com o principal como canônico.
        foreach ($conexao->fetchAllAssociative('SELECT site_id, MIN(host) AS principal FROM dominio GROUP BY site_id') as $s) {
            $conexao->executeStatement("UPDATE dominio SET modo = 'servir' WHERE site_id = ? AND host <> ?", [$s['site_id'], $s['principal']]);
        }

        $e = new Schema();
        $r = $e->createTable('redirecionamento');
        $r->addColumn('id', 'integer', ['autoincrement' => true]);
        $r->addColumn('site_id', 'integer');
        $r->addColumn('origem', 'string', ['length' => 300]);
        $r->addColumn('destino', 'string', ['length' => 500]);
        $r->addColumn('codigo', 'integer');
        $r->addColumn('prefixo', 'integer', ['default' => 0]);
        $r->addColumn('criado_em', 'string', ['length' => 32]);
        $r->setPrimaryKey(['id']);
        $r->addUniqueIndex(['site_id', 'origem', 'prefixo']);
        $conexao->createSchemaManager()->createSchemaObjects($e);
    }
}
