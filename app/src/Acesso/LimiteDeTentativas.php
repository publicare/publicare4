<?php

declare(strict_types=1);

namespace Pbl\Acesso;

use Doctrine\DBAL\Connection;

/**
 * Limite de tentativas de login **no servidor** (o do PBL3 ficava na sessão e bastava descartar o cookie). Conta falhas por login
 * e por IP numa janela de 15 minutos; login e IP são guardados só como HMAC.
 */
final class LimiteDeTentativas
{
    public const JANELA = 900;
    public const POR_LOGIN = 5;
    public const POR_IP = 30;

    /** @param (\Closure(): int)|null $relogio */
    public function __construct(private readonly Connection $conexao, private readonly string $segredo, private readonly ?\Closure $relogio = null)
    {
    }

    /** @return int|null segundos até poder tentar de novo, ou null se pode tentar */
    public function bloqueadoPor(string $login, string $ip): ?int
    {
        $espera = [];
        foreach ([[$this->chaveLogin($login), self::POR_LOGIN], [$this->chaveIp($ip), self::POR_IP]] as [$chave, $maximo]) {
            $agora = $this->agora();
            $quando = array_map('intval', $this->conexao->fetchFirstColumn(
                'SELECT quando FROM tentativa_login WHERE chave_hash = ? AND quando > ? ORDER BY quando ASC',
                [$chave, $agora - self::JANELA],
            ));
            if (count($quando) >= $maximo) {
                // libera quando a falha mais antiga que ainda conta sair da janela, deixando abaixo do máximo
                $espera[] = max(1, $quando[count($quando) - $maximo] + self::JANELA - $agora);
            }
        }

        return $espera === [] ? null : max($espera);
    }

    public function registrarFalha(string $login, string $ip): void
    {
        $agora = $this->agora();
        $this->conexao->executeStatement('DELETE FROM tentativa_login WHERE quando < ?', [$agora - 86400]);
        foreach ([$this->chaveLogin($login), $this->chaveIp($ip)] as $chave) {
            $this->conexao->insert('tentativa_login', ['chave_hash' => $chave, 'quando' => $agora]);
        }
    }

    /** Depois de um login certo, o contador daquele login recomeça (o do IP continua). */
    public function limparLogin(string $login): void
    {
        $this->conexao->delete('tentativa_login', ['chave_hash' => $this->chaveLogin($login)]);
    }

    public function hashDoIp(string $ip): string
    {
        return $this->chaveIp($ip);
    }

    private function chaveLogin(string $login): string
    {
        return hash_hmac('sha256', 'login|' . mb_strtolower(trim($login)), $this->segredo);
    }

    private function chaveIp(string $ip): string
    {
        return hash_hmac('sha256', 'ip|' . $ip, $this->segredo);
    }

    private function agora(): int
    {
        return $this->relogio !== null ? ($this->relogio)() : time();
    }
}
