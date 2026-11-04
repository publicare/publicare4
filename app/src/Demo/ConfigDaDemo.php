<?php

declare(strict_types=1);

namespace Pbl\Demo;

use Pbl\Caminhos;

/**
 * Instalação de demonstração? Só vale com a flag explícita: `"demo": true` em `dados/config/demo.json` ou a variável de ambiente
 * `PBL_DEMO=1`. Fora disso o reinício agendado da demo (ADR 0023) não existe: a tarefa não entra no catálogo e o comando recusa.
 * Ajustes opcionais no mesmo arquivo: `"dias"` (1 a 90; padrão 7) e `"hora"` (`HH:MM` no fuso de Brasília; padrão 04:00).
 * O arquivo fica em `dados/config/`, que o reinício preserva (a flag sobrevive ao reset).
 */
final class ConfigDaDemo
{
    public const FUSO = 'America/Sao_Paulo';
    public const DIAS_PADRAO = 7;
    public const HORA_PADRAO = '04:00';

    public static function ativa(Caminhos $caminhos): bool
    {
        $env = getenv('PBL_DEMO');
        if (is_string($env) && in_array(strtolower(trim($env)), ['1', 'true', 'sim', 'on'], true)) {
            return true;
        }

        return (self::ler($caminhos)['demo'] ?? false) === true;
    }

    public static function dias(Caminhos $caminhos): int
    {
        $d = self::ler($caminhos)['dias'] ?? null;

        return is_int($d) && $d >= 1 && $d <= 90 ? $d : self::DIAS_PADRAO;
    }

    /** @return string `HH:MM` */
    public static function hora(Caminhos $caminhos): string
    {
        $h = self::ler($caminhos)['hora'] ?? null;

        return is_string($h) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $h) === 1 ? $h : self::HORA_PADRAO;
    }

    /** @return array<string, mixed> */
    private static function ler(Caminhos $caminhos): array
    {
        $arquivo = $caminhos->config() . '/demo.json';
        if (!is_file($arquivo) || filesize($arquivo) > 4096) {
            return [];
        }
        $d = json_decode((string) file_get_contents($arquivo), true);

        return is_array($d) ? $d : [];
    }
}
