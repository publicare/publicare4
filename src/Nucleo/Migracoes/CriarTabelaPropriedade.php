<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaPropriedade
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema  = $conexao->createSchemaManager();
        $tipos   = new TipoCompativel($conexao);

        $tabela          = EstruturaBanco::nomeTabela('propriedade');
        $colId           = EstruturaBanco::nomeColuna('propriedade', 'id');
        $colClasse       = EstruturaBanco::nomeColuna('propriedade', 'id_classe');
        $colTipo         = EstruturaBanco::nomeColuna('propriedade', 'id_tipo_dado');
        $colNome         = EstruturaBanco::nomeColuna('propriedade', 'nome');
        $colDescricao    = EstruturaBanco::nomeColuna('propriedade', 'descricao');
        $colRotulo       = EstruturaBanco::nomeColuna('propriedade', 'rotulo');
        $colRotuloBool1  = EstruturaBanco::nomeColuna('propriedade', 'rotulo_booleano');
        $colRotuloBool0  = EstruturaBanco::nomeColuna('propriedade', 'rotulo_booleano2');
        $colObrigatorio  = EstruturaBanco::nomeColuna('propriedade', 'obrigatorio');
        $colSeguranca    = EstruturaBanco::nomeColuna('propriedade', 'seguranca');
        $colPadrao       = EstruturaBanco::nomeColuna('propriedade', 'valor_padrao');
        $colPosicao      = EstruturaBanco::nomeColuna('propriedade', 'posicao');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colId} {$tipos->varchar(36)} PRIMARY KEY,
                    {$colClasse} {$tipos->varchar(36)},
                    {$colTipo} {$tipos->varchar(36)},
                    {$colNome} {$tipos->varchar(50)} NOT NULL,
                    {$colDescricao} {$tipos->varchar(255)},
                    {$colRotulo} {$tipos->varchar(50)},
                    {$colRotuloBool1} {$tipos->varchar(50)},
                    {$colRotuloBool0} {$tipos->varchar(50)},
                    {$colObrigatorio} {$tipos->booleano()} DEFAULT 0,
                    {$colSeguranca} {$tipos->inteiro()} DEFAULT 0,
                    {$colPadrao} {$tipos->varchar(255)},
                    {$colPosicao} {$tipos->inteiro()} DEFAULT 0,
                    FOREIGN KEY ({$colClasse}) REFERENCES " . EstruturaBanco::nomeTabela('classe') . "(" . EstruturaBanco::nomeColuna('classe', 'id') . "),
                    FOREIGN KEY ({$colTipo}) REFERENCES " . EstruturaBanco::nomeTabela('tipo_dado') . "(" . EstruturaBanco::nomeColuna('tipo_dado', 'id') . ")
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
