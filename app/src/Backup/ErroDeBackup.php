<?php

declare(strict_types=1);

namespace Pbl\Backup;

/** Falha esperada de backup/restauração, com mensagem em português segura para a tela (sem caminhos do servidor nem segredos). */
final class ErroDeBackup extends \RuntimeException
{
    public function __construct(string $mensagem, public readonly bool $ocupado = false)
    {
        parent::__construct($mensagem);
    }

    /** Outra operação (atualizar, reverter, outro backup) segura a trava: não é falha, é "tente depois". */
    public static function ocupado(): self
    {
        return new self('Outra operação (atualização, restauração ou outra cópia) está em andamento. Tente de novo em alguns minutos.', true);
    }
}
