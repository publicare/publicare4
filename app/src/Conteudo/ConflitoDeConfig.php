<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

/** Alguém salvou as configurações do site depois que você abriu a tela. */
final class ConflitoDeConfig extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('As configurações do site foram alteradas por outra pessoa depois que você abriu a tela.');
    }
}
