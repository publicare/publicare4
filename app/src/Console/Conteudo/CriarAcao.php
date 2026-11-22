<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;


/** Ação `criar` de `bin/pbl conteudo`: delega ao `ServicoDeConteudo`. */
final class CriarAcao implements AcaoDeConteudo
{
    public function nome(): string
    {
        return 'criar';
    }

    public function executar(Contexto $c): Resposta
    {
        $paiRef = $c->opcao('pai') ?? throw new UsoInvalido('Informe --pai=<id|caminho>.');
        $tipo = $c->opcao('tipo') ?? throw new UsoInvalido('Informe --tipo=<classe>.');
        $pai = (new ResolvedorDeObjeto($c->s->conteudo))->resolver($c->quem, $c->site, $paiRef);
        $r = $c->s->conteudo->criar($c->quem, $pai->id, $tipo, $c->campos());

        return $c->gravada($r, 'criado (privado) dentro de #' . $pai->id);
    }
}
