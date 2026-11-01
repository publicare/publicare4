<?php

declare(strict_types=1);

namespace Pbl\Acesso;

/** O que alguém pode querer fazer. Um identificador fixo no código, nunca texto vindo da requisição (ADR 0011). */
enum Acao: string
{
    case Ver = 'ver';
    case Criar = 'criar';
    case Editar = 'editar';
    case Publicar = 'publicar';
    case Apagar = 'apagar';
    case Administrar = 'administrar';
}
