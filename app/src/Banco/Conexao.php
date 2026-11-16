<?php

declare(strict_types=1);

namespace Pbl\Banco;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Pbl\Banco\Dialeto\Dialetos;

/** Fábrica de conexões: abre o banco pelo dialeto da configuração (ADR 0028). SQLite é o padrão (ADR 0003). */
final class Conexao
{
    /** @throws ErroDeConexao com mensagem em português, sem senha */
    public static function abrir(ConfigBanco $config): Connection
    {
        $dialeto = Dialetos::porNome($config->driver);
        try {
            $conexao = DriverManager::getConnection($dialeto->opcoesDeConexao($config));
            $dialeto->aposConectar($conexao, $config);
        } catch (ConfigDeBancoInvalida $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Sem `previous`: o texto e o rastro do driver podem conter usuário, host e parâmetros da conexão.
            throw new ErroDeConexao($dialeto->traduzirErro($e));
        }

        return $conexao;
    }

    public static function sqlite(string $arquivo): Connection
    {
        return self::abrir(ConfigBanco::sqlite($arquivo));
    }

    public static function memoria(): Connection
    {
        return self::abrir(ConfigBanco::sqlite(':memory:'));
    }
}
