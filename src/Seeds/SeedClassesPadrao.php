<?php

namespace Seeds;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\Ferramentas;

class SeedClassesPadrao
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema = $conexao->createSchemaManager();

        $tabelaClasse = EstruturaBanco::nomeTabela('classe');
        $colId = EstruturaBanco::nomeColuna('classe', 'id');
        $colNome = EstruturaBanco::nomeColuna('classe', 'nome');

        $classes = ['Página', 'Notícia', 'Galeria'];

        foreach ($classes as $nomeClasse) {
            // Verifica se a classe já existe
            $sqlVerifica = "SELECT COUNT(*) FROM {$tabelaClasse} WHERE {$colNome} = :nome";
            $existe = $conexao->fetchOne($sqlVerifica, ['nome' => $nomeClasse]);

            if ($existe == 0) {
                $sql = "INSERT INTO {$tabelaClasse} ({$colId}, {$colNome}) VALUES (:id, :nome)";
                $conexao->executeStatement($sql, [
                    'id' => Ferramentas::gerarUuid(),
                    'nome' => $nomeClasse
                ]);
            }
        }
    }
}
