<?php

declare(strict_types=1);

namespace Pbl\Acesso;

use Doctrine\DBAL\Connection;

/**
 * "Modo elevado" (ADR 0011/0012, fase 5h): a confirmação de senha para uma ação sensível do painel, generalizada num
 * componente único (antes só a tela de Atualização tinha isso). Confirmar a senha uma vez abre uma janela curta
 * ({@see DURACAO}) em que outras ações sensíveis da mesma sessão não pedem a senha de novo; a janela mora na própria
 * sessão (coluna `elevado_ate`) e acaba sozinha quando a sessão acaba — logout, troca de senha (que encerra as
 * sessões da conta) ou expiração por tempo/inatividade.
 *
 * O limite de tentativas é o MESMO do login (por login e por IP); falha e bloqueio vão para a auditoria, nunca a
 * senha. Quem chama decide, com `$sempreExigir`, se a ação pede a senha mesmo com o modo já elevado (as telas de
 * atualizar/reverter, que trocam o código em execução, sempre pedem).
 */
final class ModoElevado
{
    /** Segundos que a confirmação de senha vale para outras ações sensíveis da mesma sessão. */
    public const DURACAO = 600;

    /** @param (\Closure(): int)|null $relogio */
    public function __construct(
        private readonly Connection $conexao,
        private readonly Autenticador $usuarios,
        private readonly LimiteDeTentativas $limite,
        private readonly Auditoria $auditoria,
        private readonly ?\Closure $relogio = null,
    ) {
    }

    /** A sessão confirmou a senha há pouco e ainda está dentro da janela? */
    public function ativo(SessaoAtiva $sessao): bool
    {
        $valor = $this->conexao->fetchOne('SELECT elevado_ate FROM sessao WHERE id_hash = ?', [$sessao->idHash]);

        return $valor !== false && $valor !== null && (int) $valor > $this->agora();
    }

    /**
     * Confere a senha para uma ação sensível, com o mesmo limite de tentativas do login. Se o modo já está elevado e
     * `$sempreExigir` é falso, não confere nada e deixa seguir. Toda confirmação certa (mesmo com `$sempreExigir`)
     * renova a janela, para a próxima ação sensível aproveitar.
     *
     * @return array{mensagem: string, status: int, retryAfter: int|null}|null null = pode seguir
     */
    public function confirmar(SessaoAtiva $sessao, string $senha, string $ip, string $prefixoAcao, bool $sempreExigir = false): ?array
    {
        if (!$sempreExigir && $this->ativo($sessao)) {
            return null;
        }
        $u = $sessao->usuario;
        $espera = $this->limite->bloqueadoPor($u->login, $ip);
        if ($espera !== null) {
            $this->auditoria->registrar($prefixoAcao . '.bloqueado', 'usuario:' . $u->id, $u->id, null, [], $this->limite->hashDoIp($ip));

            return ['mensagem' => 'Muitas tentativas de senha. Aguarde alguns minutos e tente de novo.', 'status' => 429, 'retryAfter' => $espera];
        }
        $confere = $this->usuarios->autenticar($u->login, mb_substr($senha, 0, 1024));
        if ($confere === null || $confere->id !== $u->id) {
            $this->limite->registrarFalha($u->login, $ip);
            $this->auditoria->registrar($prefixoAcao . '.senha_errada', 'usuario:' . $u->id, $u->id, null, [], $this->limite->hashDoIp($ip));

            return ['mensagem' => 'Senha incorreta.', 'status' => 401, 'retryAfter' => null];
        }
        $this->limite->limparLogin($u->login);
        $this->elevar($sessao);

        return null;
    }

    /**
     * Abre o modo elevado sem conferir senha. Só para os testes montarem uma sessão já elevada sem gastar uma
     * tentativa de verdade no `LimiteDeTentativas` (que teria o efeito colateral de limpar o contador do login);
     * quem testa a própria confirmação usa {@see confirmar}.
     */
    public function abrirParaTestes(SessaoAtiva $sessao): void
    {
        $this->elevar($sessao);
    }

    private function elevar(SessaoAtiva $sessao): void
    {
        $this->conexao->update('sessao', ['elevado_ate' => $this->agora() + self::DURACAO], ['id_hash' => $sessao->idHash]);
    }

    private function agora(): int
    {
        return $this->relogio !== null ? ($this->relogio)() : time();
    }
}
