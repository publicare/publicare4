<?php

declare(strict_types=1);

namespace Pbl\Acesso;

use Doctrine\DBAL\Connection;

/** Contas da equipe, perfis por subárvore e senhas (argon2id). */
final class UsuarioRepositorio implements Autenticador
{
    private ?string $hashFicticio = null;
    private readonly string $algoritmo;

    /** @param string|null $algoritmo `PASSWORD_ARGON2ID` ou `PASSWORD_BCRYPT`; padrão: argon2id se o PHP tiver, senão bcrypt */
    public function __construct(private readonly Connection $conexao, private readonly ?Auditoria $auditoria = null, ?string $algoritmo = null)
    {
        $this->algoritmo = $algoritmo ?? (defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    public function criar(string $login, string $email, string $nome, string $senha, bool $administrador = false, string $ator = 'sistema'): Usuario
    {
        $login = mb_strtolower(trim($login));
        $email = trim($email);
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,62}[a-z0-9]$/D', $login) !== 1) {
            throw new \InvalidArgumentException('Login inválido (3 a 64 letras minúsculas, números, ponto, hífen ou sublinhado)');
        }
        if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('E-mail inválido');
        }
        if (trim($nome) === '' || mb_strlen($nome) > 200) {
            throw new \InvalidArgumentException('Nome inválido');
        }
        $problema = SenhaPolitica::problema($senha, $login, $email);
        if ($problema !== null) {
            throw new \InvalidArgumentException($problema);
        }
        if ($this->conexao->fetchOne('SELECT 1 FROM usuario WHERE login = ?', [$login]) !== false) {
            throw new \InvalidArgumentException("O login {$login} já existe");
        }
        $this->conexao->insert('usuario', [
            'login' => $login, 'email' => $email, 'nome' => trim($nome), 'senha_hash' => $this->hash($senha),
            'administrador' => $administrador ? 1 : 0, 'ativo' => 1, 'criado_em' => gmdate('c'),
        ]);
        $id = (int) $this->conexao->lastInsertId();
        $this->auditoria?->registrar('usuario.criar', $ator, null, null, ['usuario' => $login, 'administrador' => $administrador]);

        return new Usuario($id, $login, $email, trim($nome), $administrador, true);
    }

    public function porLogin(string $login): ?Usuario
    {
        $l = $this->conexao->fetchAssociative('SELECT * FROM usuario WHERE login = ?', [mb_strtolower(trim($login))]);

        return $l === false ? null : $this->hidratar($l);
    }

    public function porId(int $id): ?Usuario
    {
        $l = $this->conexao->fetchAssociative('SELECT * FROM usuario WHERE id = ?', [$id]);

        return $l === false ? null : $this->hidratar($l);
    }

    /** @return list<Usuario> */
    public function todos(): array
    {
        return array_map($this->hidratar(...), $this->conexao->fetchAllAssociative('SELECT * FROM usuario ORDER BY login'));
    }

    public function autenticar(string $login, string $senha): ?Usuario
    {
        $l = $this->conexao->fetchAssociative('SELECT * FROM usuario WHERE login = ?', [mb_strtolower(trim($login))]);
        // Login inexistente também paga o custo de uma verificação de hash: o tempo não revela se a conta existe.
        $hash = $l === false ? $this->hashFicticio() : (string) $l['senha_hash'];
        [$confere, $bruto] = $this->conferir($senha, $hash);
        if ($l === false || !$confere || (int) $l['ativo'] !== 1) {
            return null;
        }
        if ($bruto || password_needs_rehash($hash, $this->algoritmo)) {
            $this->conexao->update('usuario', ['senha_hash' => $this->hash($senha)], ['id' => $l['id']]);
        }
        $this->conexao->update('usuario', ['ultimo_acesso' => gmdate('c')], ['id' => $l['id']]);

        return $this->hidratar($l);
    }

    public function definirSenha(int $id, string $senha, string $ator = 'sistema'): void
    {
        $u = $this->porId($id) ?? throw new \InvalidArgumentException("Usuário {$id} não existe");
        $problema = SenhaPolitica::problema($senha, $u->login, $u->email);
        if ($problema !== null) {
            throw new \InvalidArgumentException($problema);
        }
        $this->conexao->update('usuario', ['senha_hash' => $this->hash($senha)], ['id' => $id]);
        $this->encerrarSessoes($id); // trocar a senha derruba quem estiver logado com a antiga
        $this->auditoria?->registrar('senha.trocar', $ator, $id);
    }

    public function definirAtivo(int $id, bool $ativo, string $ator = 'sistema'): void
    {
        if ($this->porId($id) === null) {
            throw new \InvalidArgumentException("Usuário {$id} não existe");
        }
        $this->conexao->update('usuario', ['ativo' => $ativo ? 1 : 0], ['id' => $id]);
        if (!$ativo) {
            $this->encerrarSessoes($id);
        }
        $this->auditoria?->registrar($ativo ? 'usuario.ativar' : 'usuario.desativar', $ator, $id);
    }

    /** Dá a um usuário um perfil numa subárvore (o objeto e tudo abaixo dele). Substitui o perfil que ele já tinha nesse objeto. */
    public function conceder(int $usuarioId, int $objetoId, Perfil $perfil, string $ator = 'sistema'): void
    {
        if ($this->porId($usuarioId) === null) {
            throw new \InvalidArgumentException("Usuário {$usuarioId} não existe");
        }
        if ($this->conexao->fetchOne('SELECT 1 FROM objeto WHERE id = ?', [$objetoId]) === false) {
            throw new \InvalidArgumentException("Objeto {$objetoId} não existe");
        }
        $this->conexao->delete('acesso', ['usuario_id' => $usuarioId, 'objeto_id' => $objetoId]);
        $this->conexao->insert('acesso', ['usuario_id' => $usuarioId, 'objeto_id' => $objetoId, 'perfil' => $perfil->value]);
        $this->auditoria?->registrar('acesso.conceder', $ator, $usuarioId, $objetoId, ['perfil' => $perfil->value]);
    }

    public function revogar(int $usuarioId, int $objetoId, string $ator = 'sistema'): void
    {
        if ($this->conexao->delete('acesso', ['usuario_id' => $usuarioId, 'objeto_id' => $objetoId]) === 0) {
            throw new \InvalidArgumentException('Esse usuário não tem acesso concedido a esse objeto');
        }
        $this->auditoria?->registrar('acesso.revogar', $ator, $usuarioId, $objetoId);
    }

    /** @return list<array{objeto_id: int, perfil: Perfil}> */
    public function acessosDe(int $usuarioId): array
    {
        $saida = [];
        foreach ($this->conexao->fetchAllAssociative('SELECT objeto_id, perfil FROM acesso WHERE usuario_id = ? ORDER BY objeto_id', [$usuarioId]) as $l) {
            $perfil = Perfil::tryFrom((string) $l['perfil']);
            if ($perfil !== null) {
                $saida[] = ['objeto_id' => (int) $l['objeto_id'], 'perfil' => $perfil];
            }
        }

        return $saida;
    }

    private function encerrarSessoes(int $usuarioId): void
    {
        $this->conexao->delete('sessao', ['usuario_id' => $usuarioId]);
    }

    /**
     * O bcrypt só enxerga os 72 primeiros bytes da senha: duas senhas longas iguais no começo seriam a mesma. Por isso, no bcrypt, a
     * senha passa antes por SHA-256 (em base64, sem byte nulo), o que preserva a senha inteira. O argon2id não tem esse limite.
     */
    private function preparar(string $senha, string $algoritmo): string
    {
        return $algoritmo === PASSWORD_BCRYPT ? base64_encode(hash('sha256', $senha, true)) : $senha;
    }

    private function hash(string $senha): string
    {
        return password_hash($this->preparar($senha, $this->algoritmo), $this->algoritmo);
    }

    /**
     * @return array{bool, bool} [confere, só conferiu pelo formato antigo (bcrypt sem pré-hash) e precisa ser regravado]
     */
    private function conferir(string $senha, string $hash): array
    {
        if (!str_starts_with($hash, '$2y$')) {
            return [password_verify($senha, $hash), false];
        }
        if (password_verify($this->preparar($senha, PASSWORD_BCRYPT), $hash)) {
            return [true, false];
        }
        // Hash bcrypt de antes do pré-hash: só vale para senha que cabia nos 72 bytes (senão a verificação truncaria).
        $antigo = strlen($senha) <= 72 && password_verify($senha, $hash);

        return [$antigo, $antigo];
    }

    private function hashFicticio(): string
    {
        return $this->hashFicticio ??= $this->hash(bin2hex(random_bytes(16)));
    }

    /** @param array<string, mixed> $l */
    private function hidratar(array $l): Usuario
    {
        return new Usuario((int) $l['id'], (string) $l['login'], (string) $l['email'], (string) $l['nome'], (int) $l['administrador'] === 1, (int) $l['ativo'] === 1);
    }
}
