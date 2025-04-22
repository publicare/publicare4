<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaObjeto
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema  = $conexao->createSchemaManager();
        $tipos   = new TipoCompativel($conexao);

        $tabela         = EstruturaBanco::nomeTabela('objeto');
        $colId          = EstruturaBanco::nomeColuna('objeto', 'id');
        $colClasse      = EstruturaBanco::nomeColuna('objeto', 'id_classe');
        $colPai         = EstruturaBanco::nomeColuna('objeto', 'id_pai');
        $colTitulo      = EstruturaBanco::nomeColuna('objeto', 'titulo');
        $colUrl         = EstruturaBanco::nomeColuna('objeto', 'url_amigavel');
        $colVersao      = EstruturaBanco::nomeColuna('objeto', 'versao');
        $colPublicado   = EstruturaBanco::nomeColuna('objeto', 'publicado');
        $colApagado     = EstruturaBanco::nomeColuna('objeto', 'apagado');
        $colPeso        = EstruturaBanco::nomeColuna('objeto', 'peso');
        $colExclusao    = EstruturaBanco::nomeColuna('objeto', 'data_exclusao');
        $colPublicacao  = EstruturaBanco::nomeColuna('objeto', 'data_publicacao');
        $colValidade    = EstruturaBanco::nomeColuna('objeto', 'data_validade');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colId} {$tipos->varchar(36)} PRIMARY KEY,
                    {$colClasse} {$tipos->varchar(36)},
                    {$colPai} {$tipos->varchar(36)},
                    {$colTitulo} {$tipos->varchar(255)},
                    {$colUrl} {$tipos->varchar(255)},
                    {$colVersao} {$tipos->inteiro()} DEFAULT 1,
                    {$colPublicado} {$tipos->booleano()} DEFAULT 0,
                    {$colApagado} {$tipos->booleano()} DEFAULT 0,
                    {$colPeso} {$tipos->inteiro()} DEFAULT 0,
                    {$colExclusao} {$tipos->bigint()},
                    {$colPublicacao} {$tipos->bigint()},
                    {$colValidade} {$tipos->bigint()},
                    FOREIGN KEY ({$colClasse}) REFERENCES " . EstruturaBanco::nomeTabela('classe') . "(" . EstruturaBanco::nomeColuna('classe', 'id') . "),
                    FOREIGN KEY ({$colPai}) REFERENCES {$tabela}({$colId})
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
