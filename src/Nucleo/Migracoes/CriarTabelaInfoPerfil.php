<?php

namespace Nucleo\Migracoes;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\TipoCompativel;

class CriarTabelaInfoPerfil
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema  = $conexao->createSchemaManager();
        $tipos   = new TipoCompativel($conexao);

        $tabela           = EstruturaBanco::nomeTabela('infoperfil');
        $colId            = EstruturaBanco::nomeColuna('infoperfil', 'id');
        $colPerfil        = EstruturaBanco::nomeColuna('infoperfil', 'id_perfil');
        $colPropriedade   = EstruturaBanco::nomeColuna('infoperfil', 'id_propriedade');
        $colAcao          = EstruturaBanco::nomeColuna('infoperfil', 'acao');
        $colScript        = EstruturaBanco::nomeColuna('infoperfil', 'script');
        $colDonoPub       = EstruturaBanco::nomeColuna('infoperfil', 'dono_ou_publicado');
        $colSoPublicado   = EstruturaBanco::nomeColuna('infoperfil', 'so_publicado');
        $colSeDono        = EstruturaBanco::nomeColuna('infoperfil', 'se_dono');
        $colNaoMenu       = EstruturaBanco::nomeColuna('infoperfil', 'nao_menu');
        $colOrdem         = EstruturaBanco::nomeColuna('infoperfil', 'ordem');
        $colIcone         = EstruturaBanco::nomeColuna('infoperfil', 'icone');

        if (!$schema->tablesExist([$tabela])) {
            $sql = "
                CREATE TABLE {$tabela} (
                    {$colId} {$tipos->varchar(36)} PRIMARY KEY,
                    {$colPerfil} {$tipos->varchar(36)} NOT NULL,
                    {$colPropriedade} {$tipos->varchar(36)} NOT NULL,
                    {$colAcao} {$tipos->varchar(255)},
                    {$colScript} {$tipos->varchar(255)},
                    {$colDonoPub} {$tipos->booleano()} DEFAULT 0,
                    {$colSoPublicado} {$tipos->booleano()} DEFAULT 0,
                    {$colSeDono} {$tipos->booleano()} DEFAULT 0,
                    {$colNaoMenu} {$tipos->booleano()} DEFAULT 0,
                    {$colOrdem} {$tipos->inteiro()} DEFAULT 0,
                    {$colIcone} {$tipos->varchar(50)},
                    FOREIGN KEY ({$colPerfil}) REFERENCES " . EstruturaBanco::nomeTabela('perfil') . "(" . EstruturaBanco::nomeColuna('perfil', 'id') . "),
                    FOREIGN KEY ({$colPropriedade}) REFERENCES " . EstruturaBanco::nomeTabela('propriedade') . "(" . EstruturaBanco::nomeColuna('propriedade', 'id') . ")
                )
            ";
            $conexao->executeStatement($sql);
        }
    }
}
