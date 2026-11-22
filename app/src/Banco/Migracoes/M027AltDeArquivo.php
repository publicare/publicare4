<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Migracao;

/**
 * Texto alternativo de imagem (ADR 0036): `arquivo.alt`, por uso (cada envio é uma linha).
 * Só **adiciona** uma coluna (ADR 0002 e 0006): a versão anterior do código a ignora.
 */
final class M027AltDeArquivo implements Migracao
{
    public function versao(): int
    {
        return 27;
    }

    public function aplicar(Connection $conexao): void
    {
        $colunas = $conexao->createSchemaManager()->listTableColumns('arquivo');
        if (!isset($colunas['alt'])) {
            $conexao->executeStatement("ALTER TABLE arquivo ADD COLUMN alt TEXT NULL");
        }
    }
}
