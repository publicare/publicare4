<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaGrupo
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema = $conexao->createSchemaManager();
        $tipos  = new TipoCompativel($conexao);

        $tabela       = EstruturaBanco::nomeTabela('grupo');
        $colId        = EstruturaBanco::nomeColuna('grupo', 'id');
        $colNome      = EstruturaBanco::nomeColuna('grupo', 'nome');
        $colDescricao = EstruturaBanco::nomeColuna('grupo', 'descricao');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colId} {$tipos->varchar(36)} PRIMARY KEY,
                    {$colNome} {$tipos->varchar(255)} NOT NULL,
                    {$colDescricao} {$tipos->texto()}
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
