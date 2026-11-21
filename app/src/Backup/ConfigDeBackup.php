<?php

declare(strict_types=1);

namespace Pbl\Backup;

use Pbl\Caminhos;

/**
 * Configuração em `dados/config/backup.json` (ADR 0029). Padrões seguros: desativado, diário às 03:00, guarda as 7 últimas e 30 dias,
 * pasta `dados/backups`. Valor inválido no arquivo cai no padrão (nunca derruba o cron). Gravação atômica, arquivo 0600.
 */
final class ConfigDeBackup
{
    public const PASTA_PADRAO = 'dados/backups';
    public const FUSO_PADRAO = 'America/Sao_Paulo';

    /** @param list<int> $dias dias da semana ISO (1 = segunda … 7 = domingo); vale só na frequência semanal */
    public function __construct(
        public readonly bool $ativo = false,
        public readonly string $frequencia = 'diaria',
        public readonly string $hora = '03:00',
        public readonly array $dias = [1],
        public readonly int $reterUltimas = 7,
        public readonly int $reterDias = 30,
        public readonly string $pasta = self::PASTA_PADRAO,
        public readonly string $fuso = self::FUSO_PADRAO,
    ) {
        if (!in_array($frequencia, ['diaria', 'semanal'], true)) {
            throw new \InvalidArgumentException('Frequência deve ser "diaria" ou "semanal".');
        }
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora) !== 1) {
            throw new \InvalidArgumentException('Hora inválida: use HH:MM.');
        }
        if ($dias === [] || array_filter($dias, static fn (int $d): bool => $d < 1 || $d > 7) !== []) {
            throw new \InvalidArgumentException('Dias da semana devem ir de 1 a 7.');
        }
        if ($reterUltimas < 0 || $reterDias < 0 || $reterUltimas > 1000 || $reterDias > 3650) {
            throw new \InvalidArgumentException('Retenção fora do intervalo permitido.');
        }
        if (!in_array($fuso, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('Fuso horário desconhecido.');
        }
    }

    public static function arquivo(Caminhos $caminhos): string
    {
        return $caminhos->config() . '/backup.json';
    }

    public static function carregar(Caminhos $caminhos): self
    {
        $arquivo = self::arquivo($caminhos);
        $d = is_file($arquivo) && !is_link($arquivo) && filesize($arquivo) <= 65536 ? json_decode((string) file_get_contents($arquivo), true) : null;
        if (!is_array($d)) {
            return new self();
        }
        $padrao = new self();
        $dias = is_array($d['dias'] ?? null) ? array_values(array_unique(array_filter($d['dias'], 'is_int'))) : $padrao->dias;
        try {
            return new self(
                ($d['ativo'] ?? false) === true,
                is_string($d['frequencia'] ?? null) ? $d['frequencia'] : $padrao->frequencia,
                is_string($d['hora'] ?? null) ? $d['hora'] : $padrao->hora,
                $dias,
                is_int($d['reter_ultimas'] ?? null) ? $d['reter_ultimas'] : $padrao->reterUltimas,
                is_int($d['reter_dias'] ?? null) ? $d['reter_dias'] : $padrao->reterDias,
                is_string($d['pasta'] ?? null) && $d['pasta'] !== '' ? $d['pasta'] : $padrao->pasta,
                is_string($d['fuso'] ?? null) ? $d['fuso'] : $padrao->fuso,
            );
        } catch (\InvalidArgumentException) {
            return $padrao; // arquivo editado à mão com valor inválido: volta ao padrão seguro (desativado)
        }
    }

    public function gravar(Caminhos $caminhos): void
    {
        $arquivo = self::arquivo($caminhos);
        if (!is_dir($caminhos->config()) && !@mkdir($caminhos->config(), 0750, true) && !is_dir($caminhos->config())) {
            throw new ErroDeBackup('Não foi possível criar dados/config/.');
        }
        $json = json_encode([
            'ativo' => $this->ativo, 'frequencia' => $this->frequencia, 'hora' => $this->hora, 'dias' => $this->dias,
            'reter_ultimas' => $this->reterUltimas, 'reter_dias' => $this->reterDias, 'pasta' => $this->pasta, 'fuso' => $this->fuso,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $tmp = $arquivo . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            throw new ErroDeBackup('Não foi possível gravar dados/config/backup.json.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $arquivo)) {
            @unlink($tmp);
            throw new ErroDeBackup('Não foi possível gravar dados/config/backup.json.');
        }
    }
}
