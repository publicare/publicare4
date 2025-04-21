<?php

namespace Configuracao;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;

class ConexaoBanco
{
    public static function conectar(): \Doctrine\DBAL\Connection
    {
        $tipo = Ambiente::pegar('TIPO_BANCO', 'mysql');
        $config = [
            'dbname'   => Ambiente::pegar('BANCO', 'publicare'),
            'user'     => Ambiente::pegar('USUARIO', 'root'),
            'password' => Ambiente::pegar('SENHA', 'root'),
            'host'     => Ambiente::pegar('SERVIDOR', 'localhost'),
            'port'     => Ambiente::pegar('PORTA', '3306'),
            'driver'   => match ($tipo) {
                'pgsql'    => 'pdo_pgsql',
                'sqlite'   => 'pdo_sqlite',
                default    => 'pdo_mysql',
            },
        ];

        return DriverManager::getConnection($config);
    }
}
