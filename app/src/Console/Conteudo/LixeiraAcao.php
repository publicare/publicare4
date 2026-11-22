<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;


/** Ação `lixeira` de `bin/pbl conteudo`: delega ao `ServicoDeConteudo`. */
final class LixeiraAcao implements AcaoDeConteudo
{
    public function nome(): string
    {
        return 'lixeira';
    }

    public function executar(Contexto $c): Resposta
    {
        $o = $c->objeto();
        $c->exigirConfirmacao('Mandar #' . $o->id . ' para a lixeira tira a página do ar (dá para recuperar pelo painel; o CLI nunca apaga de vez).');

        return $c->gravada($c->s->conteudo->lixeira($c->quem, $o->id, $c->versao()), 'enviado para a lixeira');
    }
}
