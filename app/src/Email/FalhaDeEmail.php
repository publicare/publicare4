<?php

declare(strict_types=1);

namespace Pbl\Email;

/** Falha de envio com categoria estável (para a tela dizer o que houve sem revelar resposta do servidor nem segredo). */
final class FalhaDeEmail extends \RuntimeException
{
    public const REDE = 'rede';
    public const TLS = 'tls';
    public const AUTENTICACAO = 'autenticacao';
    public const RECUSADO = 'recusado';
    public const CONFIGURACAO = 'configuracao';

    public function __construct(string $mensagem, public readonly string $categoria)
    {
        parent::__construct($mensagem);
    }
}
