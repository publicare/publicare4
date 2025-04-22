<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaCampo
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema  = $conexao->createSchemaManager();
        $tipos   = new TipoCompativel($conexao);

        $tabela         = EstruturaBanco::nomeTabela('campo');
        $colId          = EstruturaBanco::nomeColuna('campo', 'id');
        $colObjeto      = EstruturaBanco::nomeColuna('campo', 'id_objeto');
        $colPropriedade = EstruturaBanco::nomeColuna('campo', 'id_propriedade');
        $colOrdem       = EstruturaBanco::nomeColuna('campo', 'ordem');
        $colVisivel     = EstruturaBanco::nomeColuna('campo', 'visivel');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colId} {$tipos->varchar(36)} PRIMARY KEY,
                    {$colObjeto} {$tipos->varchar(36)} NOT NULL,
                    {$colPropriedade} {$tipos->varchar(36)} NOT NULL,
                    {$colOrdem} {$tipos->inteiro()} DEFAULT 0,
                    {$colVisivel} {$tipos->booleano()} DEFAULT 1,
                    FOREIGN KEY ({$colObjeto}) REFERENCES " . EstruturaBanco::nomeTabela('objeto') . "(" . EstruturaBanco::nomeColuna('objeto', 'id') . "),
                    FOREIGN KEY ({$colPropriedade}) REFERENCES " . EstruturaBanco::nomeTabela('propriedade') . "(" . EstruturaBanco::nomeColuna('propriedade', 'id') . ")
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
