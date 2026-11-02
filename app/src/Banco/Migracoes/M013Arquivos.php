<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Pbl\Banco\Migracao;

/**
 * Upload de arquivos (fase 7a, ADR 0014): tabela `arquivo` com os metadados de cada arquivo guardado em `dados/uploads/`.
 * Só adiciona uma tabela: a versão anterior do código a ignora (ADR 0006). O valor de uma propriedade `arquivo`/`imagem` no
 * objeto é o id desta tabela (como uma `ref`, mas para `arquivo`, não para `objeto`).
 */
final class M013Arquivos implements Migracao
{
    public function versao(): int
    {
        return 13;
    }

    public function aplicar(Connection $conexao): void
    {
        $gerenciador = $conexao->createSchemaManager();
        if ($gerenciador->tablesExist(['arquivo'])) {
            // Já existe (por exemplo, um `schema_versao` manipulado à mão para refazer uma migração mais nova sem refazer
            // esta): nada a fazer, como o `M011ClasseFormulario` faz para a classe `formulario`.
            return;
        }
        $e = new Schema();
        $t = $e->createTable('arquivo');
        $t->addColumn('id', 'integer', ['autoincrement' => true]);
        $t->addColumn('site_id', 'integer');
        // Objeto ao qual o arquivo foi enviado: decide a visibilidade pública (publicado e vigente). Nunca muda depois.
        $t->addColumn('objeto_id', 'integer');
        $t->addColumn('propriedade', 'string', ['length' => 64]);
        // Nome do arquivo em disco (aleatório, nunca derivado do nome enviado): dados/uploads/<2 primeiros>/<chave>.<extensao>.
        $t->addColumn('chave', 'string', ['length' => 64]);
        $t->addColumn('extensao', 'string', ['length' => 10]);
        $t->addColumn('mime', 'string', ['length' => 120]);
        $t->addColumn('nome', 'string', ['length' => 255]); // nome original, saneado só para exibição (nunca um caminho)
        $t->addColumn('tamanho', 'integer');
        $t->addColumn('sha256', 'string', ['length' => 64]);
        $t->addColumn('largura', 'integer', ['notnull' => false]);
        $t->addColumn('altura', 'integer', ['notnull' => false]);
        // Imagem recodificada pelo GD (payload neutralizado, EXIF removido) ou arquivo só validado pelo cabeçalho (sem GD).
        $t->addColumn('sanitizada', 'boolean');
        $t->addColumn('criado_em', 'string', ['length' => 32]);
        $t->addColumn('criado_por', 'integer', ['notnull' => false]);
        $t->setPrimaryKey(['id']);
        $t->addIndex(['objeto_id']);
        $t->addIndex(['chave']);
        $t->addIndex(['sha256', 'extensao']); // deduplicação: mesmo conteúdo reaproveita o arquivo em disco
        $gerenciador->createSchemaObjects($e);
    }
}
