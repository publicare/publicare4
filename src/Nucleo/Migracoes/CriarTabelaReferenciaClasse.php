<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaReferenciaClasse
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema = $conexao->createSchemaManager();
        $tipos  = new TipoCompativel($conexao);

        $tabela        = EstruturaBanco::nomeTabela('referencia_classe');
        $colId         = EstruturaBanco::nomeColuna('referencia_classe', 'id');
        $colOrigem     = EstruturaBanco::nomeColuna('referencia_classe', 'id_classe');
        $colDestino    = EstruturaBanco::nomeColuna('referencia_classe', 'id_classe_referencia');
        $colCampoRef   = EstruturaBanco::nomeColuna('referencia_classe', 'campo_ref');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colId} {$tipos->varchar(36)} PRIMARY KEY,
                    {$colOrigem} {$tipos->varchar(36)} NOT NULL,
                    {$colDestino} {$tipos->varchar(36)} NOT NULL,
                    {$colCampoRef} {$tipos->varchar(50)},
                    FOREIGN KEY ({$colOrigem}) REFERENCES " . EstruturaBanco::nomeTabela('classe') . "(" . EstruturaBanco::nomeColuna('classe', 'id') . "),
                    FOREIGN KEY ({$colDestino}) REFERENCES " . EstruturaBanco::nomeTabela('classe') . "(" . EstruturaBanco::nomeColuna('classe', 'id') . ")
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
