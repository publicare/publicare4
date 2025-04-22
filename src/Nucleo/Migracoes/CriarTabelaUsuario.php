<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaUsuario
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema  = $conexao->createSchemaManager();
        $tipos   = new TipoCompativel($conexao);

        $tabela         = EstruturaBanco::nomeTabela('usuario');
        $colId          = EstruturaBanco::nomeColuna('usuario', 'id');
        $colNome        = EstruturaBanco::nomeColuna('usuario', 'nome');
        $colLogin       = EstruturaBanco::nomeColuna('usuario', 'login');
        $colSenha       = EstruturaBanco::nomeColuna('usuario', 'senha');
        $colEmail       = EstruturaBanco::nomeColuna('usuario', 'email');
        $colRamal       = EstruturaBanco::nomeColuna('usuario', 'ramal');
        $colChefia      = EstruturaBanco::nomeColuna('usuario', 'chefia');
        $colValido      = EstruturaBanco::nomeColuna('usuario', 'valido');
        $colAlterarSenha= EstruturaBanco::nomeColuna('usuario', 'altera_senha');
        $colLdap        = EstruturaBanco::nomeColuna('usuario', 'ldap');
        $colAtualizacao = EstruturaBanco::nomeColuna('usuario', 'data_atualizacao');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colId} {$tipos->varchar(36)} PRIMARY KEY,
                    {$colNome} {$tipos->varchar(255)} NOT NULL,
                    {$colLogin} {$tipos->varchar(100)} NOT NULL UNIQUE,
                    {$colSenha} {$tipos->varchar(255)} NOT NULL,
                    {$colEmail} {$tipos->varchar(255)},
                    {$colRamal} {$tipos->varchar(50)},
                    {$colChefia} {$tipos->booleano()} DEFAULT 0,
                    {$colValido} {$tipos->booleano()} DEFAULT 1,
                    {$colAlterarSenha} {$tipos->booleano()} DEFAULT 0,
                    {$colLdap} {$tipos->booleano()} DEFAULT 0,
                    {$colAtualizacao} {$tipos->bigint()}
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
