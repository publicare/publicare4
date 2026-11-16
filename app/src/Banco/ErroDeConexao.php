<?php

declare(strict_types=1);

namespace Pbl\Banco;

/** Falha ao abrir o banco, já traduzida pelo dialeto. Não encadeia a exceção do driver (que pode ecoar usuário e host). */
final class ErroDeConexao extends \RuntimeException
{
}
