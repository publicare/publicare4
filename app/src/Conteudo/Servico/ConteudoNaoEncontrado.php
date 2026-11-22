<?php

declare(strict_types=1);

namespace Pbl\Conteudo\Servico;

/** O conteúdo não existe **ou** a conta não pode vê-lo: a mesma resposta nos dois casos, para não revelar ids alheios. */
final class ConteudoNaoEncontrado extends \RuntimeException
{
    public function __construct(string $mensagem = 'Conteúdo não encontrado.')
    {
        parent::__construct($mensagem);
    }
}
