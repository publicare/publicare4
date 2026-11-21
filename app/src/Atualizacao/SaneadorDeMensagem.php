<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

/** Deixa uma mensagem de erro em condição de ir para a tela: sem a raiz da instalação, sem caminhos de servidor, sem controle e curta. */
final class SaneadorDeMensagem
{
    public const MAXIMO = 300;

    public static function sanear(string $texto, string $raiz = ''): string
    {
        if ($raiz !== '' && $raiz !== '/') {
            $texto = str_replace(rtrim($raiz, '/'), '…', $texto);
        }
        $texto = (string) preg_replace('#(?<![\w.])/(?:home|var|usr|etc|opt|tmp|srv|mnt|root|run|www|data)(?:/[^\s\'"<>:;,)]*)+#u', '…', mb_scrub($texto, 'UTF-8'));
        $texto = (string) preg_replace('/\e\[[0-9;]*[A-Za-z]|[\x00-\x1F\x7F]+/', ' ', $texto);
        $texto = trim((string) preg_replace('/\s{2,}/', ' ', $texto));

        return mb_substr($texto, 0, self::MAXIMO);
    }
}
