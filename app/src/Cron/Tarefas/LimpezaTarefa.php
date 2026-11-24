<?php

declare(strict_types=1);

namespace Pbl\Cron\Tarefas;

use Pbl\Cron\Resultado;
use Pbl\Cron\Tarefa;
use Pbl\Servicos;

/**
 * Limpeza do que vence sozinho no banco: sessões da administração expiradas e registros de tentativas de login/envio com mais de
 * um dia. Hoje só são apagados quando alguém entra ou envia; o cron mantém as tabelas enxutas mesmo em site parado. Nenhum
 * conteúdo é apagado aqui (a lixeira de conteúdo é decisão de gente, não do cron).
 */
final class LimpezaTarefa implements Tarefa
{
    private const JANELA_DE_TENTATIVAS = 86400;

    /** @param (\Closure(): int)|null $relogio */
    public function __construct(private readonly ?\Closure $relogio = null)
    {
    }

    public function id(): string
    {
        return 'limpeza';
    }

    public function nome(): string
    {
        return 'Limpeza de sessões e tentativas';
    }

    public function descricao(): string
    {
        return 'Apaga sessões expiradas e registros antigos de tentativas.';
    }

    public function intervalo(): int
    {
        return 3600;
    }

    public function leve(): bool
    {
        return true;
    }

    public function temTrabalho(Servicos $servicos): bool
    {
        $c = $servicos->conexao();
        $agora = $this->agora();

        return (int) $c->fetchOne('SELECT COUNT(*) FROM sessao WHERE expira_em < ?', [$agora]) > 0
            || (int) $c->fetchOne('SELECT COUNT(*) FROM tentativa_login WHERE quando < ?', [$agora - self::JANELA_DE_TENTATIVAS]) > 0
            || (int) $c->fetchOne('SELECT COUNT(*) FROM token_conta WHERE expira_em < ?', [$agora - self::JANELA_DE_TENTATIVAS]) > 0
            || (glob($servicos->caminhos->dados() . '/email/fila/*.json') ?: []) !== [];
    }

    public function executar(Servicos $servicos, float $prazo): Resultado
    {
        $c = $servicos->conexao();
        $agora = $this->agora();
        $sessoes = (int) $c->executeStatement('DELETE FROM sessao WHERE expira_em < ?', [$agora]);
        $tentativas = (int) $c->executeStatement('DELETE FROM tentativa_login WHERE quando < ?', [$agora - self::JANELA_DE_TENTATIVAS]);

        $tokens = $servicos->tokensDeConta($this->relogio)->limparExpirados();
        // Pedidos de "esqueci a senha" gravados na fila (ADR 0036): busca da conta e envio rodam aqui, fora da resposta.
        $pedidos = $servicos->recuperacao($this->relogio)->processarFila();

        return new Resultado("{$sessoes} sessão(ões) expirada(s), {$tentativas} registro(s) de tentativa e {$tokens} token(s) de conta apagados; {$pedidos} pedido(s) de recuperação processado(s)");
    }

    private function agora(): int
    {
        return $this->relogio === null ? time() : ($this->relogio)();
    }
}
