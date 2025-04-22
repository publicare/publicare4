<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaRelacao
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema  = $conexao->createSchemaManager();
        $tipos   = new TipoCompativel($conexao);

        $tabela     = EstruturaBanco::nomeTabela('relacao');
        $colId      = EstruturaBanco::nomeColuna('relacao', 'id');
        $colObjeto  = EstruturaBanco::nomeColuna('relacao', 'id_objeto');
        $colPai     = EstruturaBanco::nomeColuna('relacao', 'id_pai');
        $colClasse  = EstruturaBanco::nomeColuna('relacao', 'id_classe');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colId} {$tipos->varchar(36)} PRIMARY KEY,
                    {$colObjeto} {$tipos->varchar(36)} NOT NULL,
                    {$colPai} {$tipos->varchar(36)} NOT NULL,
                    {$colClasse} {$tipos->varchar(36)},
                    FOREIGN KEY ({$colObjeto}) REFERENCES " . EstruturaBanco::nomeTabela('objeto') . "(" . EstruturaBanco::nomeColuna('objeto', 'id') . "),
                    FOREIGN KEY ({$colPai}) REFERENCES " . EstruturaBanco::nomeTabela('objeto') . "(" . EstruturaBanco::nomeColuna('objeto', 'id') . "),
                    FOREIGN KEY ({$colClasse}) REFERENCES " . EstruturaBanco::nomeTabela('classe') . "(" . EstruturaBanco::nomeColuna('classe', 'id') . ")
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
