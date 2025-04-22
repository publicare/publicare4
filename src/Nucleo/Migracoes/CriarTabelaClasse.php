<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaClasse
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema  = $conexao->createSchemaManager();
        $tipos   = new TipoCompativel($conexao);

        $tabela         = EstruturaBanco::nomeTabela('classe');
        $colId          = EstruturaBanco::nomeColuna('classe', 'id');
        $colNome        = EstruturaBanco::nomeColuna('classe', 'nome');
        $colPrefixo     = EstruturaBanco::nomeColuna('classe', 'prefixo');
        $colDescricao   = EstruturaBanco::nomeColuna('classe', 'descricao');
        $colPai         = EstruturaBanco::nomeColuna('classe', 'id_pai');
        $colTemFilhos   = EstruturaBanco::nomeColuna('classe', 'tem_filhos');
        $colSistema     = EstruturaBanco::nomeColuna('classe', 'sistema');
        $colIndexar     = EstruturaBanco::nomeColuna('classe', 'indexar');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colId} {$tipos->varchar(36)} PRIMARY KEY,
                    {$colNome} {$tipos->varchar(50)} NOT NULL,
                    {$colPrefixo} {$tipos->varchar(50)},
                    {$colDescricao} {$tipos->varchar(255)},
                    {$colPai} {$tipos->varchar(36)},
                    {$colTemFilhos} {$tipos->booleano()} DEFAULT 0,
                    {$colSistema} {$tipos->booleano()} DEFAULT 0,
                    {$colIndexar} {$tipos->varchar(45)},
                    FOREIGN KEY ({$colPai}) REFERENCES {$tabela}({$colId})
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
