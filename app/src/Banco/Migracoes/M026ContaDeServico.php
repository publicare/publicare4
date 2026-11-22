<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Migracao;

/**
 * Conta de serviço (ADR 0031): `usuario.tipo` é `pessoa` (padrão) ou `servico` (agente via CLI, sem login pelo navegador).
 * Só **adiciona** uma coluna (ADR 0002 e 0006): a versão anterior do código a ignora.
 */
final class M026ContaDeServico implements Migracao
{
    public function versao(): int
    {
        return 26;
    }

    public function aplicar(Connection $conexao): void
    {
        $colunas = $conexao->createSchemaManager()->listTableColumns('usuario');
        if (!isset($colunas['tipo'])) {
            $conexao->executeStatement("ALTER TABLE usuario ADD COLUMN tipo VARCHAR(20) NOT NULL DEFAULT 'pessoa'");
        }
    }
}
