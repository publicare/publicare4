<?php

declare(strict_types=1);

namespace Pbl;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entrada HTTP. Fase 0: resolve host → site, URL → página publicada no cache. A URL nunca vira
 * caminho de arquivo sem passar pela validação de `Caminhos::pagina()` (sem traversal).
 */
final class Aplicacao
{
    public function __construct(public readonly Caminhos $caminhos, private readonly ?Servicos $servicos = null)
    {
    }

    public function tratar(Request $requisicao): Response
    {
        try {
            $servicos = $this->servicos ?? Servicos::doBancoInstalado($this->caminhos);
        } catch (\RuntimeException) {
            return $this->texto('PBL4 não está instalado', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $site = $servicos->sites->porHost($requisicao->getHost());
        if ($site === null) {
            return $this->texto('Domínio não configurado', Response::HTTP_NOT_FOUND);
        }

        $url = trim(rawurldecode($requisicao->getPathInfo()), '/');
        try {
            $arquivo = $this->caminhos->pagina($site->id, $url);
        } catch (\InvalidArgumentException) {
            return $this->texto('Não encontrado', Response::HTTP_NOT_FOUND);
        }
        if (!is_file($arquivo)) {
            return $this->texto('Não encontrado', Response::HTTP_NOT_FOUND);
        }

        return new Response((string) file_get_contents($arquivo), Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function texto(string $mensagem, int $status): Response
    {
        return new Response($mensagem, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
