<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Pbl\Conteudo\Objeto;

/** Como um objeto aparece para o molde: `#id`, `#titulo`, `#url`, `#classe`, `#site`, `#pai`, `#peso`, `#descricao`, datas de vigência e os campos da classe. */
final class ContextoDeObjeto
{
    /** @return array<string, mixed> */
    public static function de(Objeto $objeto, string $classe, bool $temFilhos = false): array
    {
        // Campos do sistema por último: vencem qualquer propriedade da classe com o mesmo nome (classes antigas).
        return array_merge($objeto->dados, [
            'id' => $objeto->id,
            'titulo' => $objeto->titulo,
            'url' => '/' . $objeto->urlAmigavel,
            'classe' => $classe,
            'site' => $objeto->siteId,
            'pai' => $objeto->paiId ?? '',
            'peso' => $objeto->peso,
            'descricao' => $objeto->descricao,
            'no_menu' => $objeto->noMenu ? 1 : 0,
            'rotulo_menu' => $objeto->rotuloMenu === '' ? $objeto->titulo : $objeto->rotuloMenu,
            'data_publicacao' => $objeto->dataPublicacao ?? '',
            'data_validade' => $objeto->dataValidade ?? '',
            'caminho' => $objeto->caminho,
            'url_amigavel' => $objeto->urlAmigavel,
            'classe_id' => $objeto->classeId,
            'status' => $objeto->status,
            'apagado' => 0,
            'temfilhos' => $temFilhos ? 1 : 0,
        ]);
    }
}
