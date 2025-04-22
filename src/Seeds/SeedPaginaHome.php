<?php

namespace Seeds;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\Ferramentas;

class SeedPaginaHome
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema = $conexao->createSchemaManager();

        $tabelaObjeto = EstruturaBanco::nomeTabela('objeto');
        $colId = EstruturaBanco::nomeColuna('objeto', 'id');
        $colTitulo = EstruturaBanco::nomeColuna('objeto', 'titulo');
        $colUrlAmigavel = EstruturaBanco::nomeColuna('objeto', 'url_amigavel');

        // Verifica se a página home já existe
        $sqlVerifica = "SELECT COUNT(*) FROM {$tabelaObjeto} WHERE {$colUrlAmigavel} = :url";
        $existe = $conexao->fetchOne($sqlVerifica, ['url' => 'home']);

        if ($existe == 0) {
            $sql = "INSERT INTO {$tabelaObjeto} ({$colId}, {$colTitulo}, {$colUrlAmigavel}) VALUES (:id, :titulo, :url)";
            $conexao->executeStatement($sql, [
                'id' => Ferramentas::gerarUuid(),
                'titulo' => 'Página Inicial',
                'url' => 'home'
            ]);
        }
    }
}
