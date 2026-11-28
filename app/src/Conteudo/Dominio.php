<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

/**
 * Um endereço (host) que leva a um site. `principal` é o canônico (um por site); `servir` mostra o mesmo conteúdo; `redirecionar` manda
 * para o principal. `inicioObjetoId` (opcional) é o conteúdo que abre em `/` neste endereço; o resto do site segue igual.
 */
final class Dominio
{
    public const PRINCIPAL = 'principal';
    public const SERVIR = 'servir';
    public const REDIRECIONAR = 'redirecionar';
    public const MODOS = [self::PRINCIPAL, self::SERVIR, self::REDIRECIONAR];

    public function __construct(public readonly string $host, public readonly int $siteId, public readonly string $modo, public readonly ?int $inicioObjetoId = null)
    {
    }

    /**
     * Nome de host válido (letras, números e hífen em rótulos de até 63 caracteres, até 253 no total). Sem regex com
     * quantificadores sobrepostos: separa nos pontos e valida cada rótulo com classes disjuntas.
     */
    public static function hostValido(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || $host !== strtolower($host)) {
            return false;
        }
        foreach (explode('.', $host) as $rotulo) {
            if ($rotulo === '' || strlen($rotulo) > 63 || preg_match('/^[a-z0-9]++(?:-++[a-z0-9]++)*+$/D', $rotulo) !== 1) {
                return false;
            }
        }

        return true;
    }
}
