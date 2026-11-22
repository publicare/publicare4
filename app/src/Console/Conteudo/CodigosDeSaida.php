<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;

/** Códigos de saída de `bin/pbl conteudo` (ADR 0033). Fazem parte do contrato: scripts decidem por eles. */
final class CodigosDeSaida
{
    public const SUCESSO = 0;
    public const ERRO = 1;
    public const USO = 2;
    public const NAO_ENCONTRADO = 3;
    public const SEM_PERMISSAO = 4;
    public const CONFLITO = 5;
    public const VALIDACAO = 6;
}
