<?php

declare(strict_types=1);

namespace Pbl\Banco;

use Doctrine\DBAL\Connection;

interface Migracao
{
    /** Número sequencial; a ordem de aplicação. */
    public function versao(): int;

    public function aplicar(Connection $conexao): void;
}
