<?php

declare(strict_types=1);

namespace Pbl\Acesso;

use Doctrine\DBAL\Connection;
use Pbl\Busca\Texto;

/** Contas da equipe, perfis por subárvore e senhas (argon2id). */
final class UsuarioRepositorio implements Autenticador
{
    /** Tamanho fixo da página da lista de contas (fase 5h). */
    public const CONTAS_POR_PAGINA = 50;
    /**
     * Hashes de mentira, prontos, para login inexistente pagar o MESMO custo de um login que existe (gerá-los na hora custaria um
     * hash a mais e revelaria pelo tempo quais logins existem). Têm os mesmos parâmetros dos hashes reais; um teste confere isso.
     */
    public const HASH_FICTICIO_ARGON2ID = '$argon2id$v=19$m=65536,t=4,p=1$Qk80eGR1dVk3SGNCcDF1aw$J8BRG2pKuW1ZMgMnwjGPjP+OdHOxHfRjR5or+Udksnk';
    public const HASH_FICTICIO_BCRYPT = '$2y$12$ozxPHcQgIfOiMVKkQNZ7vO7GRN3mZ9Bwou75yjEirwnLpwzjRjabO';

    /**
     * Custos **fixos** (não os padrões do PHP, que mudam entre versões: o bcrypt foi de 10 para 12 no PHP 8.4). Mudar estes valores
     * exige regenerar os hashes fictícios acima; um teste confere que os dois andam juntos.
     */
    public const OPCOES_ARGON2ID = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1];
    public const OPCOES_BCRYPT = ['cost' => 12];

    private readonly string $algoritmo;

    /** @param string|null $algoritmo `PASSWORD_ARGON2ID` ou `PASSWORD_BCRYPT`; padrão: argon2id se o PHP tiver, senão bcrypt */
    public function __construct(private readonly Connection $conexao, private readonly ?Auditoria $auditoria = null, ?string $algoritmo = null)
    {
        $this->algoritmo = $algoritmo ?? (defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    public function criar(string $login, string $email, string $nome, string $senha, bool $administrador = false, string $ator = 'sistema', bool $provisoria = false): Usuario
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
            'administrador' => $administrador ? 1 : 0, 'ativo' => 1, 'criado_em' => gmdate('c'), 'senha_provisoria' => $provisoria ? 1 : 0,
        ]);
        $id = (int) $this->conexao->lastInsertId();
        $this->auditoria?->registrar('usuario.criar', $ator, null, null, ['usuario' => $login, 'administrador' => $administrador, 'provisoria' => $provisoria]);

        return new Usuario($id, $login, $email, trim($nome), $administrador, true, Usuario::PESSOA, $provisoria);
    }

    /**
     * Conta de serviço (agente/script): sem senha que confira, sem e-mail real, nunca administradora, sem login pelo navegador
     * (ADR 0031). Os papéis por seção vêm de `conceder`; `definirAtivo(false)` a desliga a qualquer momento.
     */
    public function criarContaDeServico(string $login, string $nome, string $ator = 'sistema'): Usuario
    {
        $login = mb_strtolower(trim($login));
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,62}[a-z0-9]$/D', $login) !== 1) {
            throw new \InvalidArgumentException('Login inválido (3 a 64 letras minúsculas, números, ponto, hífen ou sublinhado)');
        }
        $nome = trim($nome) === '' ? $login : trim($nome);
        if (mb_strlen($nome) > 200) {
            throw new \InvalidArgumentException('Nome inválido');
        }
        if ($this->conexao->fetchOne('SELECT 1 FROM usuario WHERE login = ?', [$login]) !== false) {
            throw new \InvalidArgumentException("O login {$login} já existe");
        }
        $email = $login . '@servico.invalid';
        $this->conexao->insert('usuario', [
            'login' => $login, 'email' => $email, 'nome' => $nome, 'senha_hash' => Usuario::SENHA_DE_SERVICO,
            'administrador' => 0, 'ativo' => 1, 'criado_em' => gmdate('c'), 'tipo' => Usuario::SERVICO,
        ]);
        $id = (int) $this->conexao->lastInsertId();
        $this->auditoria?->registrar('usuario.criar', $ator, null, null, ['usuario' => $login, 'administrador' => false, 'tipo' => Usuario::SERVICO]);

        return new Usuario($id, $login, $email, $nome, false, true, Usuario::SERVICO);
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

    /**
     * Contas de pessoa, ativas e já com acesso (sem convite pendente), cujo e-mail é este (sem diferenciar maiúsculas). Pode haver
     * mais de uma: o e-mail único só vale para contas novas (ADR 0036).
     *
     * @return list<Usuario>
     */
    public function porEmail(string $email): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            "SELECT * FROM usuario WHERE LOWER(email) = ? AND ativo = 1 AND tipo = 'pessoa' AND convite_pendente = 0 ORDER BY login",
            [mb_strtolower(trim($email))],
        );

        return array_map($this->hidratar(...), $linhas);
    }

    /**
     * Cria a conta de um convite (ADR 0038): sem senha que confira (`SENHA_DE_CONVITE`), `convite_pendente = 1`, não entra até
     * aceitar. O e-mail precisa ser novo (único, sem diferenciar maiúsculas).
     */
    public function criarConvidado(string $login, string $email, string $nome, bool $administrador = false, string $ator = 'sistema'): Usuario
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
        if ($this->conexao->fetchOne('SELECT 1 FROM usuario WHERE login = ?', [$login]) !== false) {
            throw new \InvalidArgumentException("O login {$login} já existe");
        }
        if ($this->conexao->fetchOne('SELECT 1 FROM usuario WHERE LOWER(email) = ?', [mb_strtolower($email)]) !== false) {
            throw new \InvalidArgumentException('Já existe uma conta com esse e-mail');
        }
        $this->conexao->insert('usuario', [
            'login' => $login, 'email' => $email, 'nome' => trim($nome), 'senha_hash' => Usuario::SENHA_DE_CONVITE,
            'administrador' => $administrador ? 1 : 0, 'ativo' => 1, 'criado_em' => gmdate('c'), 'convite_pendente' => 1,
        ]);
        $id = (int) $this->conexao->lastInsertId();
        $this->auditoria?->registrar('usuario.convidar', $ator, $id, null, ['usuario' => $login, 'administrador' => $administrador]);

        return new Usuario($id, $login, $email, trim($nome), $administrador, true);
    }

    public function convitePendente(int $id): bool
    {
        return (int) $this->conexao->fetchOne('SELECT convite_pendente FROM usuario WHERE id = ?', [$id]) === 1;
    }

    /** Aceita o convite: grava a senha escolhida (política de senha), limpa a pendência e marca o e-mail como confirmado. */
    public function aceitarConvite(int $id, string $senha, string $ator = 'sistema'): void
    {
        if (!$this->convitePendente($id)) {
            throw new \InvalidArgumentException('Não há convite pendente para esta conta');
        }
        $this->definirSenha($id, $senha, $ator);
        $this->conexao->update('usuario', ['convite_pendente' => 0, 'email_confirmado_em' => gmdate('c')], ['id' => $id]);
    }

    /** Apaga a conta de um convite que ainda não foi aceito (nunca entrou). @return bool se apagou */
    public function apagarConvitePendente(int $id, string $ator = 'sistema'): bool
    {
        $n = $this->conexao->executeStatement('DELETE FROM usuario WHERE id = ? AND convite_pendente = 1 AND ultimo_acesso IS NULL', [$id]);
        if ($n === 1) {
            $this->auditoria?->registrar('convite.cancelar', $ator, null, null, ['usuario_id' => $id]);
        }

        return $n === 1;
    }

    /** @return list<Usuario> */
    public function todos(): array
    {
        return array_map($this->hidratar(...), $this->conexao->fetchAllAssociative('SELECT * FROM usuario ORDER BY login'));
    }

    /**
     * Lista paginada de contas (fase 5h), com busca por login, nome ou e-mail (sem acento e maiúsculas, a mesma
     * normalização da busca de conteúdo, {@see Texto::normalizar}) e filtro por situação. Não há tantas contas numa
     * instalação típica para justificar índice ou SQL específico: filtra e pagina em PHP, sobre `todos()`.
     *
     * @param int<1, max> $pagina 1 é a primeira
     * @return array{total: int, itens: list<Usuario>}
     */
    public function listar(string $busca = '', ?bool $ativo = null, int $pagina = 1, int $porPagina = self::CONTAS_POR_PAGINA): array
    {
        $todos = $this->todos();
        if ($ativo !== null) {
            $todos = array_values(array_filter($todos, static fn (Usuario $u): bool => $u->ativo === $ativo));
        }
        $busca = Texto::normalizar(trim($busca));
        if ($busca !== '') {
            $todos = array_values(array_filter(
                $todos,
                static fn (Usuario $u): bool => str_contains(Texto::normalizar($u->login), $busca)
                    || str_contains(Texto::normalizar($u->nome), $busca)
                    || str_contains(Texto::normalizar($u->email), $busca),
            ));
        }
        $porPagina = max(1, $porPagina);
        $pagina = max(1, $pagina);

        return ['total' => count($todos), 'itens' => array_slice($todos, ($pagina - 1) * $porPagina, $porPagina)];
    }

    public function autenticar(string $login, string $senha): ?Usuario
    {
        $l = $this->conexao->fetchAssociative('SELECT * FROM usuario WHERE login = ?', [mb_strtolower(trim($login))]);
        // Login inexistente também paga o custo de uma verificação de hash: o tempo não revela se a conta existe.
        $hash = $l === false ? $this->hashFicticio() : (string) $l['senha_hash'];
        [$confere, $bruto] = $this->conferir($senha, $hash);
        if ($l === false || !$confere || (int) $l['ativo'] !== 1 || ($l['tipo'] ?? Usuario::PESSOA) === Usuario::SERVICO) {
            return null;
        }
        if ($bruto || password_needs_rehash($hash, $this->algoritmo, $this->opcoes())) {
            $this->conexao->update('usuario', ['senha_hash' => $this->hash($senha)], ['id' => $l['id']]);
        }
        $this->conexao->update('usuario', ['ultimo_acesso' => gmdate('c')], ['id' => $l['id']]);

        return $this->hidratar($l);
    }

    /** Altera nome e e-mail (login não muda). Audita só os nomes dos campos que mudaram. */
    public function atualizar(int $id, string $nome, string $email, string $ator = 'sistema'): void
    {
        $u = $this->porId($id) ?? throw new \InvalidArgumentException("Usuário {$id} não existe");
        $nome = trim($nome);
        $email = trim($email);
        if ($nome === '' || mb_strlen($nome) > 200) {
            throw new \InvalidArgumentException('Nome inválido');
        }
        if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('E-mail inválido');
        }
        $mudou = array_keys(array_filter(['nome' => $nome !== $u->nome, 'email' => $email !== $u->email]));
        if ($mudou === []) {
            return;
        }
        $this->conexao->update('usuario', ['nome' => $nome, 'email' => $email], ['id' => $id]);
        $this->auditoria?->registrar('usuario.atualizar', $ator, $id, null, ['campos' => implode(',', $mudou)]);
    }

    /** Liga ou desliga a marca de administrador global. Não deixa a instalação sem nenhum administrador ativo. */
    public function definirAdministrador(int $id, bool $administrador, string $ator = 'sistema'): void
    {
        $u = $this->porId($id) ?? throw new \InvalidArgumentException("Usuário {$id} não existe");
        if ($administrador && $u->ehServico()) {
            throw new \InvalidArgumentException('Conta de serviço não pode ser administradora (privilégio mínimo)');
        }
        if ($u->administrador === $administrador) {
            return;
        }
        if (!$administrador && $this->ehUltimoAdministradorAtivo($id)) {
            throw new \InvalidArgumentException('Esta é a última conta de administrador ativa; ela não pode deixar de ser administradora');
        }
        $this->conexao->update('usuario', ['administrador' => $administrador ? 1 : 0], ['id' => $id]);
        $this->auditoria?->registrar($administrador ? 'usuario.admin.dar' : 'usuario.admin.tirar', $ator, $id);
    }

    /** O usuário é um administrador ativo e o único que existe? (desativá-lo ou rebaixá-lo deixaria a instalação sem administração) */
    public function ehUltimoAdministradorAtivo(int $id): bool
    {
        $u = $this->porId($id);
        if ($u === null || !$u->administrador || !$u->ativo) {
            return false;
        }

        return (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM usuario WHERE administrador = 1 AND ativo = 1') <= 1;
    }

    /**
     * Toda definição de senha grava também a marca de provisória (sempre o valor pedido): sem `$provisoria`, a marca cai.
     *
     * @param string|null $manterSessao `id_hash` da sessão que continua aberta (troca da própria senha); as demais caem
     */
    public function definirSenha(int $id, string $senha, string $ator = 'sistema', ?string $manterSessao = null, bool $provisoria = false): void
    {
        $u = $this->porId($id) ?? throw new \InvalidArgumentException("Usuário {$id} não existe");
        if ($u->ehServico()) {
            throw new \InvalidArgumentException('Conta de serviço não tem senha');
        }
        $problema = SenhaPolitica::problema($senha, $u->login, $u->email);
        if ($problema !== null) {
            throw new \InvalidArgumentException($problema);
        }
        $this->conexao->update('usuario', ['senha_hash' => $this->hash($senha), 'senha_provisoria' => $provisoria ? 1 : 0], ['id' => $id]);
        $this->encerrarSessoes($id, $manterSessao); // trocar a senha derruba quem estiver logado com a antiga
        $this->auditoria?->registrar('senha.trocar', $ator, $id, null, ['provisoria' => $provisoria]);
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

    private function encerrarSessoes(int $usuarioId, ?string $manter = null): void
    {
        if ($manter === null) {
            $this->conexao->delete('sessao', ['usuario_id' => $usuarioId]);

            return;
        }
        $this->conexao->executeStatement('DELETE FROM sessao WHERE usuario_id = ? AND id_hash <> ?', [$usuarioId, $manter]);
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
        return password_hash($this->preparar($senha, $this->algoritmo), $this->algoritmo, $this->opcoes());
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

    /** @return array<string, int> */
    private function opcoes(): array
    {
        return $this->algoritmo === PASSWORD_BCRYPT ? self::OPCOES_BCRYPT : self::OPCOES_ARGON2ID;
    }

    private function hashFicticio(): string
    {
        return $this->algoritmo === PASSWORD_BCRYPT ? self::HASH_FICTICIO_BCRYPT : self::HASH_FICTICIO_ARGON2ID;
    }

    /** @param array<string, mixed> $l */
    private function hidratar(array $l): Usuario
    {
        return new Usuario((int) $l['id'], (string) $l['login'], (string) $l['email'], (string) $l['nome'], (int) $l['administrador'] === 1, (int) $l['ativo'] === 1, (string) ($l['tipo'] ?? Usuario::PESSOA), (int) ($l['senha_provisoria'] ?? 0) === 1);
    }
}
