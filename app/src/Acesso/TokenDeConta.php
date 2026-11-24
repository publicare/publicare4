<?php

declare(strict_types=1);

namespace Pbl\Acesso;

use Doctrine\DBAL\Connection;

/**
 * Tokens de conta de uso único (ADR 0035). O token tem 256 bits (64 hex) e **só o hash** vai ao banco. Consultar não consome (um
 * scanner de e-mail que abre o link não o queima); consumir é um UPDATE atômico conferindo as linhas afetadas.
 */
final class TokenDeConta
{
    public const SENHA = 'senha';
    public const CONVITE = 'convite';
    public const EMAIL_NOVO = 'email_novo';
    public const EMAIL_ANTIGO = 'email_antigo';

    public const VALIDADE_PADRAO = 1800;
    /** Convite: 72 h (desvio da demanda, ADR 0035; pendência de aprovação do dono). */
    public const VALIDADE_CONVITE = 259200;

    private const TIPOS = [self::SENHA, self::CONVITE, self::EMAIL_NOVO, self::EMAIL_ANTIGO];

    /** @param (\Closure(): int)|null $relogio */
    public function __construct(private readonly Connection $conexao, private readonly ?\Closure $relogio = null)
    {
    }

    /** Emite um token novo e invalida os anteriores do mesmo tipo e usuário. Devolve o token em claro (só agora). */
    public function emitir(string $tipo, int $usuarioId, ?string $emailAlvo = null, ?string $ipHash = null, ?int $validade = null): string
    {
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new \InvalidArgumentException('Tipo de token inválido');
        }
        $agora = $this->agora();
        $this->invalidar($usuarioId, $tipo);
        $token = bin2hex(random_bytes(32));
        $this->conexao->insert('token_conta', [
            'tipo' => $tipo, 'usuario_id' => $usuarioId, 'token_hash' => self::hash($token), 'email_alvo' => $emailAlvo,
            'criado_em' => $agora, 'expira_em' => $agora + ($validade ?? ($tipo === self::CONVITE ? self::VALIDADE_CONVITE : self::VALIDADE_PADRAO)),
            'ip_hash' => $ipHash,
        ]);

        return $token;
    }

    /**
     * Confere sem consumir.
     *
     * @return array{usuario_id: int, email_alvo: ?string}|null null para token malformado, de outro tipo, usado, expirado ou inexistente
     */
    public function consultar(string $token, string $tipo): ?array
    {
        $l = $this->buscar($token, $tipo);

        return $l !== null && $l['usado_em'] === null && (int) $l['expira_em'] > $this->agora()
            ? ['usuario_id' => (int) $l['usuario_id'], 'email_alvo' => $l['email_alvo'] === null ? null : (string) $l['email_alvo']]
            : null;
    }

    /**
     * Consome (uso único). Duas requisições simultâneas com o mesmo token: só uma recebe o resultado.
     *
     * @return array{usuario_id: int, email_alvo: ?string}|null
     */
    public function consumir(string $token, string $tipo): ?array
    {
        $dados = $this->consultar($token, $tipo);
        if ($dados === null) {
            return null;
        }
        $afetadas = $this->conexao->executeStatement(
            'UPDATE token_conta SET usado_em = ? WHERE token_hash = ? AND tipo = ? AND usado_em IS NULL AND expira_em > ?',
            [$this->agora(), self::hash($token), $tipo, $this->agora()],
        );

        return $afetadas === 1 ? $dados : null;
    }

    /** Invalida os tokens ainda abertos do usuário (de um tipo, ou de todos). */
    public function invalidar(int $usuarioId, ?string $tipo = null): void
    {
        $sql = 'UPDATE token_conta SET usado_em = ? WHERE usuario_id = ? AND usado_em IS NULL';
        $params = [$this->agora(), $usuarioId];
        if ($tipo !== null) {
            $sql .= ' AND tipo = ?';
            $params[] = $tipo;
        }
        $this->conexao->executeStatement($sql, $params);
    }

    public function apagarDoUsuario(int $usuarioId): void
    {
        $this->conexao->delete('token_conta', ['usuario_id' => $usuarioId]);
    }

    /** Apaga o que expirou há mais de um dia (rotina do cron). @return int linhas apagadas */
    public function limparExpirados(): int
    {
        return (int) $this->conexao->executeStatement('DELETE FROM token_conta WHERE expira_em < ?', [$this->agora() - 86400]);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array<string, mixed>|null */
    private function buscar(string $token, string $tipo): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $token) !== 1) {
            return null;
        }
        $l = $this->conexao->fetchAssociative('SELECT * FROM token_conta WHERE token_hash = ?', [self::hash($token)]);
        if ($l === false || !hash_equals((string) $l['tipo'], $tipo)) {
            return null;
        }

        return $l;
    }

    private function agora(): int
    {
        return $this->relogio !== null ? ($this->relogio)() : time();
    }
}
