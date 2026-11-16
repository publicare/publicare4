<?php

declare(strict_types=1);

namespace Pbl\Acesso;

use Doctrine\DBAL\Connection;

/**
 * Limite de tentativas de login **no servidor** (o do PBL3 ficava na sessão e bastava descartar o cookie).
 *
 * Três contadores independentes, todos guardados só como HMAC e numa janela de 15 minutos:
 * - o **par** login+origem (5 falhas), que tranca quem erra sem trancar a conta para os outros;
 * - o **login** de qualquer origem (20 falhas), teto mais alto contra adivinhação distribuída, que não deixa um estranho
 *   trancar a conta com poucas tentativas (S-05);
 * - a **origem** (30 falhas; IPv6 agrupado por /64).
 *
 * Passado o limite, o atraso é **progressivo**: 30 s e dobra a cada falha a mais, até 15 min, contado da última falha. Cada
 * "espaço" ({@see ESPACO_LOGIN}, {@see ESPACO_ELEVADO}) tem os seus contadores: errar a senha numa ação sensível não gasta o
 * contador do login. O retorno é só o número de segundos, sem dizer qual contador travou nem se a conta existe.
 */
final class LimiteDeTentativas
{
    public const JANELA = 900;
    public const POR_PAR = 5;
    public const POR_LOGIN = 20;
    public const POR_IP = 30;
    public const ATRASO_BASE = 30;
    public const ATRASO_MAXIMO = 900;
    public const ESPACO_LOGIN = 'login';
    public const ESPACO_ELEVADO = 'elevado';

    /** @param (\Closure(): int)|null $relogio */
    public function __construct(private readonly Connection $conexao, private readonly string $segredo, private readonly ?\Closure $relogio = null)
    {
    }

    /** @return int|null segundos até poder tentar de novo, ou null se pode tentar */
    public function bloqueadoPor(string $login, string $ip, string $espaco = self::ESPACO_LOGIN): ?int
    {
        $agora = $this->agora();
        $espera = [];
        foreach ($this->contadores($login, $ip, $espaco) as [$chave, $maximo, $marcas]) {
            $desde = $agora - self::JANELA;
            foreach ($marcas as $marca) {
                $desde = max($desde, (int) $this->conexao->fetchOne('SELECT MAX(quando) FROM tentativa_login WHERE chave_hash = ?', [$marca]));
            }
            $quando = array_map('intval', $this->conexao->fetchFirstColumn(
                'SELECT quando FROM tentativa_login WHERE chave_hash = ? AND quando > ? ORDER BY quando ASC',
                [$chave, $desde],
            ));
            $n = count($quando);
            if ($n >= $maximo) {
                $atraso = min(self::ATRASO_MAXIMO, self::ATRASO_BASE * (2 ** min($n - $maximo, 10)));
                $falta = $quando[$n - 1] + $atraso - $agora;
                if ($falta > 0) {
                    $espera[] = $falta;
                }
            }
        }

        return $espera === [] ? null : max($espera);
    }

    /** Deve ser chamado **antes** de conferir a senha (pedidos em paralelo não escapam do contador); {@see perdoar} desfaz no acerto. */
    public function registrarFalha(string $login, string $ip, string $espaco = self::ESPACO_LOGIN): void
    {
        $agora = $this->agora();
        $this->conexao->executeStatement('DELETE FROM tentativa_login WHERE quando < ?', [$agora - 86400]);
        foreach ($this->contadores($login, $ip, $espaco) as [$chave]) {
            $this->conexao->insert('tentativa_login', ['chave_hash' => $chave, 'quando' => $agora]);
        }
    }

    /** Depois de um acerto: o contador do par e o do login recomeçam e a tentativa registrada antes não pesa no da origem. */
    public function perdoar(string $login, string $ip, string $espaco = self::ESPACO_LOGIN): void
    {
        $this->conexao->delete('tentativa_login', ['chave_hash' => $this->chavePar($login, $ip, $espaco)]);
        $this->conexao->delete('tentativa_login', ['chave_hash' => $this->chaveLogin($login, $espaco)]);
        $this->conexao->executeStatement(
            'DELETE FROM tentativa_login WHERE id = (SELECT MAX(id) FROM tentativa_login WHERE chave_hash = ?)',
            [$this->chaveIp($ip, $espaco)],
        );
    }

    /**
     * Destrava um login, de qualquer origem, nos dois espaços (`bin/pbl conta-destravar`). Grava uma marca em vez de apagar
     * linhas: os contadores por par dependem do IP, que quem destrava não sabe. Falhas anteriores à marca deixam de contar.
     */
    public function liberarLogin(string $login): void
    {
        foreach ([self::ESPACO_LOGIN, self::ESPACO_ELEVADO] as $espaco) {
            $this->conexao->insert('tentativa_login', ['chave_hash' => $this->marcaLogin($login, $espaco), 'quando' => $this->agora()]);
        }
    }

    /** Destrava uma origem (`bin/pbl conta-destravar --ip`), com a mesma marca de {@see liberarLogin}. */
    public function liberarIp(string $ip): void
    {
        foreach ([self::ESPACO_LOGIN, self::ESPACO_ELEVADO] as $espaco) {
            $this->conexao->insert('tentativa_login', ['chave_hash' => $this->marcaIp($ip, $espaco), 'quando' => $this->agora()]);
        }
    }

    public function hashDoIp(string $ip): string
    {
        return hash_hmac('sha256', 'ip|' . $ip, $this->segredo);
    }

    /** @return list<array{string, int, list<string>}> contador, máximo e marcas de liberação que valem para ele */
    private function contadores(string $login, string $ip, string $espaco): array
    {
        $porLogin = $this->marcaLogin($login, $espaco);
        $porIp = $this->marcaIp($ip, $espaco);

        return [
            [$this->chavePar($login, $ip, $espaco), self::POR_PAR, [$porLogin, $porIp]],
            [$this->chaveLogin($login, $espaco), self::POR_LOGIN, [$porLogin]],
            [$this->chaveIp($ip, $espaco), self::POR_IP, [$porIp]],
        ];
    }

    private function marcaLogin(string $login, string $espaco): string
    {
        return hash_hmac('sha256', $espaco . '-liberado|' . $this->normalizar($login), $this->segredo);
    }

    private function marcaIp(string $ip, string $espaco): string
    {
        return hash_hmac('sha256', $espaco . '-ip-liberado|' . $this->origem($ip), $this->segredo);
    }

    private function chavePar(string $login, string $ip, string $espaco): string
    {
        return hash_hmac('sha256', $espaco . '-par|' . $this->normalizar($login) . '|' . $this->origem($ip), $this->segredo);
    }

    private function chaveLogin(string $login, string $espaco): string
    {
        return hash_hmac('sha256', $espaco . '|' . $this->normalizar($login), $this->segredo);
    }

    private function chaveIp(string $ip, string $espaco): string
    {
        return hash_hmac('sha256', $espaco . '-ip|' . $this->origem($ip), $this->segredo);
    }

    private function normalizar(string $login): string
    {
        return mb_strtolower(trim($login));
    }

    /** IPv6 conta por /64 (quem tem uma rede inteira não ganha tentativas novas trocando o final do endereço). */
    private function origem(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return $ip;
        }

        return (string) inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    private function agora(): int
    {
        return $this->relogio !== null ? ($this->relogio)() : time();
    }
}
