<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Migracao;
use Pbl\Busca\Texto;

/**
 * Busca que ignora maiúsculas **e acentos**: `objeto.titulo_norm` e `objeto_indice.texto_norm` guardam o texto normalizado
 * (`Texto::normalizar`). **Só adiciona colunas**: `titulo_busca` continua com o valor antigo (minúsculas com acento), para
 * a versão anterior do código seguir funcionando se for preciso voltar atrás (expandir/contrair, ADR 0006).
 *
 * Tamanho: a normalização pode alongar o texto (`ß`→`ss`, `œ`→`oe`: até 2x), então as colunas têm o dobro do espaço
 * das originais (`titulo` 300 → 600; `texto` 500 → 1000).
 */
final class M004BuscaNormalizada implements Migracao
{
    public function versao(): int
    {
        return 4;
    }

    public function aplicar(Connection $conexao): void
    {
        $conexao->executeStatement("ALTER TABLE objeto ADD COLUMN titulo_norm VARCHAR(600) NOT NULL DEFAULT ''");
        $conexao->executeStatement('ALTER TABLE objeto_indice ADD COLUMN texto_norm VARCHAR(1000) NULL');
        foreach ($conexao->fetchAllAssociative('SELECT id, titulo FROM objeto') as $linha) {
            $conexao->update('objeto', ['titulo_norm' => Texto::normalizar((string) $linha['titulo'])], ['id' => $linha['id']]);
        }
        foreach ($conexao->fetchAllAssociative('SELECT objeto_id, chave, texto FROM objeto_indice WHERE texto IS NOT NULL') as $linha) {
            $conexao->update(
                'objeto_indice',
                ['texto_norm' => Texto::normalizar((string) $linha['texto'])],
                ['objeto_id' => $linha['objeto_id'], 'chave' => $linha['chave']],
            );
        }
        $conexao->executeStatement('CREATE INDEX idx_objeto_titulo_norm ON objeto (titulo_norm)');
    }
}
