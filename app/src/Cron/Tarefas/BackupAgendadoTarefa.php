<?php

declare(strict_types=1);

namespace Pbl\Cron\Tarefas;

use Pbl\Backup\AgendaDeBackup;
use Pbl\Backup\CatalogoDeBackups;
use Pbl\Backup\ConfigDeBackup;
use Pbl\Backup\CriadorDeBackup;
use Pbl\Backup\EstadoDoBackup;
use Pbl\Backup\ErroDeBackup;
use Pbl\Backup\RetencaoDeBackups;
use Pbl\Cron\ComProgramacao;
use Pbl\Cron\Resultado;
use Pbl\Cron\Tarefa;
use Pbl\Servicos;

/**
 * Cópia de segurança agendada (ADR 0029): "tem trabalho" só se `backup.json` está ativo, o horário (no fuso do site) venceu há menos de
 * 6 h e ainda não foi atendido. Usa a trava de atualizar/reverter; se ocupada, tenta de novo no minuto seguinte. Depois aplica a retenção.
 */
final class BackupAgendadoTarefa implements Tarefa, ComProgramacao
{
    public function __construct(private readonly ?\Closure $relogio = null)
    {
    }

    public function id(): string
    {
        return 'backup';
    }

    public function nome(): string
    {
        return 'Cópia de segurança agendada';
    }

    public function descricao(): string
    {
        return 'Cria a cópia de segurança (banco e dados) no horário configurado em "Cópias de segurança" e apaga as mais antigas conforme a retenção. Desativada até o administrador ligar.';
    }

    public function intervalo(): int
    {
        return 60;
    }

    public function leve(): bool
    {
        return false;
    }

    public function temTrabalho(Servicos $servicos): bool
    {
        $config = ConfigDeBackup::carregar($servicos->caminhos);

        return $config->ativo && (new AgendaDeBackup($config))->vencido($this->agora(), EstadoDoBackup::ler($servicos->caminhos)['ultimo_horario']);
    }

    public function executar(Servicos $servicos, float $prazo): Resultado
    {
        $caminhos = $servicos->caminhos;
        $config = ConfigDeBackup::carregar($caminhos);
        $agora = $this->agora();
        $horario = (new AgendaDeBackup($config))->ultimoHorario($agora) ?? $agora;
        try {
            $info = (new CriadorDeBackup($caminhos, $config, relogio: $this->relogio))->criar('agendada');
        } catch (ErroDeBackup $e) {
            if ($e->ocupado) {
                return new Resultado('Outra operação em andamento; tenta de novo no próximo minuto.', true);
            }
            EstadoDoBackup::gravar($caminhos, $horario, $agora, false, $e->getMessage());
            throw $e;
        }
        $catalogo = new CatalogoDeBackups($caminhos, $config);
        $apagadas = RetencaoDeBackups::aplicar($catalogo, (new RetencaoDeBackups($config->reterUltimas, $config->reterDias))->planejar($catalogo->listar(), $agora));
        EstadoDoBackup::gravar($caminhos, $horario, $agora, true, "Cópia {$info->arquivo} criada.");

        return new Resultado("Cópia {$info->arquivo} criada" . ($apagadas > 0 ? "; {$apagadas} antiga(s) apagada(s)." : '.'));
    }

    public function frequenciaTexto(Servicos $servicos): string
    {
        $config = ConfigDeBackup::carregar($servicos->caminhos);

        return $config->ativo ? (new AgendaDeBackup($config))->texto() : 'desativada';
    }

    public function proximaExecucao(Servicos $servicos): int
    {
        return (new AgendaDeBackup(ConfigDeBackup::carregar($servicos->caminhos)))->proximoHorario($this->agora());
    }

    private function agora(): int
    {
        return $this->relogio === null ? time() : ($this->relogio)();
    }
}
