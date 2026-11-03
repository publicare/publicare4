<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Migracao;

/**
 * ADR 0022. Pele e molde por objeto: duas colunas nulas em `objeto` (nulo = herdar do ancestral ou do site). Só **adiciona**
 * colunas; a versão anterior do código as ignora (ADR 0006).
 */
final class M018PeleEMoldePorObjeto implements Migracao
{
    public function versao(): int
    {
        return 18;
    }

    public function aplicar(Connection $conexao): void
    {
        $colunas = $conexao->createSchemaManager()->listTableColumns('objeto');
        if (!isset($colunas['pele'])) {
            $conexao->executeStatement('ALTER TABLE objeto ADD COLUMN pele TEXT NULL');
        }
        if (!isset($colunas['molde'])) {
            $conexao->executeStatement('ALTER TABLE objeto ADD COLUMN molde TEXT NULL');
        }
    }
}
