<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;


/** Ação `despublicar` de `bin/pbl conteudo`: delega ao `ServicoDeConteudo`. */
final class DespublicarAcao implements AcaoDeConteudo
{
    public function nome(): string
    {
        return 'despublicar';
    }

    public function executar(Contexto $c): Resposta
    {
        $o = $c->objeto();

        return $c->gravada($c->s->conteudo->despublicar($c->quem, $o->id, $c->versao()), 'tirado do ar');
    }
}
