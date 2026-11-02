<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Migracao;

/**
 * Busca nível C (ADR 0010, fase 1c): opção por site para sugerir grafia parecida ("você quis dizer…") quando uma busca
 * sem resultado parece ter erro de digitação. **Só adiciona uma coluna**, desligada por padrão (`0`): a versão anterior do
 * código a ignora e o comportamento de hoje não muda sozinho (ADR 0006). Não há tabela nova: a sugestão é calculada na
 * consulta, a partir de `objeto_termo` (nível B), com a mesma visibilidade pública de toda busca.
 */
final class M012BuscaTolerancia implements Migracao
{
    public function versao(): int
    {
        return 12;
    }

    public function aplicar(Connection $conexao): void
    {
        // Idempotente: uma instalação que já tinha a coluna (por exemplo, simulando um upgrade de antes da fase 1c
        // só a partir da versão 10, sem desfazer o que já é de versão mais nova) não tenta recriá-la.
        if (!isset($conexao->createSchemaManager()->listTableColumns('site')['busca_tolerancia'])) {
            $conexao->executeStatement('ALTER TABLE site ADD COLUMN busca_tolerancia INTEGER NOT NULL DEFAULT 0');
        }
    }
}
