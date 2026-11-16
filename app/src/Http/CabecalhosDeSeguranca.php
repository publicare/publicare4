<?php

declare(strict_types=1);

namespace Pbl\Http;

use Pbl\Caminhos;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança das respostas (S-02). Duas políticas:
 *
 * - **Painel (`/_pbl`)**: montada por `Admin\Pagina::protegida` (CSP com `default-src 'none'`, `frame-ancestors 'none'`, `no-store`).
 * - **Site público (esta classe)**: as páginas publicadas são arquivos estáticos, então **não há nonce** possível; a CSP é
 *   estática: `script-src 'self'` (as peles só carregam scripts de arquivos próprios; nenhum `<script>` inline), `style-src`
 *   com `'unsafe-inline'` (concessão consciente: moldes e conteúdo usam `style=`), imagens do próprio site, `data:` e `https:`,
 *   sem `object`, formulário e base só do próprio site, e `frame-ancestors 'self'`.
 *
 * Cabeçalho que a resposta já traz (formulário, arquivo, assets de pele, painel) **nunca é sobrescrito**. HSTS: só em HTTPS, com
 * `max-age` de `dados/config/seguranca.json` (`hsts_max_age`, em segundos; 0 desliga; padrão 1 ano) e **sem** `includeSubDomains`
 * nem `preload`. `csp_publica_somente_relatorio: true` troca a CSP pública por `Content-Security-Policy-Report-Only` (para
 * testar uma pele antes de impor).
 */
final class CabecalhosDeSeguranca
{
    public const HSTS_PADRAO = 31536000;
    public const PERMISSOES = 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()';
    public const CSP_PUBLICA = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; media-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

    public function __construct(private readonly int $hstsMaxAge = self::HSTS_PADRAO, private readonly bool $somenteRelatorio = false)
    {
    }

    public static function dosCaminhos(Caminhos $caminhos): self
    {
        $arquivo = $caminhos->config() . '/seguranca.json';
        $dados = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;
        if (!is_array($dados)) {
            return new self();
        }
        $maxAge = $dados['hsts_max_age'] ?? self::HSTS_PADRAO;
        $maxAge = is_int($maxAge) && $maxAge >= 0 && $maxAge <= 63072000 ? $maxAge : self::HSTS_PADRAO;

        return new self($maxAge, ($dados['csp_publica_somente_relatorio'] ?? false) === true);
    }

    /** `$seguro`: a conexão é HTTPS (já considerando `Rede::seguro`, que respeita `confiar_proto`). */
    public function aplicar(Response $r, bool $seguro): Response
    {
        $c = $r->headers;
        $c->remove('Strict-Transport-Security');
        if ($seguro && $this->hstsMaxAge > 0) {
            $c->set('Strict-Transport-Security', 'max-age=' . $this->hstsMaxAge);
        }
        $this->seAusente($r, 'X-Content-Type-Options', 'nosniff');
        $this->seAusente($r, 'Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->seAusente($r, 'Permissions-Policy', self::PERMISSOES);
        if (!$c->has('Content-Security-Policy') && !$c->has('Content-Security-Policy-Report-Only')) {
            $c->set($this->somenteRelatorio ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', self::CSP_PUBLICA);
        }

        return $r;
    }

    private function seAusente(Response $r, string $nome, string $valor): void
    {
        if (!$r->headers->has($nome)) {
            $r->headers->set($nome, $valor);
        }
    }
}
