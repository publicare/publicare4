<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;


/** Ação `mover` de `bin/pbl conteudo`: delega ao `ServicoDeConteudo`. */
final class MoverAcao implements AcaoDeConteudo
{
    public function nome(): string
    {
        return 'mover';
    }

    public function executar(Contexto $c): Resposta
    {
        $o = $c->objeto();
        $para = (new ResolvedorDeObjeto($c->s->conteudo))->resolver($c->quem, $c->site, $c->opcao('para') ?? throw new UsoInvalido('Informe --para=<id|caminho> (o novo pai).'));
        $c->exigirConfirmacao('Mover #' . $o->id . ' para dentro de #' . $para->id . ' muda o endereço público dele e dos filhos (o antigo redireciona).');

        return $c->gravada($c->s->conteudo->mover($c->quem, $o->id, $c->versao(), $para->id), 'movido para #' . $para->id);
    }
}
