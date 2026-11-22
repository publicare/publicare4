<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;


/** Ação `retirar` de `bin/pbl conteudo`: delega ao `ServicoDeConteudo`. */
final class RetirarAcao implements AcaoDeConteudo
{
    public function nome(): string
    {
        return 'retirar';
    }

    public function executar(Contexto $c): Resposta
    {
        $o = $c->objeto();

        return $c->gravada($c->s->conteudo->retirar($c->quem, $o->id, $c->versao()), 'retirado da revisão');
    }
}
