<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

/**
 * HTML do menu a partir do snapshot (`MenuDoSite`), sem tocar no banco: `<ul>` aninhado sem JavaScript, o item da página
 * corrente com `class="ativo"` e `aria-current="page"`, os ancestrais com `class="ancestral"`. Rótulos e URLs saem escapados.
 */
final class MenuHtml
{
    /**
     * @param list<array<string, mixed>> $arvore
     * @param string $caminho `caminho` materializado do objeto corrente (`/1/5/9/`): de onde saem os ancestrais
     * @param string $urlInicio endereço do item de início: `/` ou a raiz da pele (ADR 0022)
     * @param string $inicio rótulo do item "página inicial" (`/`) no começo; vazio: sem ele
     */
    public static function gerar(array $arvore, int $profundidade, string $inicio, int $objetoId, string $caminho, ?int $raizId, string $urlInicio = '/'): string
    {
        /** @var array<int, int> $ancestrais */
        $ancestrais = array_flip(array_filter(array_map('intval', explode('/', $caminho))));
        $itens = '';
        if ($inicio !== '') {
            $ehRaiz = $raizId !== null && $raizId === $objetoId;
            $itens .= self::li($urlInicio, $inicio, false, false, $ehRaiz, false, '');
        }
        $itens .= self::itens($arvore, $profundidade, 1, $objetoId, $ancestrais);
        if ($itens === '') {
            return '';
        }

        return '<nav class="menu" aria-label="Menu principal"><ul>' . $itens . '</ul></nav>';
    }

    /**
     * @param list<array<string, mixed>> $itens
     * @param array<int, int> $ancestrais
     */
    private static function itens(array $itens, int $profundidade, int $nivel, int $objetoId, array $ancestrais): string
    {
        $html = '';
        foreach ($itens as $i) {
            $id = (int) $i['id'];
            /** @var list<array<string, mixed>> $subitens */
            $subitens = is_array($i['filhos'] ?? null) ? $i['filhos'] : [];
            $filhos = $nivel < $profundidade ? self::itens($subitens, $profundidade, $nivel + 1, $objetoId, $ancestrais) : '';
            $html .= self::li(
                (string) $i['url'],
                (string) $i['rotulo'],
                !empty($i['externo']),
                !empty($i['nova_aba']),
                $id === $objetoId,
                $id !== $objetoId && isset($ancestrais[$id]),
                $filhos === '' ? '' : '<ul>' . $filhos . '</ul>',
            );
        }

        return $html;
    }

    private static function li(string $url, string $rotulo, bool $externo, bool $novaAba, bool $ativo, bool $ancestral, string $sub): string
    {
        $classe = $ativo ? ' class="ativo"' : ($ancestral ? ' class="ancestral"' : '');
        $atributos = ' href="' . self::h($url) . '"' . ($ativo ? ' aria-current="page"' : '')
            . ($novaAba ? ' target="_blank"' : '') . ($externo || $novaAba ? ' rel="noopener noreferrer"' : '');

        return '<li' . $classe . '><a' . $atributos . '>' . self::h($rotulo) . '</a>' . $sub . '</li>';
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
