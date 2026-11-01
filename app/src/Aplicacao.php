<?php

declare(strict_types=1);

namespace Pbl;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Ponto de entrada HTTP. Por enquanto só responde a raiz; o roteador real vem na fase 0b. */
final class Aplicacao
{
    public function __construct(public readonly Caminhos $caminhos)
    {
    }

    public function tratar(Request $requisicao): Response
    {
        if ($requisicao->getPathInfo() === '/') {
            return new Response('PBL4 ' . Versao::ATUAL, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return new Response('Não encontrado', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
