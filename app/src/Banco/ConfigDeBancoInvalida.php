<?php

declare(strict_types=1);

namespace Pbl\Banco;

/** Configuração de banco inválida. A mensagem nomeia o campo e nunca traz a senha. */
final class ConfigDeBancoInvalida extends \InvalidArgumentException
{
}
