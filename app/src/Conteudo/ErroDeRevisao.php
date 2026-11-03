<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

/** Um passo do fluxo de revisão que não pode acontecer. `$codigo` é fixo (a tela escolhe o texto); a mensagem é para registro e testes. */
final class ErroDeRevisao extends \RuntimeException
{
    public const NEGADO = 'negado';
    public const ESTADO = 'estado';
    public const MOTIVO = 'motivo';
    public const CONFLITO = 'conflito';

    public function __construct(public readonly string $codigo, string $mensagem)
    {
        parent::__construct($mensagem);
    }
}
