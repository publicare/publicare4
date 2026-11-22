<?php

declare(strict_types=1);

namespace Pbl\Testes\Texto;

/** Conta palavras de microcopy de uma tela do painel por categoria (só o `<main>`; o menu e o rodapé são iguais em todas). */
final class Medidor
{
    /** Categorias que formam o "texto corrido" (a meta de corte de 40% vale para a soma delas). */
    public const CORRIDO = ['subtitulo', 'ajuda', 'aviso', 'vazio', 'paragrafo'];

    /** @var array<string, string> categoria => XPath relativo ao elemento (testado do mais próximo ao mais distante) */
    private const REGRAS = [
        'ajuda' => 'contains(concat(" ",normalize-space(@class)," ")," pbl-ajuda-texto ") or contains(concat(" ",normalize-space(@class)," ")," pbl-toggle-ajuda ") or contains(concat(" ",normalize-space(@class)," ")," form-text ")',
        'aviso' => 'contains(concat(" ",normalize-space(@class)," ")," alert ")',
        'vazio' => 'contains(concat(" ",normalize-space(@class)," ")," pbl-vazio ")',
        'subtitulo' => 'contains(concat(" ",normalize-space(@class)," ")," text-body-secondary ") or contains(concat(" ",normalize-space(@class)," ")," text-muted ") or contains(concat(" ",normalize-space(@class)," ")," lead ") or contains(concat(" ",normalize-space(@class)," ")," pbl-subtitulo ")',
        'botao' => 'self::button or (self::a and contains(concat(" ",normalize-space(@class)," ")," btn ")) or (self::input and (@type="submit" or @type="button"))',
        'rotulo' => 'self::label or self::legend or self::th or self::h1 or self::h2 or self::h3 or self::h4 or self::summary',
        'paragrafo' => 'self::p',
    ];

    /** @return array<string, int> categoria => palavras (inclui `corrido` e `total`) */
    public static function contar(string $html): array
    {
        $porCategoria = array_fill_keys([...array_keys(self::REGRAS), 'outros'], 0);
        foreach (self::trechos($html) as [$categoria, $texto]) {
            $porCategoria[$categoria] += self::palavras($texto);
        }
        $porCategoria['corrido'] = array_sum(array_intersect_key($porCategoria, array_flip(self::CORRIDO)));
        $porCategoria['total'] = array_sum(array_diff_key($porCategoria, ['corrido' => 1]));

        return $porCategoria;
    }

    /**
     * Texto visível de cada ajuda de campo (`.pbl-ajuda-texto`), para o limite de tamanho.
     *
     * @return list<array{id: string, texto: string}>
     */
    public static function ajudas(string $html): array
    {
        $xp = self::xpath($html);
        $saida = [];
        foreach ($xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," pbl-ajuda-texto ")]') ?: [] as $no) {
            \assert($no instanceof \DOMElement);
            $saida[] = ['id' => $no->getAttribute('id'), 'texto' => self::normalizar($no->textContent)];
        }

        return $saida;
    }

    /**
     * Subtítulos de tela: o primeiro parágrafo depois do `<h1>` e os parágrafos do cabeçalho de seção.
     *
     * @return list<string>
     */
    public static function subtitulos(string $html): array
    {
        $xp = self::xpath($html);
        $saida = [];
        foreach ($xp->query('//main//h1/following-sibling::*[1][self::p] | //main//*[contains(concat(" ",normalize-space(@class)," ")," pbl-secao-cab ")]/p') ?: [] as $no) {
            $t = $no instanceof \DOMNode ? self::normalizar($no->textContent) : '';
            if ($t !== '') {
                $saida[] = $t;
            }
        }

        return $saida;
    }

    /** Texto visível do `<main>`, em uma linha (para procurar padrões proibidos). */
    public static function textoVisivel(string $html): string
    {
        return implode(' ', array_map(static fn (array $t): string => $t[1], self::trechos($html)));
    }

    /**
     * @return list<array{0: string, 1: string}> [categoria, texto]
     */
    private static function trechos(string $html): array
    {
        $xp = self::xpath($html);
        $achados = $xp->query('//main') ?: null;
        $raiz = $achados?->item(0) ?? ($xp->query('//body') ?: null)?->item(0);
        if (!$raiz instanceof \DOMNode) {
            return [];
        }
        $saida = [];
        foreach ($xp->query('.//text()[normalize-space()]', $raiz) ?: [] as $no) {
            \assert($no instanceof \DOMNode);
            $texto = self::normalizar($no->nodeValue ?? '');
            if ($texto === '' || self::dentroDe($no, ['script', 'style', 'template'])) {
                continue;
            }
            $saida[] = [self::categoria($xp, $no), $texto];
        }
        // <input type=submit value="…"> não tem nó de texto.
        foreach ($xp->query('.//input[@type="submit" or @type="button"][@value]', $raiz) ?: [] as $in) {
            \assert($in instanceof \DOMElement);
            $saida[] = ['botao', self::normalizar($in->getAttribute('value'))];
        }

        return $saida;
    }

    private static function categoria(\DOMXPath $xp, \DOMNode $no): string
    {
        for ($e = $no->parentNode; $e instanceof \DOMElement; $e = $e->parentNode) {
            if (strtolower($e->nodeName) === 'main') {
                break;
            }
            foreach (self::REGRAS as $categoria => $teste) {
                if ($xp->evaluate('boolean(self::*[' . $teste . '])', $e) === true) {
                    return $categoria;
                }
            }
        }

        return 'outros';
    }

    /** @param list<string> $nomes */
    private static function dentroDe(\DOMNode $no, array $nomes): bool
    {
        for ($e = $no->parentNode; $e !== null; $e = $e->parentNode) {
            if (in_array(strtolower($e->nodeName), $nomes, true)) {
                return true;
            }
        }

        return false;
    }

    private static function xpath(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        $anterior = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return new \DOMXPath($doc);
    }

    private static function normalizar(string $texto): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    private static function palavras(string $texto): int
    {
        return preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\-\'’\/.,:@]*/u', $texto) ?: 0;
    }
}
