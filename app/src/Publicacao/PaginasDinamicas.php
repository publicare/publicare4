<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Pbl\Conteudo\Site;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * As páginas que não são arquivos estáticos (ADR 0016): a busca (`/busca?q=`), a listagem paginada (`?pagina=N` sobre uma
 * página publicada) e a página 404 da pele (`erro404`). Todas usam o mesmo motor de moldes e escapam tudo na saída; o termo
 * da busca e o número da página chegam como variáveis do molde (`$q`, `$pagina`), nunca concatenados em HTML.
 */
final class PaginasDinamicas
{
    public const MAX_PAGINA = 1000;
    public const MAX_TERMO = 200;

    public function __construct(private readonly Servicos $servicos)
    {
    }

    /** `/busca`: renderiza o molde `busca` da pele sobre a página inicial do site. Null se a pele não tem o molde. */
    public function busca(Site $site, Request $req): ?Response
    {
        if (!$this->servicos->renderizador->temMolde($site, 'busca')) {
            return null;
        }
        $raiz = $site->objetoRaizId === null ? null : $this->servicos->objetos->porId($site->objetoRaizId);
        if ($raiz === null) {
            return null;
        }
        $q = $req->query->all()['q'] ?? null;
        $q = is_string($q) && mb_check_encoding($q, 'UTF-8') ? mb_substr(trim($q), 0, self::MAX_TERMO) : '';
        $html = $this->servicos->renderizador->renderizar($site, $raiz, 'busca', ['q' => $q, 'pagina' => $this->pagina($req)]);

        return $this->html($html, Response::HTTP_OK);
    }

    /** `?pagina=N` (N ≥ 2) sobre a página publicada `$url`: o mesmo molde da página, com `$pagina` preenchido. Null se não se aplica. */
    public function paginada(Site $site, string $url, Request $req): ?Response
    {
        $pagina = $this->pagina($req);
        if ($pagina < 2) {
            return null;
        }
        $objeto = $this->servicos->objetos->porUrl($site->id, $url);
        if ($objeto === null || !$objeto->visivel(gmdate('c'))) {
            return null;
        }

        return $this->html($this->servicos->renderizador->renderizar($site, $objeto, null, ['pagina' => $pagina]), Response::HTTP_OK);
    }

    /** O 404 da pele (`erro404`) sobre a página inicial; null se a pele não tem o molde (a `Aplicacao` cai no texto simples). */
    public function naoEncontrado(Site $site): ?Response
    {
        if (!$this->servicos->renderizador->temMolde($site, 'erro404')) {
            return null;
        }
        $raiz = $site->objetoRaizId === null ? null : $this->servicos->objetos->porId($site->objetoRaizId);
        if ($raiz === null) {
            return null;
        }
        try {
            return $this->html($this->servicos->renderizador->renderizar($site, $raiz, 'erro404'), Response::HTTP_NOT_FOUND);
        } catch (\Throwable) {
            return null; // molde quebrado: o 404 continua sendo 404, em texto simples
        }
    }

    private function pagina(Request $req): int
    {
        $p = $req->query->all()['pagina'] ?? null;

        return is_string($p) && preg_match('/^[0-9]{1,4}$/', $p) === 1 ? max(1, min(self::MAX_PAGINA, (int) $p)) : 1;
    }

    private function html(string $html, int $status): Response
    {
        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8', 'X-Content-Type-Options' => 'nosniff']);
    }
}
