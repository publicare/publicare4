<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;

use Pbl\Conteudo\Servico\EntradaInvalida;

/** Ação `republicar`: a republicação em lotes do painel, retomável, dentro de um orçamento de tempo. */
final class RepublicarAcao implements AcaoDeConteudo
{
    public const ORCAMENTO_PADRAO = 25;
    public const ORCAMENTO_MAXIMO = 120;

    public function nome(): string
    {
        return 'republicar';
    }

    public function executar(Contexto $c): Resposta
    {
        $orcamento = $c->opcao('orcamento') === null ? self::ORCAMENTO_PADRAO : $c->inteiro('orcamento', (string) $c->opcao('orcamento'));
        if ($orcamento > self::ORCAMENTO_MAXIMO) {
            throw new EntradaInvalida(['orcamento' => 'O máximo é ' . self::ORCAMENTO_MAXIMO . ' segundos.']);
        }
        $c->exigirConfirmacao('Republicar refaz o menu e todas as páginas publicadas de ' . $c->site->nome . ' (pode demorar; é retomável).');
        $r = $c->s->conteudo->republicar($c->quem, $c->site, (float) $orcamento);
        $e = $r['estado'];
        $dados = [
            'concluido' => $r['concluido'], 'status' => $e['status'] ?? 'nenhuma',
            'feitas' => $e['feitas'] ?? 0, 'total' => $e['total'] ?? 0, 'publicadas' => $e['publicadas'] ?? 0, 'falhas' => $e['falhas'] ?? 0,
        ];
        $texto = $r['concluido']
            ? 'Republicação concluída: ' . $dados['publicadas'] . ' página(s) publicada(s)' . ($dados['falhas'] > 0 ? ', ' . $dados['falhas'] . ' com falha' : '') . '.'
            : 'Republicação em andamento: ' . $dados['feitas'] . ' de ' . $dados['total'] . '. Rode "republicar --confirmar" de novo para continuar.';

        return new Resposta($dados, $texto);
    }
}
