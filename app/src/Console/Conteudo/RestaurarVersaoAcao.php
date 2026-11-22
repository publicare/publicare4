<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;


/** Ação `restaurar-versao` de `bin/pbl conteudo`: delega ao `ServicoDeConteudo`. */
final class RestaurarVersaoAcao implements AcaoDeConteudo
{
    public function nome(): string
    {
        return 'restaurar-versao';
    }

    public function executar(Contexto $c): Resposta
    {
        $o = $c->objeto();
        $alvo = $c->inteiro('para', $c->opcao('para') ?? throw new UsoInvalido('Informe --para=<número da versão a restaurar>.'));
        $c->exigirConfirmacao('Restaurar a versão ' . $alvo . ' de #' . $o->id . ' cria uma versão nova com aquele conteúdo (o estado atual é mantido).');

        return $c->gravada($c->s->conteudo->restaurarVersao($c->quem, $o->id, $alvo, $c->versao()), 'restaurado da versão ' . $alvo);
    }
}
