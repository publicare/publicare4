<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaPerfil
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema  = $conexao->createSchemaManager();
        $tipos   = new TipoCompativel($conexao);

        $tabela         = EstruturaBanco::nomeTabela('perfil');
        $colId          = EstruturaBanco::nomeColuna('perfil', 'id');
        $colNome        = EstruturaBanco::nomeColuna('perfil', 'nome');
        $colDescricao   = EstruturaBanco::nomeColuna('perfil', 'descricao');
        $colSistema     = EstruturaBanco::nomeColuna('perfil', 'sistema');
        $colDataCriacao = EstruturaBanco::nomeColuna('perfil', 'data_criacao');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colId} {$tipos->varchar(36)} PRIMARY KEY,
                    {$colNome} {$tipos->varchar(100)} NOT NULL,
                    {$colDescricao} {$tipos->varchar(255)},
                    {$colSistema} {$tipos->booleano()} DEFAULT 0,
                    {$colDataCriacao} {$tipos->timestamp()} DEFAULT CURRENT_TIMESTAMP
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
