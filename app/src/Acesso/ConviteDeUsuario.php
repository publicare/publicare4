<?php

declare(strict_types=1);

namespace Pbl\Acesso;

/**
 * Convite (ADR 0038): o administrador cria a conta com e-mail e a própria pessoa define a senha por link de uso único (72 h).
 * A conta convidada não entra até aceitar. Reenviar invalida o link anterior; cancelar apaga a conta se ela nunca foi usada.
 */
final class ConviteDeUsuario
{
    public function __construct(
        private readonly UsuarioRepositorio $usuarios,
        private readonly TokenDeConta $tokens,
        private readonly EmailsDeConta $emails,
        private readonly Auditoria $auditoria,
    ) {
    }

    /**
     * @param bool $enviar `false` só emite o link (para quem não tem SMTP: `--sem-envio`)
     * @return string o link de uso único (segredo: quem chama só mostra se `$enviar` for falso ou ao operador)
     * @throws \InvalidArgumentException dados inválidos; \RuntimeException se o e-mail não pôde ser enviado
     */
    public function convidar(string $login, string $nome, string $email, bool $administrador, string $ator, bool $enviar = true): string
    {
        $this->exigirBase($enviar);
        $u = $this->usuarios->criarConvidado($login, $email, $nome, $administrador, $ator);

        return $this->emitir($u, $ator, $enviar);
    }

    /** @return string o link novo; o anterior deixa de valer */
    public function reenviar(int $usuarioId, string $ator, bool $enviar = true): string
    {
        $this->exigirBase($enviar);
        $u = $this->usuarios->porId($usuarioId);
        if ($u === null || !$this->usuarios->convitePendente($usuarioId)) {
            throw new \InvalidArgumentException('Não há convite pendente para esta conta');
        }
        $this->auditoria->registrar('convite.reenviar', $ator, $usuarioId);

        return $this->emitir($u, $ator, $enviar);
    }

    /** Invalida o link e apaga a conta se ela ainda estiver pendente e nunca entrou. @return bool se a conta foi apagada */
    public function cancelar(int $usuarioId, string $ator): bool
    {
        $this->tokens->invalidar($usuarioId, TokenDeConta::CONVITE);
        $apagou = $this->usuarios->apagarConvitePendente($usuarioId, $ator);
        if ($apagou) {
            $this->tokens->apagarDoUsuario($usuarioId);
        }

        return $apagou;
    }

    public function tokenValido(string $token): bool
    {
        return $this->tokens->consultar($token, TokenDeConta::CONVITE) !== null;
    }

    /** @return string|null motivo da recusa (a política é conferida antes de consumir o link), ou null se aceitou */
    public function aceitar(string $token, string $senha): ?string
    {
        $dados = $this->tokens->consultar($token, TokenDeConta::CONVITE);
        $u = $dados === null ? null : $this->usuarios->porId($dados['usuario_id']);
        if ($u === null || !$u->ativo || !$this->usuarios->convitePendente($u->id)) {
            return RecuperacaoDeAcesso::MENSAGEM_TOKEN_INVALIDO;
        }
        $problema = SenhaPolitica::problema($senha, $u->login, $u->email);
        if ($problema !== null) {
            return $problema;
        }
        if ($this->tokens->consumir($token, TokenDeConta::CONVITE) === null) {
            return RecuperacaoDeAcesso::MENSAGEM_TOKEN_INVALIDO;
        }
        $this->usuarios->aceitarConvite($u->id, $senha, 'convite');
        $this->auditoria->registrar('convite.aceitar', 'convite', $u->id);

        return null;
    }

    private function emitir(Usuario $u, string $ator, bool $enviar): string
    {
        $token = $this->tokens->emitir(TokenDeConta::CONVITE, $u->id, $u->email);
        if ($enviar) {
            $this->emails->convite($u, $token);
        }

        return $this->emails->link(EmailsDeConta::CAMINHO_CONVITE, $token);
    }

    private function exigirBase(bool $enviar): void
    {
        if ($enviar && !$this->emails->habilitado()) {
            throw new \InvalidArgumentException('O envio de e-mail não está configurado; gere o link sem envio e entregue-o à pessoa');
        }
        if (!$this->emails->temBase()) {
            throw new \InvalidArgumentException('Configure o domínio principal do site antes de convidar (os links usam esse endereço)');
        }
    }
}
