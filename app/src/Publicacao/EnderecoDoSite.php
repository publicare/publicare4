<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Pbl\Conteudo\DominioRepositorio;

/**
 * O endereço absoluto de um site (`https://dominio-principal`), para `canonical`, `og:*`, `sitemap.xml` e `robots.txt`. O host vem
 * do cadastro (domínio principal), nunca da requisição: a página publicada é estática e vale para qualquer domínio que a sirva.
 * O esquema é `https`, salvo `PBL_ESQUEMA=http` (desenvolvimento). Sem domínio principal, devolve vazio (links relativos).
 */
final class EnderecoDoSite
{
    /** @var array<int, string> */
    private array $memoria = [];

    public function __construct(private readonly DominioRepositorio $dominios, private readonly string $esquema = 'https')
    {
    }

    public static function esquemaDoAmbiente(): string
    {
        return getenv('PBL_ESQUEMA') === 'http' ? 'http' : 'https';
    }

    /** Esquece o valor guardado (o domínio principal mudou nesta execução). */
    public function esquecer(int $siteId): void
    {
        unset($this->memoria[$siteId]);
    }

    /** `https://host` sem barra final, ou vazio se o site não tem domínio principal. */
    public function base(int $siteId): string
    {
        if (!isset($this->memoria[$siteId])) {
            $principal = $this->dominios->principalDe($siteId);
            $this->memoria[$siteId] = $principal === null ? '' : $this->esquema . '://' . $principal->host;
        }

        return $this->memoria[$siteId];
    }
}
