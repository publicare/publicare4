<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaValor
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema = $conexao->createSchemaManager();
        $tipos = new TipoCompativel($conexao);

        $tabela         = EstruturaBanco::nomeTabela('valor');
        $colId          = EstruturaBanco::nomeColuna('valor', 'id');
        $colObjeto      = EstruturaBanco::nomeColuna('valor', 'id_objeto');
        $colPropriedade = EstruturaBanco::nomeColuna('valor', 'id_propriedade');
        $colValor       = EstruturaBanco::nomeColuna('valor', 'valor');
        $colVersao      = EstruturaBanco::nomeColuna('valor', 'id_versao');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colId} {$tipos->varchar(36)} PRIMARY KEY,
                    {$colObjeto} {$tipos->varchar(36)} NOT NULL,
                    {$colPropriedade} {$tipos->varchar(36)} NOT NULL,
                    {$colValor} {$tipos->texto()},
                    {$colVersao} {$tipos->varchar(36)},
                    FOREIGN KEY ({$colObjeto}) REFERENCES " . EstruturaBanco::nomeTabela('objeto') . "(" . EstruturaBanco::nomeColuna('objeto', 'id') . "),
                    FOREIGN KEY ({$colPropriedade}) REFERENCES " . EstruturaBanco::nomeTabela('propriedade') . "(" . EstruturaBanco::nomeColuna('propriedade', 'id') . "),
                    FOREIGN KEY ({$colVersao}) REFERENCES " . EstruturaBanco::nomeTabela('versao_objeto') . "(" . EstruturaBanco::nomeColuna('versao_objeto', 'id') . ")
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
