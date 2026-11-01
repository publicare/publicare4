<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Migracao;

/**
 * Metadados do objeto que o PBL3 já tinha e os moldes reais usam o tempo todo: `peso` (ordem manual,
 * padrão de ordenação), `descricao`, vigência (`data_publicacao`, `data_validade`). Só adiciona colunas
 * (compatível com a versão anterior do código, ADR 0006).
 */
final class M002Metadados implements Migracao
{
    public function versao(): int
    {
        return 2;
    }

    public function aplicar(Connection $conexao): void
    {
        $conexao->executeStatement('ALTER TABLE objeto ADD COLUMN peso INTEGER NOT NULL DEFAULT 0');
        $conexao->executeStatement('ALTER TABLE objeto ADD COLUMN descricao TEXT NULL');
        $conexao->executeStatement('ALTER TABLE objeto ADD COLUMN data_publicacao VARCHAR(32) NULL');
        $conexao->executeStatement('ALTER TABLE objeto ADD COLUMN data_validade VARCHAR(32) NULL');
    }
}
