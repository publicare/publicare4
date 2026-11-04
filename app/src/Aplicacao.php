<?php

declare(strict_types=1);

namespace Pbl;

use Pbl\Acesso\Rede;
use Pbl\Admin\Painel;
use Pbl\Arquivos\ArquivoController;
use Pbl\Atualizacao\Manutencao;
use Pbl\Conteudo\Dominio;
use Pbl\Formularios\EnvioDeFormulario;
use Pbl\Instalacao\Instalador;
use Pbl\Conteudo\Site;
use Pbl\Instalacao\InstaladorWeb;
use Pbl\Publicacao\PaginasDinamicas;
use Pbl\Publicacao\SitemapERobots;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entrada HTTP. Ordem de resolução (fluxo 1): domínio → site; domínio de redirecionamento; página publicada no cache;
 * histórico de URLs; regras manuais de redirecionamento; 404. A URL nunca vira caminho de arquivo sem passar pela validação
 * de `Caminhos::pagina()` (sem traversal). Todo redirecionamento vai para um caminho do próprio site, para o domínio
 * principal cadastrado, ou para um destino `https://` que um administrador cadastrou: nunca para texto da requisição.
 */
final class Aplicacao
{
    private const MAX_CAMINHO = 500;

    public function __construct(public readonly Caminhos $caminhos, private readonly ?Servicos $servicos = null)
    {
    }

    public function tratar(Request $requisicao): Response
    {
        if (Manutencao::ativa($this->caminhos)) {
            $r = new Response(
                '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Site em manutenção</title></head>'
                . '<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:20vh auto;padding:0 1rem;text-align:center"><h1>Site em manutenção</h1><p>Site em manutenção: voltamos em instantes.</p></body></html>',
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'],
            );
            $r->headers->set('Retry-After', '60');

            return $r;
        }
        if ($this->servicos === null && InstaladorWeb::atende($requisicao) && !(new Instalador($this->caminhos))->instalado()) {
            return (new InstaladorWeb($this->caminhos))->tratar($requisicao);
        }
        try {
            $servicos = $this->servicos ?? Servicos::doBancoInstalado($this->caminhos);
        } catch (\RuntimeException) {
            return $this->texto('PBL4 não está instalado', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $host = $requisicao->getHost();
        } catch (SuspiciousOperationException) {
            return $this->texto('Domínio inválido', Response::HTTP_BAD_REQUEST);
        }
        $dominio = $servicos->dominios->porHost($host);
        $site = $dominio === null ? null : $servicos->sites->porId($dominio->siteId);
        if ($dominio === null || $site === null) {
            return $this->texto('Domínio não configurado', Response::HTTP_NOT_FOUND);
        }
        $principal = $servicos->dominios->principalDe($site->id);
        // Host canônico do site quando o domínio pedido não é ele (null se o pedido já é o principal ou não há principal).
        $canonico = $principal !== null && $principal->host !== $dominio->host ? $principal->host : null;

        if ($dominio->modo === Dominio::REDIRECIONAR && $canonico !== null) {
            return new RedirectResponse($this->noDominio($requisicao, $canonico), Response::HTTP_MOVED_PERMANENTLY);
        }

        if (EnvioDeFormulario::atende($requisicao)) {
            // Envio público de formulário (sem sessão): só no domínio do site dono do formulário.
            return (new EnvioDeFormulario($servicos, new Rede($this->caminhos), $site->id))->tratar($requisicao);
        }
        if (ArquivoController::atende($requisicao)) {
            // Entrega de arquivo (sem sessão): só o arquivo de um objeto publicado e vigente deste site (fluxo 7).
            return (new ArquivoController($servicos, $site->id))->tratar($requisicao);
        }
        if (\Pbl\Publicacao\AssetsDePele::atende($requisicao)) {
            // CSS/JS/imagens das peles (ADR 0022), sem sessão: só arquivos de dados/peles/<pele>/assets.
            return (new \Pbl\Publicacao\AssetsDePele($this->caminhos))->tratar($requisicao);
        }
        if (Painel::atende($requisicao)) {
            // Administração (entrar, sair…). Só aqui o segredo do site e as sessões entram em jogo: a página pública não depende deles.
            return (new Painel($servicos, new Rede($this->caminhos), $servicos->csrf(), $servicos->sessoes(), $servicos->limiteDeTentativas()))->tratar($requisicao);
        }

        $pedido = rawurldecode($requisicao->getPathInfo());
        if (strlen($pedido) > self::MAX_CAMINHO || !mb_check_encoding($pedido, 'UTF-8')) {
            return $this->texto('Não encontrado', Response::HTTP_NOT_FOUND);
        }
        $url = trim($pedido, '/');
        try {
            $arquivo = $this->caminhos->pagina($site->id, $url);
        } catch (\InvalidArgumentException) {
            $arquivo = null; // endereço que nunca é URL de conteúdo (maiúsculas, .php…): ainda pode haver regra manual
        }

        if ($arquivo === null || !is_file($arquivo)) {
            if ($url === 'busca') {
                $busca = (new PaginasDinamicas($servicos))->busca($site, $requisicao);
                if ($busca !== null) {
                    return $busca;
                }
            }
            if ($arquivo !== null) {
                $atual = $servicos->objetos->urlAtualDoHistorico($site->id, $url);
                if ($atual !== null) {
                    return new RedirectResponse($this->comConsulta($requisicao, '/' . $atual), Response::HTTP_MOVED_PERMANENTLY);
                }
            }
            $regra = $servicos->redirecionamentos->resolver($site->id, $pedido);
            if ($regra !== null) {
                return new RedirectResponse($this->comConsulta($requisicao, $regra['destino']), $regra['codigo']);
            }

            if ($arquivo === null && SitemapERobots::atende($pedido) && in_array($requisicao->getMethod(), ['GET', 'HEAD'], true)) {
                $r = (new SitemapERobots($servicos))->tratar($site, $pedido);
                if ($r !== null) {
                    return $r;
                }
            }

            return $this->naoEncontrado($servicos, $site);
        }
        if (is_file($arquivo . '.expira') && trim((string) file_get_contents($arquivo . '.expira')) < gmdate('c')) {
            return $this->naoEncontrado($servicos, $site);
        }
        if ($requisicao->query->has('pagina')) {
            $paginada = (new PaginasDinamicas($servicos))->paginada($site, $url, $requisicao);
            if ($paginada !== null) {
                return $paginada;
            }
        }

        $cabecalhos = ['Content-Type' => 'text/html; charset=utf-8', 'X-Content-Type-Options' => 'nosniff'];
        if ($dominio->modo === Dominio::SERVIR && $canonico !== null) {
            // O mesmo conteúdo em outro endereço: avisa aos buscadores qual é o canônico.
            $cabecalhos['Link'] = '<' . $this->noDominio($requisicao, $canonico, false) . '>; rel="canonical"';
        }

        return new Response((string) file_get_contents($arquivo), Response::HTTP_OK, $cabecalhos);
    }

    /** 404 de página pública: o molde `erro404` da pele, se houver; senão o texto simples de sempre. */
    private function naoEncontrado(Servicos $servicos, Site $site): Response
    {
        return (new PaginasDinamicas($servicos))->naoEncontrado($site) ?? $this->texto('Não encontrado', Response::HTTP_NOT_FOUND);
    }

    /** Mesmo caminho (e, se `$comConsulta`, a mesma query string) no domínio dado; o host vem do cadastro, nunca da requisição. */
    private function noDominio(Request $requisicao, string $host, bool $comConsulta = true): string
    {
        $porta = $requisicao->getPort();
        $sufixo = in_array($porta, [80, 443], true) ? '' : ':' . $porta;
        $consulta = $comConsulta ? $requisicao->getQueryString() : null;

        return $requisicao->getScheme() . '://' . $host . $sufixo . $requisicao->getPathInfo() . ($consulta === null || $consulta === '' ? '' : '?' . $consulta);
    }

    /** Acrescenta a query string do pedido ao destino, antes do `#âncora`, se o destino ainda não tiver uma. */
    private function comConsulta(Request $requisicao, string $destino): string
    {
        $consulta = $requisicao->getQueryString();
        $posicao = strpos($destino, '#');
        $base = $posicao === false ? $destino : substr($destino, 0, $posicao);
        $ancora = $posicao === false ? '' : substr($destino, $posicao);
        if ($consulta === null || $consulta === '' || str_contains($base, '?')) {
            return $destino;
        }

        return $base . '?' . $consulta . $ancora;
    }

    private function texto(string $mensagem, int $status): Response
    {
        return new Response($mensagem, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
