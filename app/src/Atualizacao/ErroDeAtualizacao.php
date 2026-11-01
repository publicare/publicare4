<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

/** Algo impediu a verificação ou a aplicação de uma atualização. A mensagem é para a pessoa que opera o site. */
final class ErroDeAtualizacao extends \RuntimeException
{
}
