<?php

declare(strict_types=1);

namespace Pbl\Acesso;

use Pbl\Caminhos;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

/**
 * O que depende de como o site está exposto. Atrás de um proxy (Cloudflare, nginx), o IP real e o HTTPS chegam em cabeçalhos
 * que só podem ser confiados se o administrador disser que o proxy existe: `dados/config/rede.json`, por exemplo
 * `{"cabecalho_ip": "CF-Connecting-IP", "confiar_proto": true, "proxies_confiaveis": ["173.245.48.0/20"]}`. Sem o arquivo, vale só o
 * que o servidor enxerga.
 *
 * `proxies_confiaveis` (IPs ou CIDR) diz quais conexões podem informar o IP pelo `cabecalho_ip`: o cabeçalho só vale se o
 * `REMOTE_ADDR` estiver na lista; quem chega direto ao servidor não consegue forjá-lo. Lista vazia (ou sem nenhum item válido)
 * significa que nenhum proxy é confiável e o cabeçalho é ignorado. Sem a chave, o comportamento antigo continua (o cabeçalho vale
 * de qualquer origem) e o sistema avisa no log, uma vez, que isso é forjável.
 */
final class Rede
{
    /** @var array{cabecalho_ip?: string, confiar_proto?: bool} */
    private array $config = [];

    /** @var list<string>|null null = a chave `proxies_confiaveis` não foi declarada */
    private ?array $proxiesConfiaveis = null;

    private bool $avisouSemLista = false;

    public function __construct(Caminhos $caminhos)
    {
        $arquivo = $caminhos->config() . '/rede.json';
        if (is_file($arquivo)) {
            $dados = json_decode((string) file_get_contents($arquivo), true);
            if (is_array($dados)) {
                if (isset($dados['cabecalho_ip']) && is_string($dados['cabecalho_ip']) && preg_match('/^[A-Za-z0-9-]{1,64}$/D', $dados['cabecalho_ip']) === 1) {
                    $this->config['cabecalho_ip'] = $dados['cabecalho_ip'];
                }
                $this->config['confiar_proto'] = ($dados['confiar_proto'] ?? false) === true;
                if (array_key_exists('proxies_confiaveis', $dados)) {
                    $this->proxiesConfiaveis = self::itensValidos($dados['proxies_confiaveis']);
                }
            }
        }
    }

    /** @return list<string> só os IPs e CIDR bem formados; qualquer outra coisa vira lista vazia */
    private static function itensValidos(mixed $itens): array
    {
        if (!is_array($itens)) {
            return [];
        }
        $validos = [];
        foreach ($itens as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            $partes = explode('/', $item, 2);
            $ip = $partes[0];
            $v6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            if (!$v6 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }
            if (isset($partes[1]) && (preg_match('/^[0-9]{1,3}$/D', $partes[1]) !== 1 || (int) $partes[1] > ($v6 ? 128 : 32))) {
                continue;
            }
            $validos[] = $item;
        }

        return $validos;
    }

    public function ip(Request $requisicao): string
    {
        if (isset($this->config['cabecalho_ip']) && $this->conexaoPodeInformarOIp($requisicao)) {
            $valor = trim((string) $requisicao->headers->get($this->config['cabecalho_ip'], ''));
            if (filter_var($valor, FILTER_VALIDATE_IP) !== false) {
                return $valor;
            }
        }

        return $requisicao->server->getString('REMOTE_ADDR', '0.0.0.0');
    }

    private function conexaoPodeInformarOIp(Request $requisicao): bool
    {
        if ($this->proxiesConfiaveis === null) {
            if (!$this->avisouSemLista) {
                $this->avisouSemLista = true;
                error_log('PBL4: rede.json define "cabecalho_ip" sem "proxies_confiaveis"; qualquer cliente que alcance o servidor direto pode forjar o IP. Liste os proxies em "proxies_confiaveis".');
            }

            return true;
        }

        return $this->proxiesConfiaveis !== [] && IpUtils::checkIp($requisicao->server->getString('REMOTE_ADDR', ''), $this->proxiesConfiaveis);
    }

    /**
     * A porta que o visitante enxerga. Atrás de proxy de confiança (o HTTPS termina nele e o PHP recebe, por exemplo, na 80), vale
     * `X-Forwarded-Port` se vier, senão a padrão do esquema público; sem proxy declarado, a porta da própria conexão.
     */
    private function portaPublica(Request $requisicao, string $esquemaPublico): int
    {
        if (!($this->config['confiar_proto'] ?? false)) {
            return (int) $requisicao->getPort();
        }
        $informada = (string) $requisicao->headers->get('X-Forwarded-Port', '');
        if (ctype_digit($informada) && (int) $informada > 0 && (int) $informada < 65536) {
            return (int) $informada;
        }

        return $esquemaPublico === 'https' ? 443 : 80;
    }

    public function seguro(Request $requisicao): bool
    {
        return $requisicao->isSecure()
            || (($this->config['confiar_proto'] ?? false) && strtolower((string) $requisicao->headers->get('X-Forwarded-Proto', '')) === 'https');
    }

    /**
     * A origem do POST (cabeçalho `Origin`, ou `Referer` se não houver) é este mesmo site? Sem nenhum dos dois, o token CSRF decide.
     * Com `$soOHost`, só o host é comparado (esquema e porta ficam de fora).
     */
    public function origemConfere(Request $requisicao, bool $soOHost = false): bool
    {
        $origem = $requisicao->headers->get('Origin');
        if ($origem === null || $origem === '') {
            $origem = $requisicao->headers->get('Referer');
            if ($origem === null || $origem === '') {
                return true;
            }
        }
        $partes = parse_url($origem);
        if (!is_array($partes) || !isset($partes['host'], $partes['scheme'])) {
            return false; // inclui o `Origin: null` de contextos sem origem
        }
        if ($soOHost) {
            // Para o instalador, que roda antes de qualquer rede.json existir: atrás de um proxy que termina o HTTPS o PHP não sabe
            // o esquema nem a porta públicos. A prova de posse e o token CSRF seguem exigidos; aqui basta a origem ser este mesmo host.
            return strtolower($partes['host']) === strtolower($requisicao->getHost());
        }
        $esquemaPublico = $this->seguro($requisicao) ? 'https' : 'http';
        $porta = $partes['port'] ?? ($partes['scheme'] === 'https' ? 443 : 80);

        return strtolower($partes['scheme']) === $esquemaPublico
            && strtolower($partes['host']) === strtolower($requisicao->getHost())
            && $porta === $this->portaPublica($requisicao, $esquemaPublico);
    }
}
