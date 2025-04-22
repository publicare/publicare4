<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaTipoDado
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema  = $conexao->createSchemaManager();
        $tipos   = new TipoCompativel($conexao);

        $tabela         = EstruturaBanco::nomeTabela('tipo_dado');
        $colId          = EstruturaBanco::nomeColuna('tipo_dado', 'id');
        $colNome        = EstruturaBanco::nomeColuna('tipo_dado', 'nome');
        $colTabela      = EstruturaBanco::nomeColuna('tipo_dado', 'tabela');
        $colDelimitador = EstruturaBanco::nomeColuna('tipo_dado', 'delimitador');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colId} {$tipos->varchar(36)} PRIMARY KEY,
                    {$colNome} {$tipos->varchar(50)} NOT NULL,
                    {$colTabela} {$tipos->varchar(50)} NOT NULL,
                    {$colDelimitador} {$tipos->varchar(1)}
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
