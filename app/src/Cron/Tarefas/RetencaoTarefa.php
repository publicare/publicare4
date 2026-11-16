<?php

declare(strict_types=1);

namespace Pbl\Cron\Tarefas;

use Pbl\Cron\Resultado;
use Pbl\Cron\Tarefa;
use Pbl\Privacidade\PoliticaDeRetencao;
use Pbl\Servicos;

/** Retenção de dado pessoal na auditoria (LGPD): zera o `ip_hash` e, se configurado, apaga linhas antigas. Ver {@see PoliticaDeRetencao}. */
final class RetencaoTarefa implements Tarefa
{
    /** @param (\Closure(): int)|null $relogio */
    public function __construct(private readonly ?\Closure $relogio = null)
    {
    }

    public function id(): string
    {
        return 'retencao';
    }

    public function nome(): string
    {
        return 'Retenção de dados pessoais';
    }

    public function descricao(): string
    {
        return 'Zera o IP (guardado só como hash) das linhas antigas de auditoria e, se configurado, apaga as linhas mais antigas que o prazo (dados/config/privacidade.json).';
    }

    public function intervalo(): int
    {
        return 86400;
    }

    public function leve(): bool
    {
        return true;
    }

    public function temTrabalho(Servicos $servicos): bool
    {
        $p = PoliticaDeRetencao::dosCaminhos($servicos->caminhos);
        $c = $servicos->conexao();
        if ($p->auditoriaIpDias > 0 && (int) $c->fetchOne('SELECT COUNT(*) FROM auditoria WHERE ip_hash IS NOT NULL AND quando < ?', [$this->limite($p->auditoriaIpDias)]) > 0) {
            return true;
        }

        return $p->auditoriaDias > 0 && (int) $c->fetchOne('SELECT COUNT(*) FROM auditoria WHERE quando < ?', [$this->limite($p->auditoriaDias)]) > 0;
    }

    public function executar(Servicos $servicos, float $prazo): Resultado
    {
        $p = PoliticaDeRetencao::dosCaminhos($servicos->caminhos);
        $c = $servicos->conexao();
        $ips = 0;
        $linhas = 0;
        if ($p->auditoriaIpDias > 0) {
            $ips = (int) $c->executeStatement('UPDATE auditoria SET ip_hash = NULL WHERE ip_hash IS NOT NULL AND quando < ?', [$this->limite($p->auditoriaIpDias)]);
        }
        if ($p->auditoriaDias > 0) {
            $linhas = (int) $c->executeStatement('DELETE FROM auditoria WHERE quando < ?', [$this->limite($p->auditoriaDias)]);
        }

        return new Resultado("{$ips} IP(s) de auditoria zerado(s) e {$linhas} linha(s) de auditoria apagada(s)");
    }

    /** Instante limite no mesmo formato de `Auditoria` (`gmdate('c')`), que ordena como texto. */
    private function limite(int $dias): string
    {
        $agora = $this->relogio === null ? time() : ($this->relogio)();

        return gmdate('c', $agora - $dias * 86400);
    }
}
