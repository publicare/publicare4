<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Migracao;

/**
 * `titulo_busca`: o título em minúsculas **Unicode** (`mb_strtolower`), gravado ao criar o objeto. A busca por título
 * compara com ela, em vez de `LOWER()`, que no SQLite só rebaixa ASCII (`Árvore` não casaria com `árvore`).
 * Funciona igual em SQLite, MySQL e PostgreSQL. Só adiciona coluna e índice (compatível com a versão anterior).
 */
final class M003TituloBusca implements Migracao
{
    public function versao(): int
    {
        return 3;
    }

    public function aplicar(Connection $conexao): void
    {
        $conexao->executeStatement("ALTER TABLE objeto ADD COLUMN titulo_busca VARCHAR(300) NOT NULL DEFAULT ''");
        foreach ($conexao->fetchAllAssociative('SELECT id, titulo FROM objeto') as $linha) {
            $conexao->update('objeto', ['titulo_busca' => mb_strtolower((string) $linha['titulo'])], ['id' => $linha['id']]);
        }
        $conexao->executeStatement('CREATE INDEX idx_objeto_titulo_busca ON objeto (titulo_busca)');
    }
}
