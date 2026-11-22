<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;

/** A entrada padrão do processo (constante STDIN; nunca o fluxo de entrada de requisições web). */
final class EntradaPadrao implements Entrada
{
    private const BLOCO = 8192;

    public function ler(int $limiteEmBytes): string
    {
        if (!defined('STDIN')) {
            throw new UsoInvalido('Não há entrada padrão disponível neste ambiente.');
        }
        $lido = '';
        while (!feof(STDIN)) {
            $bloco = fread(STDIN, self::BLOCO);
            if ($bloco === false || $bloco === '') {
                break;
            }
            $lido .= $bloco;
            if (strlen($lido) > $limiteEmBytes) {
                throw new EntradaGrandeDemais('A entrada passa de ' . intdiv($limiteEmBytes, 1048576) . ' MiB.');
            }
        }

        return $lido;
    }
}
