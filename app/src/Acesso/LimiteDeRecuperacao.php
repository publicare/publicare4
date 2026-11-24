<?php

declare(strict_types=1);

namespace Pbl\Acesso;

use Doctrine\DBAL\Connection;

/**
 * Limite dos pedidos de recuperação (ADR 0036): 5 por conta e 20 por IP em 15 min, sobre `tentativa_login` com chaves HMAC de
 * prefixo próprio (`rec|`). Conta o identificador mesmo se a conta não existe, então estourar não revela nada. O IP nunca é
 * guardado em claro.
 */
final class LimiteDeRecuperacao
{
    public const JANELA = 900;
    public const POR_CONTA = 5;
    public const POR_IP = 20;

    /** @param (\Closure(): int)|null $relogio */
    public function __construct(private readonly Connection $conexao, private readonly string $segredo, private readonly ?\Closure $relogio = null)
    {
    }

    /** Registra o pedido e diz se ele pode seguir (`false`: omitir o envio, mantendo a mesma resposta). */
    public function registrar(string $identificador, string $ip): bool
    {
        $agora = $this->agora();
        $conta = $this->chaveDaConta($identificador);
        $origem = $this->chaveDoIp($ip);
        $this->conexao->executeStatement('DELETE FROM tentativa_login WHERE quando < ?', [$agora - 86400]);
        $permitido = $this->contar($conta) < self::POR_CONTA && $this->contar($origem) < self::POR_IP;
        $this->conexao->insert('tentativa_login', ['chave_hash' => $conta, 'quando' => $agora]);
        $this->conexao->insert('tentativa_login', ['chave_hash' => $origem, 'quando' => $agora]);

        return $permitido;
    }

    private function contar(string $chave): int
    {
        return (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM tentativa_login WHERE chave_hash = ? AND quando > ?', [$chave, $this->agora() - self::JANELA]);
    }

    private function chaveDaConta(string $identificador): string
    {
        return hash_hmac('sha256', 'rec|conta|' . mb_strtolower(trim($identificador)), $this->segredo);
    }

    private function chaveDoIp(string $ip): string
    {
        return hash_hmac('sha256', 'rec|ip|' . $ip, $this->segredo);
    }

    private function agora(): int
    {
        return $this->relogio !== null ? ($this->relogio)() : time();
    }
}
