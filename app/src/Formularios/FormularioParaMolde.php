<?php

declare(strict_types=1);

namespace Pbl\Formularios;

use Pbl\Acesso\Csrf;

/** Ponte do comando `formulario` dos moldes: devolve o HTML do formulário ou vazio (nada de erro na página pública). */
final class FormularioParaMolde
{
    /** @param \Closure(): Csrf $csrf criado só quando preciso: o segredo do site não é lido por páginas sem formulário */
    public function __construct(private readonly FormularioRepositorio $formularios, private readonly \Closure $csrf)
    {
    }

    public function html(int $siteId, int $id): string
    {
        $f = $this->formularios->porId($siteId, $id);

        return $f === null ? '' : FormularioHtml::renderizar($f, ($this->csrf)()->paraFormulario($f->id));
    }
}
