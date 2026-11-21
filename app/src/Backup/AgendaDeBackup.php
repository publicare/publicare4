<?php

declare(strict_types=1);

namespace Pbl\Backup;

/** Calcula, no fuso do site, o horário agendado mais recente e o próximo (diário ou semanal). Puro: sem disco e sem relógio. */
final class AgendaDeBackup
{
    /** Janela depois da hora marcada em que ainda vale rodar (cron fora do ar não dispara cópias velhas ao voltar). */
    public const JANELA = 6 * 3600;

    public function __construct(private readonly ConfigDeBackup $config)
    {
    }

    /** O horário agendado mais recente que já passou (unix), procurando até 7 dias para trás. */
    public function ultimoHorario(int $agora): ?int
    {
        foreach (range(0, 7) as $volta) {
            $t = $this->horarioDoDia($agora, -$volta);
            if ($t !== null && $t <= $agora) {
                return $t;
            }
        }

        return null;
    }

    public function proximoHorario(int $agora): int
    {
        foreach (range(0, 8) as $frente) {
            $t = $this->horarioDoDia($agora, $frente);
            if ($t !== null && $t > $agora) {
                return $t;
            }
        }

        return $agora + 86400;
    }

    /** Há horário vencido, dentro da janela, e ainda não atendido (`$ultimoAtendido`)? */
    public function vencido(int $agora, int $ultimoAtendido): bool
    {
        $h = $this->ultimoHorario($agora);

        return $h !== null && $h > $ultimoAtendido && $agora - $h <= self::JANELA;
    }

    public function texto(): string
    {
        $nomes = [1 => 'seg', 2 => 'ter', 3 => 'qua', 4 => 'qui', 5 => 'sex', 6 => 'sáb', 7 => 'dom'];
        $quando = $this->config->frequencia === 'diaria'
            ? 'todos os dias'
            : 'às ' . implode(', ', array_map(static fn (int $d): string => $nomes[$d], $this->config->dias));

        return "{$quando}, às {$this->config->hora} ({$this->config->fuso})";
    }

    private function horarioDoDia(int $agora, int $deslocamento): ?int
    {
        $fuso = new \DateTimeZone($this->config->fuso);
        $dia = (new \DateTimeImmutable('@' . $agora))->setTimezone($fuso)->modify(($deslocamento >= 0 ? '+' : '') . $deslocamento . ' day');
        if ($this->config->frequencia === 'semanal' && !in_array((int) $dia->format('N'), $this->config->dias, true)) {
            return null;
        }
        [$h, $mi] = array_map('intval', explode(':', $this->config->hora));

        return $dia->setTime($h, $mi)->getTimestamp();
    }
}
