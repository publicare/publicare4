<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;


/** Ação `publicar` de `bin/pbl conteudo`: delega ao `ServicoDeConteudo`. */
final class PublicarAcao implements AcaoDeConteudo
{
    public function nome(): string
    {
        return 'publicar';
    }

    public function executar(Contexto $c): Resposta
    {
        $o = $c->objeto();

        return $c->gravada($c->s->conteudo->publicar($c->quem, $o->id, $c->versao()), 'publicado');
    }
}
