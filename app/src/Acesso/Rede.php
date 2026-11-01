<?php

declare(strict_types=1);

namespace Pbl\Acesso;

use Pbl\Caminhos;
use Symfony\Component\HttpFoundation\Request;

/**
 * O que depende de como o site está exposto. Atrás de um proxy (Cloudflare, nginx), o IP real e o HTTPS chegam em cabeçalhos
 * que só podem ser confiados se o administrador disser que o proxy existe: `dados/config/rede.json`, por exemplo
 * `{"cabecalho_ip": "CF-Connecting-IP", "confiar_proto": true}`. Sem o arquivo, vale só o que o servidor enxerga.
 */
final class Rede
{
    /** @var array{cabecalho_ip?: string, confiar_proto?: bool} */
    private array $config = [];

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
            }
        }
    }

    public function ip(Request $requisicao): string
    {
        if (isset($this->config['cabecalho_ip'])) {
            $valor = trim((string) $requisicao->headers->get($this->config['cabecalho_ip'], ''));
            if (filter_var($valor, FILTER_VALIDATE_IP) !== false) {
                return $valor;
            }
        }

        return $requisicao->server->getString('REMOTE_ADDR', '0.0.0.0');
    }

    public function seguro(Request $requisicao): bool
    {
        return $requisicao->isSecure()
            || (($this->config['confiar_proto'] ?? false) && strtolower((string) $requisicao->headers->get('X-Forwarded-Proto', '')) === 'https');
    }

    /** A origem do POST (cabeçalho `Origin`, ou `Referer` se não houver) é este mesmo site? Sem nenhum dos dois, o token CSRF decide. */
    public function origemConfere(Request $requisicao): bool
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
        $porta = $partes['port'] ?? ($partes['scheme'] === 'https' ? 443 : 80);

        return strtolower($partes['host']) === strtolower($requisicao->getHost()) && $porta === $requisicao->getPort();
    }
}
