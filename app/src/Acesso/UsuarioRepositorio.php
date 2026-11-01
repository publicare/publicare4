<?php

declare(strict_types=1);

namespace Pbl\Acesso;

use Doctrine\DBAL\Connection;

/** Contas da equipe, perfis por subárvore e senhas (argon2id). */
final class UsuarioRepositorio implements Autenticador
{
    private static ?string $hashFicticio = null;

    public function __construct(private readonly Connection $conexao, private readonly ?Auditoria $auditoria = null)
    {
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
            'login' => $login, 'email' => $email, 'nome' => trim($nome), 'senha_hash' => self::hash($senha),
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
        $hash = $l === false ? self::hashFicticio() : (string) $l['senha_hash'];
        $confere = password_verify($senha, $hash);
        if ($l === false || !$confere || (int) $l['ativo'] !== 1) {
            return null;
        }
        if (password_needs_rehash($hash, self::algoritmo())) {
            $this->conexao->update('usuario', ['senha_hash' => self::hash($senha)], ['id' => $l['id']]);
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
        $this->conexao->update('usuario', ['senha_hash' => self::hash($senha)], ['id' => $id]);
        $this->auditoria?->registrar('senha.trocar', $ator, $id);
    }

    public function definirAtivo(int $id, bool $ativo, string $ator = 'sistema'): void
    {
        if ($this->porId($id) === null) {
            throw new \InvalidArgumentException("Usuário {$id} não existe");
        }
        $this->conexao->update('usuario', ['ativo' => $ativo ? 1 : 0], ['id' => $id]);
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

    private static function algoritmo(): string
    {
        // Argon2id quando o PHP foi compilado com ele (imagem oficial e a maioria das hospedagens); senão, bcrypt.
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    private static function hash(string $senha): string
    {
        return password_hash($senha, self::algoritmo());
    }

    private static function hashFicticio(): string
    {
        return self::$hashFicticio ??= self::hash(bin2hex(random_bytes(16)));
    }

    /** @param array<string, mixed> $l */
    private function hidratar(array $l): Usuario
    {
        return new Usuario((int) $l['id'], (string) $l['login'], (string) $l['email'], (string) $l['nome'], (int) $l['administrador'] === 1, (int) $l['ativo'] === 1);
    }
}
