<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Migracao;
use Pbl\Busca\Texto;

/**
 * Busca que ignora maiúsculas **e acentos**: `titulo_busca` passa a ser o título normalizado (`Texto::normalizar`) e o
 * índice ganha `texto_norm`, a versão normalizada de cada propriedade de texto indexada. Os dados que já existem são
 * recalculados aqui. Só adiciona coluna (compatível com a versão anterior do código).
 */
final class M004BuscaNormalizada implements Migracao
{
    public function versao(): int
    {
        return 4;
    }

    public function aplicar(Connection $conexao): void
    {
        $conexao->executeStatement('ALTER TABLE objeto_indice ADD COLUMN texto_norm VARCHAR(500) NULL');
        foreach ($conexao->fetchAllAssociative('SELECT id, titulo FROM objeto') as $linha) {
            $conexao->update('objeto', ['titulo_busca' => Texto::normalizar((string) $linha['titulo'])], ['id' => $linha['id']]);
        }
        foreach ($conexao->fetchAllAssociative('SELECT objeto_id, chave, texto FROM objeto_indice WHERE texto IS NOT NULL') as $linha) {
            $conexao->update(
                'objeto_indice',
                ['texto_norm' => Texto::normalizar((string) $linha['texto'])],
                ['objeto_id' => $linha['objeto_id'], 'chave' => $linha['chave']],
            );
        }
    }
}
