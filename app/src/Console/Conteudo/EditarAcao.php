<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;


/** Ação `editar` de `bin/pbl conteudo`: delega ao `ServicoDeConteudo`. */
final class EditarAcao implements AcaoDeConteudo
{
    public function nome(): string
    {
        return 'editar';
    }

    public function executar(Contexto $c): Resposta
    {
        $o = $c->objeto();
        $r = $c->s->conteudo->editar($c->quem, $o->id, $c->versao(), $c->campos(), $c->marca('publicar'), $c->marca('enviar-revisao'));

        return $c->gravada($r, 'gravado' . ($c->marca('publicar') ? ' e publicado' : ($c->marca('enviar-revisao') ? ' e enviado para revisão' : '')));
    }
}
