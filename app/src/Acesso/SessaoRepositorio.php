<?php

declare(strict_types=1);

namespace Pbl\Acesso;

use Doctrine\DBAL\Connection;

/**
 * Sessões da administração (ADR 0011). O cookie leva um token aleatório de 32 bytes; o banco guarda só o **hash** dele, então
 * quem lê o banco não consegue se passar por ninguém. Expira por inatividade (30 min) e por tempo absoluto (12 h).
 */
final class SessaoRepositorio
{
    public const INATIVIDADE = 1800;
    public const DURACAO_MAXIMA = 43200;
    private const ATUALIZAR_USO_A_CADA = 60;

    /** @param (\Closure(): int)|null $relogio devolve o "agora" em segundos (testes) */
    public function __construct(
        private readonly Connection $conexao,
        private readonly UsuarioRepositorio $usuarios,
        private readonly ?\Closure $relogio = null,
    ) {
    }

    /** Abre uma sessão **nova** (token novo a cada login) e devolve o token em texto, para ir no cookie. */
    public function criar(int $usuarioId): string
    {
        $agora = $this->agora();
        $token = bin2hex(random_bytes(32));
        $this->conexao->executeStatement('DELETE FROM sessao WHERE expira_em < ?', [$agora]);
        $this->conexao->insert('sessao', [
            'id_hash' => self::hash($token), 'usuario_id' => $usuarioId, 'criado_em' => $agora,
            'expira_em' => $agora + self::DURACAO_MAXIMA, 'ultimo_uso' => $agora,
        ]);

        return $token;
    }

    public function validar(string $token): ?SessaoAtiva
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $token) !== 1) {
            return null;
        }
        $hash = self::hash($token);
        $s = $this->conexao->fetchAssociative('SELECT * FROM sessao WHERE id_hash = ?', [$hash]);
        if ($s === false) {
            return null;
        }
        $agora = $this->agora();
        if ($agora >= (int) $s['expira_em'] || $agora - (int) $s['ultimo_uso'] > self::INATIVIDADE) {
            $this->conexao->delete('sessao', ['id_hash' => $hash]);

            return null;
        }
        $usuario = $this->usuarios->porId((int) $s['usuario_id']);
        if ($usuario === null || !$usuario->ativo) {
            $this->conexao->delete('sessao', ['id_hash' => $hash]);

            return null;
        }
        if ($agora - (int) $s['ultimo_uso'] >= self::ATUALIZAR_USO_A_CADA) {
            $this->conexao->update('sessao', ['ultimo_uso' => $agora], ['id_hash' => $hash]);
        }

        return new SessaoAtiva($usuario, $hash);
    }

    public function encerrar(string $token): void
    {
        $this->conexao->delete('sessao', ['id_hash' => self::hash($token)]);
    }

    public function encerrarTodasDe(int $usuarioId): void
    {
        $this->conexao->delete('sessao', ['usuario_id' => $usuarioId]);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function agora(): int
    {
        return $this->relogio !== null ? ($this->relogio)() : time();
    }
}
