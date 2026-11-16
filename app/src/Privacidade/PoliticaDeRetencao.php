<?php

declare(strict_types=1);

namespace Pbl\Privacidade;

use Pbl\Caminhos;

/**
 * Prazos de retenção de dado pessoal (LGPD), de `dados/config/privacidade.json`:
 * `{"auditoria_ip_dias": 90, "auditoria_dias": 0}`. Prazo `0` (ou ausente, no caso da auditoria inteira) = não apagar.
 *
 * - `auditoria_ip_dias` (padrão 90): depois disso, o `ip_hash` da linha de auditoria é zerado; a linha continua (a trilha de quem
 *   fez o quê não se perde).
 * - `auditoria_dias` (padrão 0, nunca): apaga a linha de auditoria inteira. Ligue só se a política da instituição exigir.
 *
 * Sessões vencem sozinhas e o contador de tentativas de login guarda só HMAC por 24 h (`LimpezaTarefa`).
 */
final class PoliticaDeRetencao
{
    public const AUDITORIA_IP_PADRAO = 90;

    public function __construct(public readonly int $auditoriaIpDias = self::AUDITORIA_IP_PADRAO, public readonly int $auditoriaDias = 0)
    {
    }

    public static function dosCaminhos(Caminhos $caminhos): self
    {
        $arquivo = $caminhos->config() . '/privacidade.json';
        $dados = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;
        if (!is_array($dados)) {
            return new self();
        }

        return new self(self::dias($dados['auditoria_ip_dias'] ?? null, self::AUDITORIA_IP_PADRAO), self::dias($dados['auditoria_dias'] ?? null, 0));
    }

    private static function dias(mixed $valor, int $padrao): int
    {
        return is_int($valor) && $valor >= 0 && $valor <= 36500 ? $valor : $padrao;
    }
}
