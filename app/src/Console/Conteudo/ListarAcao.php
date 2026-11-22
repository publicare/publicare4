<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;

use Pbl\Acesso\Acao;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\Servico\EntradaInvalida;

/** Ação de leitura do `conteudo`: sempre pelo serviço (a conta só enxerga o que o painel mostraria). */
final class ListarAcao implements AcaoDeConteudo
{
    public const ESTADOS = ['privado', 'submetido', 'rejeitado', 'publicado', 'apagado'];
    public const LIMITE_PADRAO = 50;
    public const LIMITE_MAXIMO = 200;

    public function nome(): string
    {
        return 'listar';
    }

    public function executar(Contexto $c): Resposta
    {
        $estado = $c->opcao('estado');
        if ($estado !== null && !in_array($estado, self::ESTADOS, true)) {
            throw new EntradaInvalida(['estado' => 'Use um de: ' . implode(', ', self::ESTADOS) . '.']);
        }
        $limite = $c->opcao('limite') === null ? self::LIMITE_PADRAO : $c->inteiro('limite', (string) $c->opcao('limite'));
        $limite = min($limite, self::LIMITE_MAXIMO);
        $resolvedor = new ResolvedorDeObjeto($c->s->conteudo);
        $pai = $c->opcao('pai') === null ? null : $resolvedor->resolver($c->quem, $c->site, $c->opcao('pai'));
        $arvore = $c->marca('arvore');
        if ($pai === null && $c->site->objetoRaizId !== null && !$arvore) {
            // sem --pai: os filhos da página inicial, se a conta a vê; senão, tudo o que ela vê no site
            $raiz = $c->s->objetos->porId($c->site->objetoRaizId);
            if ($raiz !== null && $c->s->permissoes->pode($c->quem, Acao::Ver, $raiz)) {
                $pai = $raiz;
            }
        }
        $r = $c->s->conteudo->listar($c->quem, $c->site, $pai, $arvore || $pai === null, $estado, $c->opcao('tipo'), $c->opcao('busca'), $limite);
        $itens = array_map(static fn (Objeto $o): array => $c->resumo($o) + ['pai_id' => $o->paiId], $r['itens']);
        $texto = $itens === [] ? 'Nenhum conteúdo encontrado.' : implode("\n", array_map(static fn (Objeto $o): string => $c->linha($o), $r['itens']));
        if ($r['ha_mais']) {
            $texto .= "\n(há mais resultados; refine ou aumente --limite)";
        }

        return new Resposta(['itens' => $itens, 'ha_mais' => $r['ha_mais']], $texto);
    }
}
