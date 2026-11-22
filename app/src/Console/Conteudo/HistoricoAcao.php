<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;

/** Ação de leitura do `conteudo`: sempre pelo serviço (a conta só enxerga o que o painel mostraria). */
final class HistoricoAcao implements AcaoDeConteudo
{
    public function nome(): string
    {
        return 'historico';
    }

    public function executar(Contexto $c): Resposta
    {
        $o = (new ResolvedorDeObjeto($c->s->conteudo))->resolver($c->quem, $c->site, $c->alvo());
        $itens = [];
        $texto = [];
        foreach ($c->s->conteudo->versoes($c->quem, $o->id) as $v) {
            $autor = $v['usuario_id'] === null ? null : $c->s->usuarios->porId($v['usuario_id']);
            $itens[] = [
                'versao' => $v['versao'], 'titulo' => $v['titulo'], 'estado' => $v['status'], 'motivo' => $v['motivo'], 'quando' => $v['criado_em'],
                'autor' => $autor === null ? null : ['login' => $autor->login, 'agente' => $autor->ehServico()],
            ];
            $texto[] = 'v' . $v['versao'] . ' ' . $v['criado_em'] . ' ' . $v['status'] . ' ' . $v['titulo'] . ' (' . ($autor === null ? 'sistema' : $autor->login . ($autor->ehServico() ? ', agente' : '')) . ', ' . $v['motivo'] . ')';
        }

        return new Resposta(['id' => $o->id, 'versao_atual' => $o->versao, 'versoes' => $itens], implode("\n", $texto));
    }
}
