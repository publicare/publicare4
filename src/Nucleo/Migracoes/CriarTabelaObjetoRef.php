<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaObjetoRef
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema  = $conexao->createSchemaManager();
        $tipos   = new TipoCompativel($conexao);

        $tabela         = EstruturaBanco::nomeTabela('objeto_ref');
        $colId          = EstruturaBanco::nomeColuna('objeto_ref', 'id');
        $colObjeto      = EstruturaBanco::nomeColuna('objeto_ref', 'id_objeto');
        $colPropriedade = EstruturaBanco::nomeColuna('objeto_ref', 'id_propriedade');
        $colValor       = EstruturaBanco::nomeColuna('objeto_ref', 'valor');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colId} {$tipos->varchar(36)} PRIMARY KEY,
                    {$colObjeto} {$tipos->varchar(36)} NOT NULL,
                    {$colPropriedade} {$tipos->varchar(36)} NOT NULL,
                    {$colValor} {$tipos->varchar(36)} NOT NULL,
                    FOREIGN KEY ({$colObjeto}) REFERENCES " . EstruturaBanco::nomeTabela('objeto') . "(" . EstruturaBanco::nomeColuna('objeto', 'id') . "),
                    FOREIGN KEY ({$colPropriedade}) REFERENCES " . EstruturaBanco::nomeTabela('propriedade') . "(" . EstruturaBanco::nomeColuna('propriedade', 'id') . "),
                    FOREIGN KEY ({$colValor}) REFERENCES " . EstruturaBanco::nomeTabela('objeto') . "(" . EstruturaBanco::nomeColuna('objeto', 'id') . ")
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
