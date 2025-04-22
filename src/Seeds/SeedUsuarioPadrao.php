<?php

namespace Seeds;

use Configuracao\ConexaoBanco;
use Configuracao\EstruturaBanco;
use Configuracao\Ferramentas;

class SeedUsuarioPadrao
{
    public static function executar(): void
    {
        $conexao = ConexaoBanco::conectar();
        $schema = $conexao->createSchemaManager();

        $tabelaUsuario = EstruturaBanco::nomeTabela('usuario');
        $colId = EstruturaBanco::nomeColuna('usuario', 'id');
        $colLogin = EstruturaBanco::nomeColuna('usuario', 'login');
        $colSenha = EstruturaBanco::nomeColuna('usuario', 'senha');
        $colNome = EstruturaBanco::nomeColuna('usuario', 'nome');

        // Verifica se o usuário já existe
        $sqlVerifica = "SELECT COUNT(*) FROM {$tabelaUsuario} WHERE {$colLogin} = :login";
        $existe = $conexao->fetchOne($sqlVerifica, ['login' => 'admin']);

        if ($existe == 0) {
            $sql = "INSERT INTO {$tabelaUsuario} ({$colId}, {$colLogin}, {$colSenha}, {$colNome}) VALUES (:id, :login, :senha, :nome)";
            $conexao->executeStatement($sql, [
                'id' => Ferramentas::gerarUuid(),
                'login' => 'admin',
                'senha' => password_hash('admin', PASSWORD_DEFAULT),
                'nome' => 'Administrador'
            ]);
        }
    }
}
