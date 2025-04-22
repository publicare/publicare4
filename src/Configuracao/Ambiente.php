<?php

namespace Configuracao;

class Ambiente
{
    public static function pegar(string $chave, ?string $padrao = null): ?string
    {
        $valor = getenv($chave);
        if ($valor === false) {
            $arquivoEnv = __DIR__ . '/../../.env';
            if (file_exists($arquivoEnv)) {
                foreach (file($arquivoEnv) as $linha) {
                    if (str_starts_with(trim($linha), "$chave=")) {
                        return trim(explode('=', $linha, 2)[1]);
                    }
                }
            }
            return $padrao;
        }
        return $valor;
    }
}
