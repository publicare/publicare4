<?php

declare(strict_types=1);

namespace Pbl\Conteudo\Servico;

use Pbl\Acesso\Acao;

/** A conta vê o conteúdo, mas não pode fazer esta ação nele. A mensagem nunca contém conteúdo. */
final class AcessoNegado extends \RuntimeException
{
    public function __construct(public readonly Acao $acao, string $mensagem = '')
    {
        parent::__construct($mensagem !== '' ? $mensagem : 'Esta conta não pode ' . $acao->value . ' este conteúdo.');
    }
}
