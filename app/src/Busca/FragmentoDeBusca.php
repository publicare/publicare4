<?php

declare(strict_types=1);

namespace Pbl\Busca;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;

/**
 * O que um driver de busca entrega ao `MotorDeConsulta` para entrar na consulta única: uma condição (`onde`) e uma
 * expressão de pontos (`relevancia`, maior = melhor), cada uma com seus parâmetros. Os parâmetros são separados porque a
 * expressão de pontos só entra no SQL quando se ordena por relevância.
 */
final class FragmentoDeBusca
{
    /**
     * @param array<string, mixed> $valoresOnde
     * @param array<string, ParameterType|ArrayParameterType> $tiposOnde
     * @param array<string, mixed> $valoresRelevancia
     * @param array<string, ParameterType|ArrayParameterType> $tiposRelevancia
     */
    public function __construct(
        public readonly string $onde,
        public readonly array $valoresOnde,
        public readonly array $tiposOnde,
        public readonly string $relevancia = 'COALESCE(0, 0)', // nunca um literal puro: no ORDER BY, número seria posição de coluna
        public readonly array $valoresRelevancia = [],
        public readonly array $tiposRelevancia = [],
    ) {
    }

    /** Consulta sem termo aproveitável (só palavras vazias, pontuação): não acha nada. */
    public static function nada(): self
    {
        return new self('1 = 0', [], []);
    }
}
