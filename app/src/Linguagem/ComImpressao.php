<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** Resolvedor que sabe resumir o conjunto de moldes a que pertence (hash da pele): entra na chave do molde compilado. */
interface ComImpressao
{
    public function impressao(): string;
}
