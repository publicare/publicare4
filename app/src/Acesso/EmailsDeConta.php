<?php

declare(strict_types=1);

namespace Pbl\Acesso;

use Pbl\Email\Mensagem;
use Pbl\Email\Transporte;

/**
 * Textos e links dos e-mails de conta. O link usa só o endereço do domínio principal (`$base`), nunca o Host da requisição. O
 * único segredo que um e-mail carrega é o link de uso único; senha nunca vai em e-mail.
 */
final class EmailsDeConta
{
    public const CAMINHO_SENHA = '/_pbl/redefinir-senha/';
    public const CAMINHO_CONVITE = '/_pbl/convite/';
    public const CAMINHO_EMAIL = '/_pbl/confirmar-email/';

    public function __construct(private readonly Transporte $transporte, private readonly string $base, private readonly string $nomeDoSite = 'PBL4')
    {
    }

    /** Há como enviar e como montar links? Sem isso a recuperação fica desabilitada. */
    public function habilitado(): bool
    {
        return $this->transporte->tipo() !== Transporte::DESLIGADO && $this->base !== '';
    }

    public function temBase(): bool
    {
        return $this->base !== '';
    }

    public function link(string $caminho, string $token): string
    {
        return rtrim($this->base, '/') . $caminho . $token;
    }

    public function recuperacao(Usuario $u, string $token): void
    {
        $this->enviar($u->email, 'Redefinir sua senha', "Olá, {$this->limpo($u->nome)}.\n\nPara escolher uma nova senha, abra o link (vale 30 minutos e só pode ser usado uma vez):\n\n"
            . $this->link(self::CAMINHO_SENHA, $token) . "\n\nSe você não pediu, ignore esta mensagem: nada muda na sua conta.\n");
    }

    /** @param list<string> $logins */
    public function usuarios(string $email, array $logins): void
    {
        $this->enviar($email, 'Seu usuário de acesso', "Os usuários cadastrados para este e-mail:\n\n" . implode("\n", $logins)
            . "\n\nSe você não pediu, ignore esta mensagem.\n");
    }

    public function senhaAlterada(Usuario $u): void
    {
        $this->enviar($u->email, 'Sua senha foi alterada', "Olá, {$this->limpo($u->nome)}.\n\nA senha da sua conta ({$u->login}) acabou de ser alterada e as outras sessões foram encerradas.\n"
            . "Se não foi você, peça a um administrador para redefinir o acesso.\n");
    }

    public function convite(Usuario $u, string $token): void
    {
        $this->enviar($u->email, "Convite para {$this->nomeDoSite}", "Olá, {$this->limpo($u->nome)}.\n\nVocê foi convidado(a) para {$this->nomeDoSite} com o usuário {$u->login}. Para definir sua senha, abra o link (vale 72 horas e só pode ser usado uma vez):\n\n"
            . $this->link(self::CAMINHO_CONVITE, $token) . "\n\nSe não esperava este convite, ignore a mensagem.\n");
    }

    /** @throws \RuntimeException se o envio falhar (sem detalhes do servidor) */
    public function enviar(string $para, string $assunto, string $corpo): void
    {
        $this->transporte->enviar(new Mensagem($para, $assunto, $corpo));
    }

    private function limpo(string $nome): string
    {
        return trim(preg_replace('/[\r\n\0]+/', ' ', $nome) ?? '');
    }
}
