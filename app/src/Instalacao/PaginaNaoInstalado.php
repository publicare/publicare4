<?php

declare(strict_types=1);

namespace Pbl\Instalacao;

use Symfony\Component\HttpFoundation\Response;

/**
 * Página da raiz (e de qualquer endereço) quando o PBL4 ainda não foi instalado: explica, em português e sem login, como instalar.
 * Marcação sem estilo nem script em linha (cabe na CSP do site). Não mostra versão, caminho de disco nem mensagem de exceção.
 */
final class PaginaNaoInstalado
{
    public static function resposta(): Response
    {
        $assistente = InstaladorWeb::PREFIXO;
        $posse = InstaladorWeb::ARQUIVO_DE_POSSE;
        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>Publicare 4: instalação pendente</title></head>'
            . '<body><main><h1>O Publicare 4 ainda não foi instalado</h1>'
            . '<p>Este site está no ar, mas falta fazer a instalação. Quem cuida do servidor pode instalar de três formas:</p>'
            . '<h2>Com o instalar.php (mais simples)</h2>'
            . '<p>Baixe o <code>instalar.php</code> em <code>atualizacoes.publicare.org/instalar.php</code>, envie para a pasta do site por FTP e abra-o no navegador.</p>'
            . '<h2>Pela linha de comando</h2>'
            . '<p>Na pasta do Publicare 4, no servidor, rode: <code>bin/pbl instalar</code></p>'
            . '<h2>Pelo navegador</h2>'
            . '<p>Abra o assistente em <a href="' . $assistente . '"><code>' . $assistente . '</code></a>. '
            . 'Por segurança, ele só funciona depois de você criar um arquivo vazio chamado <code>' . $posse . '</code> dentro da pasta <code>dados</code>.</p>'
            . '<p>Passo a passo: <code>docs/tecnica/instalacao.md</code> (na pasta do Publicare 4) e <code>docs/usuario/instalar.md</code>.</p>'
            . '<p>Se você é visitante deste site, volte mais tarde.</p>'
            . '</main></body></html>';

        return new Response($html, Response::HTTP_SERVICE_UNAVAILABLE, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'Retry-After' => '300',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
