<?php

declare(strict_types=1);

namespace Pbl\Acesso;

use Doctrine\DBAL\Connection;

/**
 * Limite de envios de formulários públicos por IP **e por formulário**, no servidor (ADR 0013). Cada POST que chega conta, dê
 * certo ou não: o que se limita é a taxa. Reaproveita a tabela `tentativa_login`; a chave é um HMAC com prefixo próprio
 * (`envio|`), então não se mistura com o limite de login e o IP nunca é guardado em claro.
 */
final class LimiteDeEnvios
{
    public const JANELA = 900;
    public const MAXIMO = 10;

    /** @param (\Closure(): int)|null $relogio */
    public function __construct(private readonly Connection $conexao, private readonly string $segredo, private readonly ?\Closure $relogio = null)
    {
    }

    /** @return int|null segundos até poder enviar de novo, ou null se pode */
    public function bloqueadoPor(int $formularioId, string $ip): ?int
    {
        $agora = $this->agora();
        $quando = array_map('intval', $this->conexao->fetchFirstColumn(
            'SELECT quando FROM tentativa_login WHERE chave_hash = ? AND quando > ? ORDER BY quando ASC',
            [$this->chave($formularioId, $ip), $agora - self::JANELA],
        ));
        if (count($quando) < self::MAXIMO) {
            return null;
        }

        return max(1, $quando[count($quando) - self::MAXIMO] + self::JANELA - $agora);
    }

    public function registrar(int $formularioId, string $ip): void
    {
        $agora = $this->agora();
        $this->conexao->executeStatement('DELETE FROM tentativa_login WHERE quando < ?', [$agora - 86400]);
        $this->conexao->insert('tentativa_login', ['chave_hash' => $this->chave($formularioId, $ip), 'quando' => $agora]);
    }

    public function hashDoIp(string $ip): string
    {
        return hash_hmac('sha256', 'ip|' . $ip, $this->segredo);
    }

    private function chave(int $formularioId, string $ip): string
    {
        return hash_hmac('sha256', 'envio|' . $formularioId . '|' . $ip, $this->segredo);
    }

    private function agora(): int
    {
        return $this->relogio !== null ? ($this->relogio)() : time();
    }
}
