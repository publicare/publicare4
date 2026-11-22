<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;


/** Ação `enviar` de `bin/pbl conteudo`: delega ao `ServicoDeConteudo`. */
final class EnviarAcao implements AcaoDeConteudo
{
    public function nome(): string
    {
        return 'enviar';
    }

    public function executar(Contexto $c): Resposta
    {
        $o = $c->objeto();

        return $c->gravada($c->s->conteudo->enviar($c->quem, $o->id, $c->versao()), 'enviado para revisão');
    }
}
