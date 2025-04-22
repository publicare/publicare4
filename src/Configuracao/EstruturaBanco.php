<?php

namespace Configuracao;

class EstruturaBanco
{
    public static function nomeTabela(string $referencia): string
    {
        return Ambiente::pegar("TABELA_" . strtoupper($referencia), match ($referencia) {
            'objeto' => 'objeto',
            'versao' => 'versao',
            'campo' => 'campo',
            'valor' => 'valor',
            'relacao' => 'relacao',
            default => $referencia
        });
    }

    public static function nomeColuna(string $tabela, string $coluna): string
    {
        return Ambiente::pegar("COLUNA_" . strtoupper($tabela) . "_" . strtoupper($coluna), $coluna);
    }
}
