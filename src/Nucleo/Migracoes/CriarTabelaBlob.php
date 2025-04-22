<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaBlob
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema  = $conexao->createSchemaManager();
        $tipos   = new TipoCompativel($conexao);

        $tabela         = EstruturaBanco::nomeTabela('blob');
        $colId          = EstruturaBanco::nomeColuna('blob', 'id');
        $colObjeto      = EstruturaBanco::nomeColuna('blob', 'id_objeto');
        $colPropriedade = EstruturaBanco::nomeColuna('blob', 'id_propriedade');
        $colArquivo     = EstruturaBanco::nomeColuna('blob', 'id_arquivo');
        $colPublica     = EstruturaBanco::nomeColuna('blob', 'publica');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colId} {$tipos->varchar(36)} PRIMARY KEY,
                    {$colObjeto} {$tipos->varchar(36)} NOT NULL,
                    {$colPropriedade} {$tipos->varchar(36)} NOT NULL,
                    {$colArquivo} {$tipos->varchar(36)} NOT NULL,
                    {$colPublica} {$tipos->booleano()} DEFAULT 1,
                    FOREIGN KEY ({$colObjeto}) REFERENCES " . EstruturaBanco::nomeTabela('objeto') . "(" . EstruturaBanco::nomeColuna('objeto', 'id') . "),
                    FOREIGN KEY ({$colPropriedade}) REFERENCES " . EstruturaBanco::nomeTabela('propriedade') . "(" . EstruturaBanco::nomeColuna('propriedade', 'id') . "),
                    FOREIGN KEY ({$colArquivo}) REFERENCES " . EstruturaBanco::nomeTabela('arquivo') . "(" . EstruturaBanco::nomeColuna('arquivo', 'id') . ")
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
