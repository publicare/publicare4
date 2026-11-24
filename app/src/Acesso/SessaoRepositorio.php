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
        if ($this->usuarios->porId($usuarioId)?->ehServico() === true) {
            throw new \InvalidArgumentException('Conta de serviço não abre sessão no painel');
        }
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
        if ($usuario === null || !$usuario->ativo || $usuario->ehServico()) {
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

    /**
     * Sessões abertas e vigentes da conta, da mais recente para a mais antiga. Devolve só um identificador curto (prefixo do hash)
     * e datas: nunca o token nem o hash inteiro.
     *
     * @return list<array{ref: string, criado_em: int, ultimo_uso: int, atual: bool}>
     */
    public function listarDe(int $usuarioId, string $atualHash): array
    {
        $agora = $this->agora();
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT id_hash, criado_em, ultimo_uso FROM sessao WHERE usuario_id = ? AND expira_em > ? ORDER BY ultimo_uso DESC',
            [$usuarioId, $agora],
        );
        $saida = [];
        foreach ($linhas as $l) {
            if ($agora - (int) $l['ultimo_uso'] > self::INATIVIDADE) {
                continue;
            }
            $hash = (string) $l['id_hash'];
            $saida[] = ['ref' => substr($hash, 0, 16), 'criado_em' => (int) $l['criado_em'], 'ultimo_uso' => (int) $l['ultimo_uso'], 'atual' => hash_equals($atualHash, $hash)];
        }

        return $saida;
    }

    /** Encerra uma sessão da conta pela referência curta, nunca a atual. Devolve se encerrou. */
    public function encerrarPorRef(int $usuarioId, string $ref, string $atualHash): bool
    {
        if (preg_match('/^[0-9a-f]{16}$/D', $ref) !== 1) {
            return false;
        }
        $n = $this->conexao->executeStatement(
            'DELETE FROM sessao WHERE usuario_id = ? AND id_hash <> ? AND substr(id_hash, 1, 16) = ?',
            [$usuarioId, $atualHash, $ref],
        );

        return $n > 0;
    }

    /** Encerra todas as sessões da conta, menos a atual. */
    public function encerrarOutrasDe(int $usuarioId, string $atualHash): int
    {
        return (int) $this->conexao->executeStatement('DELETE FROM sessao WHERE usuario_id = ? AND id_hash <> ?', [$usuarioId, $atualHash]);
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
