<?php

declare(strict_types=1);

namespace Pbl\Backup;

use Pbl\Caminhos;

/** Estado da rotina agendada em `dados/backup/estado.json` (último horário atendido e resultado). Sem segredos. */
final class EstadoDoBackup
{
    /** @return array{ultimo_horario: int, em: int, ok: bool, mensagem: string} */
    public static function ler(Caminhos $caminhos): array
    {
        $arquivo = $caminhos->dados() . '/backup/estado.json';
        $d = is_file($arquivo) && !is_link($arquivo) && filesize($arquivo) < 65536 ? json_decode((string) file_get_contents($arquivo), true) : null;
        $d = is_array($d) ? $d : [];

        return [
            'ultimo_horario' => is_int($d['ultimo_horario'] ?? null) ? $d['ultimo_horario'] : 0,
            'em' => is_int($d['em'] ?? null) ? $d['em'] : 0,
            'ok' => ($d['ok'] ?? false) === true,
            'mensagem' => is_string($d['mensagem'] ?? null) ? $d['mensagem'] : '',
        ];
    }

    public static function gravar(Caminhos $caminhos, int $ultimoHorario, int $em, bool $ok, string $mensagem): void
    {
        $pasta = $caminhos->dados() . '/backup';
        if (!is_dir($pasta) && !@mkdir($pasta, 0750, true) && !is_dir($pasta)) {
            return;
        }
        $tmp = $pasta . '/estado.json.tmp';
        $json = json_encode(['ultimo_horario' => $ultimoHorario, 'em' => $em, 'ok' => $ok, 'mensagem' => mb_substr($mensagem, 0, 300)], JSON_THROW_ON_ERROR);
        if (@file_put_contents($tmp, $json) !== false) {
            @rename($tmp, $pasta . '/estado.json');
        }
    }
}
