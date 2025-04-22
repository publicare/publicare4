<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaArquivo
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema = $conexao->createSchemaManager();
        $tipos = new TipoCompativel($conexao);

        $tabela         = EstruturaBanco::nomeTabela('arquivo');
        $colId          = EstruturaBanco::nomeColuna('arquivo', 'id');
        $colNome        = EstruturaBanco::nomeColuna('arquivo', 'nome');
        $colCaminho     = EstruturaBanco::nomeColuna('arquivo', 'caminho');
        $colTipo        = EstruturaBanco::nomeColuna('arquivo', 'tipo');
        $colTamanho     = EstruturaBanco::nomeColuna('arquivo', 'tamanho');
        $colDataUpload  = EstruturaBanco::nomeColuna('arquivo', 'data_upload');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colId} {$tipos->varchar(36)} PRIMARY KEY,
                    {$colNome} {$tipos->varchar(255)} NOT NULL,
                    {$colCaminho} {$tipos->varchar(255)} NOT NULL,
                    {$colTipo} {$tipos->varchar(100)},
                    {$colTamanho} {$tipos->bigint()},
                    {$colDataUpload} {$tipos->bigint()}
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
