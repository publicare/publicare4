<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Pbl\Conteudo\Objeto;

/** Como um objeto aparece para o molde: `#id`, `#titulo`, `#url`, `#classe` e os campos da classe. */
final class ContextoDeObjeto
{
    /** @return array<string, mixed> */
    public static function de(Objeto $objeto, string $classe): array
    {
        return $objeto->dados + [
            'id' => $objeto->id,
            'titulo' => $objeto->titulo,
            'url' => '/' . $objeto->urlAmigavel,
            'classe' => $classe,
        ];
    }
}
