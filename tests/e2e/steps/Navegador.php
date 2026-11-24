<?php

declare(strict_types=1);

namespace Pbl\Testes\E2E;

use Pbl\Aplicacao;
use Pbl\Caminhos;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Passos reutilizáveis dos cenários: um "navegador" que fala HTTP com o núcleo e extrai links/recursos do HTML. */
final class Navegador
{
    public function __construct(private readonly Caminhos $c, private readonly string $host)
    {
    }

    public function abrir(string $caminho): Response
    {
        return (new Aplicacao($this->c))->tratar(Request::create('https://' . $this->host . $caminho));
    }

    /** @return list<string> caminhos internos (com query) de <a href>, sem âncoras, esquemas e áreas de sistema. */
    public static function linksInternos(string $html): array
    {
        preg_match_all('#<a\b[^>]*\shref="([^"]*)"#', $html, $m);
        $saida = [];
        foreach ($m[1] as $href) {
            $href = html_entity_decode($href);
            if ($href === '' || $href[0] === '#' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) === 1 || str_starts_with($href, '//')) {
                continue;
            }
            $caminho = (string) parse_url($href, PHP_URL_PATH);
            if ($caminho === '' || str_starts_with($caminho, '/_pbl/') || str_starts_with($caminho, '/admin')) {
                continue;
            }
            $saida[] = $caminho;
        }

        return array_values(array_unique($saida));
    }

    /** @return list<string> URLs absolutas (http/https/protocolo-relativas) usadas para CARREGAR recursos. */
    public static function recursosExternos(string $html): array
    {
        $achados = [];
        preg_match_all('#<(?:script|img|source|iframe|link)\b[^>]*\s(?:src|href|srcset)="((?:https?:)?//[^"]+)"#i', $html, $m, PREG_SET_ORDER);
        foreach ($m as $item) {
            if (!preg_match('#<link\b[^>]*rel="(?:canonical|alternate|me|author)"#i', $item[0])) {
                $achados[] = $item[1];
            }
        }
        preg_match_all('#url\(\s*[\'"]?(?:https?:)?//[^)]+#i', $html, $css);

        return array_merge($achados, $css[0]);
    }
}
