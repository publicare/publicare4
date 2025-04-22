<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaFloat
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema  = $conexao->createSchemaManager();
        $tipos   = new TipoCompativel($conexao);

        $tabela         = EstruturaBanco::nomeTabela('float');
        $colId          = EstruturaBanco::nomeColuna('float', 'id');
        $colObjeto      = EstruturaBanco::nomeColuna('float', 'id_objeto');
        $colPropriedade = EstruturaBanco::nomeColuna('float', 'id_propriedade');
        $colValor       = EstruturaBanco::nomeColuna('float', 'valor');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colId} {$tipos->varchar(36)} PRIMARY KEY,
                    {$colObjeto} {$tipos->varchar(36)} NOT NULL,
                    {$colPropriedade} {$tipos->varchar(36)} NOT NULL,
                    {$colValor} {$tipos->decimal(18,4)},
                    FOREIGN KEY ({$colObjeto}) REFERENCES " . EstruturaBanco::nomeTabela('objeto') . "(" . EstruturaBanco::nomeColuna('objeto', 'id') . "),
                    FOREIGN KEY ({$colPropriedade}) REFERENCES " . EstruturaBanco::nomeTabela('propriedade') . "(" . EstruturaBanco::nomeColuna('propriedade', 'id') . ")
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
