<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

use Pbl\Caminhos;

/**
 * Modo de manutenção durante a troca de código: o site responde 503 com `Retry-After` em vez de rodar meio-código. A marca vence
 * sozinha depois de 30 minutos, para um processo que morreu no meio nunca deixar o site fora do ar para sempre.
 */
final class Manutencao
{
    public const VALIDADE = 1800;

    public static function ligar(Caminhos $caminhos, string $motivo): void
    {
        $arquivo = self::arquivo($caminhos);
        file_put_contents($arquivo, json_encode(['desde' => time(), 'motivo' => $motivo], JSON_THROW_ON_ERROR));
    }

    public static function desligar(Caminhos $caminhos): void
    {
        @unlink(self::arquivo($caminhos));
    }

    public static function ativa(Caminhos $caminhos): bool
    {
        $arquivo = self::arquivo($caminhos);
        if (!is_file($arquivo)) {
            return false;
        }
        $d = json_decode((string) file_get_contents($arquivo), true);

        return is_array($d) && isset($d['desde']) && is_int($d['desde']) && time() - $d['desde'] < self::VALIDADE;
    }

    private static function arquivo(Caminhos $caminhos): string
    {
        return $caminhos->dados() . '/manutencao.json';
    }
}
