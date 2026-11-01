<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Pbl\Banco\Migracao;

/**
 * Histórico de versões de cada objeto (`objeto_versao`, uma linha por estado salvo, a atual inclusive) e histórico de URLs
 * (`url_historico`: endereço antigo → objeto; vira redirecionamento 301). Só adiciona tabelas e uma coluna (`objeto.versao`).
 */
final class M005VersoesEHistoricoDeUrls implements Migracao
{
    public function versao(): int
    {
        return 5;
    }

    public function aplicar(Connection $conexao): void
    {
        $conexao->executeStatement('ALTER TABLE objeto ADD COLUMN versao INTEGER NOT NULL DEFAULT 1');

        $e = new Schema();
        $v = $e->createTable('objeto_versao');
        $v->addColumn('id', 'integer', ['autoincrement' => true]);
        $v->addColumn('objeto_id', 'integer');
        $v->addColumn('versao', 'integer');
        $v->addColumn('titulo', 'string', ['length' => 300]);
        $v->addColumn('url_amigavel', 'string', ['length' => 300]);
        $v->addColumn('status', 'string', ['length' => 20]);
        $v->addColumn('dados', 'text');
        $v->addColumn('peso', 'integer', ['default' => 0]);
        $v->addColumn('descricao', 'text', ['notnull' => false]);
        $v->addColumn('data_publicacao', 'string', ['length' => 32, 'notnull' => false]);
        $v->addColumn('data_validade', 'string', ['length' => 32, 'notnull' => false]);
        $v->addColumn('pai_id', 'integer', ['notnull' => false]);
        $v->addColumn('motivo', 'string', ['length' => 300, 'notnull' => false]);
        $v->addColumn('criado_em', 'string', ['length' => 32]);
        $v->setPrimaryKey(['id']);
        $v->addUniqueIndex(['objeto_id', 'versao']);

        $h = $e->createTable('url_historico');
        $h->addColumn('site_id', 'integer');
        $h->addColumn('url_amigavel', 'string', ['length' => 300]);
        $h->addColumn('objeto_id', 'integer');
        $h->addColumn('criado_em', 'string', ['length' => 32]);
        $h->setPrimaryKey(['site_id', 'url_amigavel']);
        $h->addIndex(['objeto_id']);

        $conexao->createSchemaManager()->createSchemaObjects($e);

        // Objetos que já existiam ganham a versão 1 como retrato do estado atual.
        $agora = gmdate('c');
        foreach ($conexao->fetchAllAssociative('SELECT * FROM objeto') as $o) {
            $conexao->insert('objeto_versao', [
                'objeto_id' => $o['id'], 'versao' => 1, 'titulo' => $o['titulo'], 'url_amigavel' => $o['url_amigavel'],
                'status' => $o['status'], 'dados' => $o['dados'], 'peso' => $o['peso'], 'descricao' => $o['descricao'],
                'data_publicacao' => $o['data_publicacao'], 'data_validade' => $o['data_validade'], 'pai_id' => $o['pai_id'],
                'motivo' => 'estado inicial', 'criado_em' => $agora,
            ]);
        }
    }
}
