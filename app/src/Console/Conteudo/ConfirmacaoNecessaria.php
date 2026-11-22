<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;

/** Operação destrutiva sem `--confirmar`: nada foi feito; a mensagem descreve o efeito. Saída 6. */
final class ConfirmacaoNecessaria extends \RuntimeException
{
}
