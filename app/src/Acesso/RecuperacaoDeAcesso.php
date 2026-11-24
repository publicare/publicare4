<?php

declare(strict_types=1);

namespace Pbl\Acesso;

use Pbl\Arquivos\GravacaoAtomica;

/**
 * "Esqueci a senha" e "esqueci o usuário" (ADR 0035 e 0036).
 *
 * O pedido ({@see solicitar}) faz sempre o mesmo trabalho barato e síncrono: registra o limite e grava um job (0600). A busca da
 * conta, a emissão do token e o envio rodam depois, em {@see processarFila} (após a resposta ou no cron), então nem o corpo nem
 * o tempo da resposta dizem se a conta existe, está inativa, sem e-mail ou limitada.
 */
final class RecuperacaoDeAcesso
{
    public const SENHA = 'senha';
    public const USUARIO = 'usuario';
    public const MENSAGEM_UNIFORME = 'Se os dados estiverem cadastrados, enviamos as instruções para o e-mail da conta.';
    public const MENSAGEM_TOKEN_INVALIDO = 'Este link não é válido ou já foi usado. Peça um novo.';

    public function __construct(
        private readonly UsuarioRepositorio $usuarios,
        private readonly TokenDeConta $tokens,
        private readonly LimiteDeRecuperacao $limite,
        private readonly EmailsDeConta $emails,
        private readonly Auditoria $auditoria,
        private readonly string $pastaDaFila,
    ) {
    }

    public function habilitado(): bool
    {
        return $this->emails->habilitado();
    }

    /** Parte síncrona. Nunca revela nada: devolve sempre {@see MENSAGEM_UNIFORME} (quem chama mostra). */
    public function solicitar(string $tipo, string $identificador, string $ip, ?string $ipHash = null): string
    {
        if (!$this->habilitado() || !in_array($tipo, [self::SENHA, self::USUARIO], true)) {
            return self::MENSAGEM_UNIFORME;
        }
        $identificador = mb_substr(trim($identificador), 0, 255);
        $permitido = $this->limite->registrar($identificador, $ip);
        if (!$permitido) {
            $this->auditoria->registrar('recuperacao.limitada', 'sistema', null, null, ['tipo' => $tipo], $ipHash);
        }
        GravacaoAtomica::gravar(
            $this->pastaDaFila . '/' . bin2hex(random_bytes(12)) . '.json',
            json_encode(['tipo' => $tipo, 'identificador' => $identificador, 'permitido' => $permitido, 'ip_hash' => $ipHash], JSON_THROW_ON_ERROR),
        );

        return self::MENSAGEM_UNIFORME;
    }

    /** Parte assíncrona (cron, depois da resposta, ou `bin/pbl`). @return int jobs processados */
    public function processarFila(): int
    {
        $n = 0;
        foreach (glob($this->pastaDaFila . '/*.json') ?: [] as $arquivo) {
            $job = json_decode((string) @file_get_contents($arquivo), true);
            @unlink($arquivo);
            if (!is_array($job) || ($job['permitido'] ?? false) !== true) {
                $n++;
                continue;
            }
            $ipHash = isset($job['ip_hash']) ? (string) $job['ip_hash'] : null;
            if (($job['tipo'] ?? '') === self::SENHA) {
                $this->enviarSenha((string) ($job['identificador'] ?? ''), $ipHash);
            } elseif (($job['tipo'] ?? '') === self::USUARIO) {
                $this->enviarUsuarios((string) ($job['identificador'] ?? ''), $ipHash);
            }
            $n++;
        }

        return $n;
    }

    /** Emite o token e devolve o link (para o operador, `bin/pbl conta-recuperar`). Quem imprime trata o link como segredo. */
    public function emitirLinkParaOperador(Usuario $u): string
    {
        $token = $this->tokens->emitir(TokenDeConta::SENHA, $u->id);
        $this->auditoria->registrar('recuperacao.emitida', 'cli', $u->id);

        return $this->emails->link(EmailsDeConta::CAMINHO_SENHA, $token);
    }

    /** O link ainda abre? (mostra o formulário; não consome) */
    public function tokenValido(string $token): bool
    {
        return $this->tokens->consultar($token, TokenDeConta::SENHA) !== null;
    }

    /**
     * Define a nova senha. A política é conferida **antes** de consumir (senha fraca não queima o link). Encerra todas as sessões,
     * invalida os outros tokens de senha e avisa por e-mail.
     *
     * @return string|null o motivo da recusa, ou null se deu certo
     */
    public function redefinir(string $token, string $novaSenha, ?string $ipHash = null): ?string
    {
        $dados = $this->tokens->consultar($token, TokenDeConta::SENHA);
        $u = $dados === null ? null : $this->usuarios->porId($dados['usuario_id']);
        if ($u === null || !$u->ativo || $u->ehServico()) {
            return self::MENSAGEM_TOKEN_INVALIDO;
        }
        $problema = SenhaPolitica::problema($novaSenha, $u->login, $u->email);
        if ($problema !== null) {
            return $problema;
        }
        if ($this->tokens->consumir($token, TokenDeConta::SENHA) === null) {
            return self::MENSAGEM_TOKEN_INVALIDO;
        }
        $this->usuarios->definirSenha($u->id, $novaSenha, 'recuperacao');
        $this->tokens->invalidar($u->id, TokenDeConta::SENHA);
        $this->auditoria->registrar('recuperacao.concluida', 'recuperacao', $u->id, null, [], $ipHash);
        $this->avisar($u);

        return null;
    }

    private function enviarSenha(string $identificador, ?string $ipHash): void
    {
        $contas = str_contains($identificador, '@') ? $this->usuarios->porEmail($identificador) : $this->elegivel($this->usuarios->porLogin($identificador));
        foreach ($contas as $u) {
            try {
                $this->emails->recuperacao($u, $this->tokens->emitir(TokenDeConta::SENHA, $u->id, null, $ipHash));
                $this->auditoria->registrar('recuperacao.enviada', 'sistema', $u->id, null, [], $ipHash);
            } catch (\RuntimeException | \InvalidArgumentException) {
                $this->auditoria->registrar('recuperacao.falha', 'sistema', $u->id);
            }
        }
    }

    private function enviarUsuarios(string $identificador, ?string $ipHash): void
    {
        if (!str_contains($identificador, '@')) {
            return;
        }
        $contas = $this->usuarios->porEmail($identificador);
        if ($contas === []) {
            return;
        }
        try {
            $this->emails->usuarios($contas[0]->email, array_map(static fn (Usuario $u): string => $u->login, $contas));
            $this->auditoria->registrar('recuperacao.usuario.enviado', 'sistema', null, null, [], $ipHash);
        } catch (\RuntimeException | \InvalidArgumentException) {
            $this->auditoria->registrar('recuperacao.falha', 'sistema');
        }
    }

    /** @return list<Usuario> */
    private function elegivel(?Usuario $u): array
    {
        return $u !== null && $u->ativo && !$u->ehServico() && !$this->usuarios->convitePendente($u->id) ? [$u] : [];
    }

    private function avisar(Usuario $u): void
    {
        try {
            $this->emails->senhaAlterada($u);
        } catch (\RuntimeException | \InvalidArgumentException) {
            $this->auditoria->registrar('recuperacao.falha', 'sistema', $u->id);
        }
    }
}
