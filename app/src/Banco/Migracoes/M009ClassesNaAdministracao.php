<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Migracao;

/**
 * O que a administração de classes precisa guardar além das propriedades (ADR 0012, fase 5c): descrição, se a classe aceita
 * filhos e quais classes filhas aceita, e a versão (contra sobrescrita cega quando duas pessoas editam a mesma classe).
 * Só adiciona colunas com valor padrão que mantém o comportamento de hoje (aceita filhos de qualquer classe), compatível com a
 * versão anterior do código (ADR 0006).
 */
final class M009ClassesNaAdministracao implements Migracao
{
    public function versao(): int
    {
        return 9;
    }

    public function aplicar(Connection $conexao): void
    {
        $conexao->executeStatement('ALTER TABLE classe ADD COLUMN descricao TEXT NULL');
        $conexao->executeStatement('ALTER TABLE classe ADD COLUMN aceita_filhos INTEGER NOT NULL DEFAULT 1');
        $conexao->executeStatement('ALTER TABLE classe ADD COLUMN filhas TEXT NULL'); // JSON: lista de nomes de classe; NULL = qualquer
        $conexao->executeStatement('ALTER TABLE classe ADD COLUMN versao INTEGER NOT NULL DEFAULT 1');
    }
}
