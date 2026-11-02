<?php

declare(strict_types=1);

namespace Pbl\Acesso;

/**
 * Token anti-CSRF. Para quem já entrou, é um HMAC do id da sessão (muda a cada login); para o formulário de entrar, que ainda
 * não tem sessão, é um HMAC da hora cheia (vale esta hora e a anterior). Comparação com `hash_equals`.
 */
final class Csrf
{
    private const JANELA_ENTRAR = 3600;

    /** @param (\Closure(): int)|null $relogio */
    public function __construct(private readonly string $segredo, private readonly ?\Closure $relogio = null)
    {
    }

    public function daSessao(string $idHash): string
    {
        return hash_hmac('sha256', 'sessao|' . $idHash, $this->segredo);
    }

    public function conferirSessao(string $idHash, string $token): bool
    {
        return hash_equals($this->daSessao($idHash), $token);
    }

    public function paraEntrar(): string
    {
        return $this->entrarEm(intdiv($this->agora(), self::JANELA_ENTRAR));
    }

    public function conferirEntrar(string $token): bool
    {
        $janela = intdiv($this->agora(), self::JANELA_ENTRAR);

        return hash_equals($this->entrarEm($janela), $token) || hash_equals($this->entrarEm($janela - 1), $token);
    }

    /**
     * Token do formulário público `$id`. As páginas são publicadas como arquivos, então o token não pode mudar a cada visita: é um
     * HMAC fixo do formulário. Ele prova que o envio veio de uma página gerada por este site (junto com a conferência de origem);
     * a defesa contra abuso é o limite por IP e o campo-armadilha, não o sigilo do token.
     */
    public function paraFormulario(int $id): string
    {
        return hash_hmac('sha256', 'formulario|' . $id, $this->segredo);
    }

    public function conferirFormulario(int $id, string $token): bool
    {
        return hash_equals($this->paraFormulario($id), $token);
    }

    private function entrarEm(int $janela): string
    {
        return hash_hmac('sha256', 'entrar|' . $janela, $this->segredo);
    }

    private function agora(): int
    {
        return $this->relogio !== null ? ($this->relogio)() : time();
    }
}
