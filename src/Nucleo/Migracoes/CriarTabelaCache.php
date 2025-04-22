<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaCache
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema = $conexao->createSchemaManager();
        $tipos = new TipoCompativel($conexao);

        $tabela         = EstruturaBanco::nomeTabela('cache');
        $colId          = EstruturaBanco::nomeColuna('cache', 'id');
        $colObjeto      = EstruturaBanco::nomeColuna('cache', 'id_objeto');
        $colConteudo    = EstruturaBanco::nomeColuna('cache', 'conteudo');
        $colHash        = EstruturaBanco::nomeColuna('cache', 'hash');
        $colDataCriacao = EstruturaBanco::nomeColuna('cache', 'data_criacao');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colId} {$tipos->varchar(36)} PRIMARY KEY,
                    {$colObjeto} {$tipos->varchar(36)} NOT NULL,
                    {$colConteudo} {$tipos->texto()},
                    {$colHash} {$tipos->varchar(255)},
                    {$colDataCriacao} {$tipos->bigint()},
                    FOREIGN KEY ({$colObjeto}) REFERENCES " . EstruturaBanco::nomeTabela('objeto') . "(" . EstruturaBanco::nomeColuna('objeto', 'id') . ")
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
