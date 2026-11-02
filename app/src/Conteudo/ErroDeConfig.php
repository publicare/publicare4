<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

/** Uma ou mais configurações do site foram recusadas pela validação; nada foi gravado. */
final class ErroDeConfig extends \InvalidArgumentException
{
    /** @param array<string, string> $erros chave => mensagem em português */
    public function __construct(public readonly array $erros)
    {
        parent::__construct(implode(' ', $erros));
    }
}
