<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Migracao;

/**
 * Ícone por tipo de conteúdo (fase 5r, ADR 0026): `classe.icone` guarda a chave de um ícone do conjunto fechado do painel
 * (vazio = sem ícone). Só **adiciona** uma coluna opcional; a versão anterior do código a ignora (ADR 0002 e 0006).
 */
final class M024IconeDeClasse implements Migracao
{
    public function versao(): int
    {
        return 24;
    }

    public function aplicar(Connection $conexao): void
    {
        $colunas = $conexao->createSchemaManager()->listTableColumns('classe');
        if (!isset($colunas['icone'])) {
            $conexao->executeStatement('ALTER TABLE classe ADD COLUMN icone VARCHAR(30) NULL');
        }
    }
}
