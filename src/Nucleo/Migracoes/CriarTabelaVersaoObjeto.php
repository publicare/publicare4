<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaVersaoObjeto
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema  = $conexao->createSchemaManager();
        $tipos   = new TipoCompativel($conexao);

        $tabela        = EstruturaBanco::nomeTabela('versao_objeto');
        $colId         = EstruturaBanco::nomeColuna('versao_objeto', 'id');
        $colObjeto     = EstruturaBanco::nomeColuna('versao_objeto', 'id_objeto');
        $colVersao     = EstruturaBanco::nomeColuna('versao_objeto', 'versao');
        $colConteudo   = EstruturaBanco::nomeColuna('versao_objeto', 'conteudo');
        $colData       = EstruturaBanco::nomeColuna('versao_objeto', 'data_criacao');
        $colUsuario    = EstruturaBanco::nomeColuna('versao_objeto', 'id_usuario');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colId} {$tipos->varchar(36)} PRIMARY KEY,
                    {$colObjeto} {$tipos->varchar(36)} NOT NULL,
                    {$colVersao} {$tipos->inteiro()} NOT NULL,
                    {$colConteudo} {$tipos->texto()},
                    {$colData} {$tipos->timestamp()} DEFAULT CURRENT_TIMESTAMP,
                    {$colUsuario} {$tipos->varchar(36)},
                    FOREIGN KEY ({$colObjeto}) REFERENCES " . EstruturaBanco::nomeTabela('objeto') . "(" . EstruturaBanco::nomeColuna('objeto', 'id') . "),
                    FOREIGN KEY ({$colUsuario}) REFERENCES " . EstruturaBanco::nomeTabela('usuario') . "(" . EstruturaBanco::nomeColuna('usuario', 'id') . ")
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
