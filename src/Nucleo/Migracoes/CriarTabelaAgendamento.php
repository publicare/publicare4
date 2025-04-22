<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaAgendamento
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema = $conexao->createSchemaManager();
        $tipos = new TipoCompativel($conexao);

        $tabela = EstruturaBanco::nomeTabela('agendamento');
        $colId = EstruturaBanco::nomeColuna('agendamento', 'id');
        $colObjeto = EstruturaBanco::nomeColuna('agendamento', 'id_objeto');
        $colAcao = EstruturaBanco::nomeColuna('agendamento', 'acao');
        $colDataExec = EstruturaBanco::nomeColuna('agendamento', 'data_execucao');
        $colExecutado = EstruturaBanco::nomeColuna('agendamento', 'executado');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colId} {$tipos->varchar(36)} PRIMARY KEY,
                    {$colObjeto} {$tipos->varchar(36)} NOT NULL,
                    {$colAcao} {$tipos->varchar(255)} NOT NULL,
                    {$colDataExec} {$tipos->bigint()} NOT NULL,
                    {$colExecutado} {$tipos->booleano()} DEFAULT 0,
                    FOREIGN KEY ({$colObjeto}) REFERENCES " . EstruturaBanco::nomeTabela('objeto') . "(" . EstruturaBanco::nomeColuna('objeto', 'id') . ")
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
