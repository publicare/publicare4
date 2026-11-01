<?php

declare(strict_types=1);

namespace Pbl\Consulta;

/**
 * Uma condição da consulta: `campo op valor`. O campo é uma coluna do objeto (`titulo`, `peso`, datas), uma propriedade
 * indexável da classe (`local`) ou, para seguir uma referência, `ref.campo` (`autor.cargo`).
 */
final class Filtro
{
    public const OPERADORES = ['=', '!=', '<', '>', '<=', '>=', 'contem'];

    public function __construct(
        public readonly string $campo,
        public readonly string $operador,
        public readonly string $valor,
    ) {
        if (!in_array($operador, self::OPERADORES, true)) {
            throw new \InvalidArgumentException("Operador inválido: {$operador}");
        }
    }
}
