<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaLog
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema = $conexao->createSchemaManager();
        $tipos = new TipoCompativel($conexao);

        $tabela         = EstruturaBanco::nomeTabela('log');
        $colId          = EstruturaBanco::nomeColuna('log', 'id');
        $colObjeto      = EstruturaBanco::nomeColuna('log', 'id_objeto');
        $colUsuario     = EstruturaBanco::nomeColuna('log', 'id_usuario');
        $colAcao        = EstruturaBanco::nomeColuna('log', 'acao');
        $colMensagem    = EstruturaBanco::nomeColuna('log', 'mensagem');
        $colIp          = EstruturaBanco::nomeColuna('log', 'ip');
        $colDataHora    = EstruturaBanco::nomeColuna('log', 'data_hora');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colId} {$tipos->varchar(36)} PRIMARY KEY,
                    {$colObjeto} {$tipos->varchar(36)},
                    {$colUsuario} {$tipos->varchar(36)},
                    {$colAcao} {$tipos->varchar(255)},
                    {$colMensagem} {$tipos->varchar(255)},
                    {$colIp} {$tipos->varchar(45)},
                    {$colDataHora} {$tipos->bigint()},
                    FOREIGN KEY ({$colObjeto}) REFERENCES " . EstruturaBanco::nomeTabela('objeto') . "(" . EstruturaBanco::nomeColuna('objeto', 'id') . "),
                    FOREIGN KEY ({$colUsuario}) REFERENCES " . EstruturaBanco::nomeTabela('usuario') . "(" . EstruturaBanco::nomeColuna('usuario', 'id') . ")
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
