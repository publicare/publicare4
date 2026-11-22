<?php

declare(strict_types=1);

namespace Pbl\Conteudo\Servico;

/** A operação não cabe no estado atual do conteúdo (na lixeira, página inicial, tem filhos, fluxo de revisão). */
final class EstadoInvalido extends \RuntimeException
{
    public const ARVORE = 'conflito_de_arvore';
    public const ESTADO = 'estado_invalido';

    public function __construct(string $mensagem, public readonly string $codigo = self::ESTADO)
    {
        parent::__construct($mensagem);
    }
}
