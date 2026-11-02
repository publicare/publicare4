<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Migracao;

/**
 * Classes fixas (fase 5l, ADR 0015): a coluna `classe.fixa` marca os tipos de conteúdo essenciais, que não se apagam nem
 * perdem as propriedades da base. Só **adiciona** uma coluna com padrão 0 (a versão anterior do código a ignora, ADR 0006).
 * Em bancos que já têm `pasta`, `pagina`, `formulario`, `arquivo`, `imagem` ou `link`, marca-os como fixos; em um banco novo,
 * só `formulario` existe neste ponto (migração 11) e o instalador cria as outras já fixas.
 */
final class M016ClassesFixas implements Migracao
{
    public function versao(): int
    {
        return 16;
    }

    public function aplicar(Connection $conexao): void
    {
        // Idempotente: o `Migrador` guarda só a versão mais alta, e testes reaplicam migrações mais novas com a coluna já criada.
        $colunas = $conexao->createSchemaManager()->listTableColumns('classe');
        if (!isset($colunas['fixa'])) {
            $conexao->executeStatement('ALTER TABLE classe ADD COLUMN fixa INTEGER NOT NULL DEFAULT 0');
        }
        $conexao->executeStatement("UPDATE classe SET fixa = 1 WHERE nome IN ('pasta', 'pagina', 'formulario', 'arquivo', 'imagem', 'link')");
    }
}
