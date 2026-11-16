<?php

declare(strict_types=1);

namespace Pbl\Banco;

use Pbl\Arquivos\GravacaoAtomica;
use Pbl\Caminhos;

/**
 * Grava `dados/config/banco.json` (0600, atômico). Só entram os campos do usuário de OPERAÇÃO; o usuário de
 * instalação/DDL não tem campo e, por isso, nunca vai a disco.
 */
final class GravadorDeConfigBanco
{
    public static function gravar(Caminhos $caminhos, ConfigBanco $config): void
    {
        $dados = ['driver' => $config->driver];
        if ($config->ehSqlite()) {
            if ($config->nome !== $caminhos->banco()) {
                $dados['nome'] = $config->nome;
            }
        } else {
            $dados += ['host' => $config->host, 'porta' => $config->porta, 'nome' => $config->nome, 'usuario' => $config->usuario, 'senha' => $config->segredo()];
            if ($config->schema !== null) {
                $dados['schema'] = $config->schema;
            }
            $dados += ['tls' => $config->tls, 'modo_simples' => $config->modoSimples];
        }
        GravacaoAtomica::gravar(
            $caminhos->config() . '/banco.json',
            json_encode($dados, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }
}
