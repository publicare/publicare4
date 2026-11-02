<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Migracao;

/**
 * "Modo elevado" (fase 5h, ADR 0011/0012): a sessão guarda até quando a senha foi confirmada de novo. Uma só coluna,
 * em `sessao`: a janela acaba sozinha quando a sessão acaba (logout, troca de senha, expiração), sem tabela nem
 * limpeza extra.
 */
final class M012ModoElevado implements Migracao
{
    public function versao(): int
    {
        return 12;
    }

    public function aplicar(Connection $conexao): void
    {
        // O `Migrador` rastreia só a versão mais alta já aplicada (não cada migração individualmente), então um
        // teste que "volta" a versão de uma migração anterior para testá-la de novo também reaplica esta (sem
        // tocar na coluna, que continua no banco); por isso a conferência, para o ALTER TABLE não falhar.
        $colunas = $conexao->createSchemaManager()->listTableColumns('sessao');
        if (isset($colunas['elevado_ate'])) {
            return;
        }
        $conexao->executeStatement('ALTER TABLE sessao ADD COLUMN elevado_ate INTEGER NULL');
    }
}
