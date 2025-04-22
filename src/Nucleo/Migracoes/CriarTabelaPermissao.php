<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaPermissao
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema  = $conexao->createSchemaManager();
        $tipos   = new TipoCompativel($conexao);

        $tabela           = EstruturaBanco::nomeTabela('permissao');
        $colUsuarioId     = EstruturaBanco::nomeColuna('permissao', 'id_usuario');
        $colObjetoId      = EstruturaBanco::nomeColuna('permissao', 'id_objeto');
        $colPerfilId      = EstruturaBanco::nomeColuna('permissao', 'id_perfil');
        $colAtivo         = EstruturaBanco::nomeColuna('permissao', 'ativo');
        $colDataAtrib     = EstruturaBanco::nomeColuna('permissao', 'data_atribuicao');
        $colAtribuidoPor  = EstruturaBanco::nomeColuna('permissao', 'atribuido_por');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colUsuarioId} {$tipos->varchar(36)} NOT NULL,
                    {$colObjetoId}  {$tipos->varchar(36)} NOT NULL,
                    {$colPerfilId}  {$tipos->varchar(36)} NOT NULL,
                    {$colAtivo}     {$tipos->booleano()} DEFAULT 1,
                    {$colDataAtrib} {$tipos->bigint()},
                    {$colAtribuidoPor} {$tipos->varchar(36)},
                    PRIMARY KEY ({$colUsuarioId}, {$colObjetoId}, {$colPerfilId}),
                    FOREIGN KEY ({$colUsuarioId}) REFERENCES " . EstruturaBanco::nomeTabela('usuario') . "(" . EstruturaBanco::nomeColuna('usuario', 'id') . "),
                    FOREIGN KEY ({$colObjetoId}) REFERENCES " . EstruturaBanco::nomeTabela('objeto') . "(" . EstruturaBanco::nomeColuna('objeto', 'id') . "),
                    FOREIGN KEY ({$colPerfilId}) REFERENCES " . EstruturaBanco::nomeTabela('perfil') . "(" . EstruturaBanco::nomeColuna('perfil', 'id') . "),
                    FOREIGN KEY ({$colAtribuidoPor}) REFERENCES " . EstruturaBanco::nomeTabela('usuario') . "(" . EstruturaBanco::nomeColuna('usuario', 'id') . ")
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
