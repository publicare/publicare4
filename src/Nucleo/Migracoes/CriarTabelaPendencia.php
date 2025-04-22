<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaPendencia
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema = $conexao->createSchemaManager();
        $tipos = new TipoCompativel($conexao);

        $tabela         = EstruturaBanco::nomeTabela('pendencia');
        $colId          = EstruturaBanco::nomeColuna('pendencia', 'id');
        $colObjeto      = EstruturaBanco::nomeColuna('pendencia', 'id_objeto');
        $colUsuario     = EstruturaBanco::nomeColuna('pendencia', 'id_usuario');
        $colStatus      = EstruturaBanco::nomeColuna('pendencia', 'id_status');
        $colData        = EstruturaBanco::nomeColuna('pendencia', 'data_criacao');
        $colMensagem    = EstruturaBanco::nomeColuna('pendencia', 'mensagem');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colId} {$tipos->varchar(36)} PRIMARY KEY,
                    {$colObjeto} {$tipos->varchar(36)} NOT NULL,
                    {$colUsuario} {$tipos->varchar(36)},
                    {$colStatus} {$tipos->varchar(36)},
                    {$colData} {$tipos->bigint()},
                    {$colMensagem} {$tipos->varchar(255)},
                    FOREIGN KEY ({$colObjeto}) REFERENCES " . EstruturaBanco::nomeTabela('objeto') . "(" . EstruturaBanco::nomeColuna('objeto', 'id') . "),
                    FOREIGN KEY ({$colUsuario}) REFERENCES " . EstruturaBanco::nomeTabela('usuario') . "(" . EstruturaBanco::nomeColuna('usuario', 'id') . ")
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
