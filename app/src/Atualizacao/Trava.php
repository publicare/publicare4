<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

use Pbl\Caminhos;

/**
 * Exclusão mútua entre qualquer origem (linha de comando, painel, cron) e qualquer operação que troque o código ou o banco
 * (atualizar, voltar atrás): `flock` em `dados/atualizacao.lock`, fora de `dados/atualizacao/` (que cada atualização apaga).
 * O sistema solta a trava sozinho se o processo morrer.
 */
final class Trava
{
    /**
     * @return resource
     * @throws ErroDeAtualizacao se outra operação está em andamento ou não há como criar o arquivo
     */
    public static function obter(Caminhos $caminhos)
    {
        $dados = $caminhos->dados();
        if (!is_dir($dados) && !@mkdir($dados, 0750, true) && !is_dir($dados)) {
            throw new ErroDeAtualizacao('Não foi possível criar a pasta dados/.');
        }
        $f = @fopen($dados . '/atualizacao.lock', 'c');
        if ($f === false) {
            throw new ErroDeAtualizacao('Não foi possível criar dados/atualizacao.lock (falta permissão de escrita em dados/).');
        }
        if (!flock($f, LOCK_EX | LOCK_NB)) {
            fclose($f);
            throw new ErroDeAtualizacao('Outra atualização em andamento: espere terminar antes de tentar de novo.');
        }

        return $f;
    }

    /** @param resource $trava */
    public static function soltar($trava): void
    {
        flock($trava, LOCK_UN);
        fclose($trava);
    }
}
