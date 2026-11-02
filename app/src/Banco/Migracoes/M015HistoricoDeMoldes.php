<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Pbl\Banco\Migracao;

/**
 * Histórico de versões de molde (fase 5j, ADR 0007/0012 §9): `pele_molde_versao` guarda uma linha por gravação
 * (criar, salvar, apagar ou voltar), com o conteúdo inteiro, o hash e quem fez, para a tela de histórico, a
 * diferença entre versões e "voltar a esta versão". `pele_molde_rascunho` guarda, no máximo, um rascunho por
 * molde (chave `pele`+`molde`): salvar como rascunho não grava no arquivo nem gera versão; publicar o rascunho
 * grava o arquivo, gera a versão e apaga a linha. Só adiciona tabelas; nada em `GerenciadorDePeles` muda de
 * comportamento para quem já usava a fase 5e.
 */
final class M015HistoricoDeMoldes implements Migracao
{
    public function versao(): int
    {
        return 15;
    }

    public function aplicar(Connection $conexao): void
    {
        // O `Migrador` guarda só a versão mais alta; testes que "voltam" uma versão anterior reaplicam esta
        // migração com as tabelas já presentes. Por isso cada tabela só é criada se ainda não existir.
        $gerente = $conexao->createSchemaManager();
        $e = new Schema();

        if (!$gerente->tablesExist(['pele_molde_versao'])) {
            $this->versoes($e);
        }
        if (!$gerente->tablesExist(['pele_molde_rascunho'])) {
            $this->rascunhos($e);
        }
        if ($e->getTables() !== []) {
            $gerente->createSchemaObjects($e);
        }
    }

    private function versoes(Schema $e): void
    {
        $v = $e->createTable('pele_molde_versao');
        $v->addColumn('id', 'integer', ['autoincrement' => true]);
        $v->addColumn('pele', 'string', ['length' => 50]);
        $v->addColumn('molde', 'string', ['length' => 100]);
        $v->addColumn('versao', 'integer');
        $v->addColumn('conteudo', 'text');
        $v->addColumn('hash', 'string', ['length' => 64]);
        $v->addColumn('acao', 'string', ['length' => 20]);
        $v->addColumn('usuario_id', 'integer', ['notnull' => false]);
        $v->addColumn('criado_em', 'string', ['length' => 32]);
        $v->setPrimaryKey(['id']);
        $v->addUniqueIndex(['pele', 'molde', 'versao']);
    }

    private function rascunhos(Schema $e): void
    {
        $r = $e->createTable('pele_molde_rascunho');
        $r->addColumn('pele', 'string', ['length' => 50]);
        $r->addColumn('molde', 'string', ['length' => 100]);
        $r->addColumn('conteudo', 'text');
        $r->addColumn('usuario_id', 'integer', ['notnull' => false]);
        $r->addColumn('atualizado_em', 'string', ['length' => 32]);
        $r->setPrimaryKey(['pele', 'molde']);
    }
}
