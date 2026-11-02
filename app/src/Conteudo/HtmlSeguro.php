<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

/**
 * Limpeza de HTML por lista de permissões (ADR 0012 §9). Usada na gravação de propriedades do tipo `html`.
 *
 * Não filtra o texto por expressão regular: o HTML é lido por um parser de verdade (`DOMDocument`) e a saída é **reescrita do
 * zero** a partir da árvore, só com os elementos e atributos da lista, texto e valores sempre escapados. O que o parser entender
 * de um jeito e o navegador de outro resulta, no pior caso, em texto escapado, nunca em marcação nova.
 *
 * - Elementos fora da lista são removidos mantendo o texto de dentro; os de conteúdo perigoso (`script`, `style`, `iframe`,
 *   `object`, `embed`, `svg`, `math`, `template`, `form`…) somem com tudo o que têm dentro. Comentários, instruções e CDATA somem.
 * - Atributos: só os da lista para aquele elemento. Nenhum `style`, `class`, `id` nem `on*`.
 * - Endereços (`href`, `src`): esquemas `http`, `https`, `mailto` e `tel` em links, `http` e `https` em imagens, ou endereço
 *   relativo. Espaços e caracteres de controle dentro do endereço são removidos antes da conferência (como o navegador faz), então
 *   `java\tscript:` não escapa. `javascript:`, `vbscript:` e `data:` nunca passam.
 * - Todo link ganha `rel="noopener noreferrer"`.
 * - Idempotente: limpar um HTML já limpo devolve o mesmo texto.
 */
final class HtmlSeguro
{
    /** Tamanho máximo aceito na entrada, em bytes (a administração limita bem abaixo disso). */
    public const MAX_BYTES = 1_000_000;
    private const PROFUNDIDADE_MAXIMA = 40;
    private const ESQUEMAS_LINK = ['http', 'https', 'mailto', 'tel'];
    private const ESQUEMAS_IMAGEM = ['http', 'https'];

    /** Elementos permitidos => atributos permitidos (além dos globais). */
    private const ELEMENTOS = [
        'p' => [], 'br' => [], 'hr' => [], 'div' => [], 'span' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'small' => [], 'sub' => [], 'sup' => [], 'mark' => [],
        'blockquote' => [], 'q' => [], 'cite' => [], 'code' => [], 'pre' => [], 'kbd' => [], 'abbr' => [],
        'ul' => [], 'ol' => ['start'], 'li' => [], 'dl' => [], 'dt' => [], 'dd' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [], 'caption' => [],
        'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'],
        'figure' => [], 'figcaption' => [],
        'a' => ['href'], 'img' => ['src', 'alt', 'width', 'height'],
    ];
    private const VAZIOS = ['br' => true, 'hr' => true, 'img' => true];
    private const GLOBAIS = ['title', 'lang', 'dir'];
    /** Elementos que somem junto com o que têm dentro. */
    private const DESCARTADOS = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'noscript', 'noembed', 'noframes', 'template',
        'svg', 'math', 'form', 'input', 'button', 'select', 'option', 'textarea', 'head', 'title', 'meta', 'link', 'base',
        'audio', 'video', 'source', 'track', 'canvas', 'xmp', 'plaintext', 'listing', 'dialog', 'portal', 'slot',
    ];

    public static function limpar(string $html): string
    {
        if ($html === '') {
            return '';
        }
        if (strlen($html) > self::MAX_BYTES) {
            throw new ViolacaoDeRegra(ViolacaoDeRegra::HTML_INVALIDO, 'O HTML é grande demais.');
        }
        $html = str_replace("\0", '', mb_check_encoding($html, 'UTF-8') ? $html : mb_scrub($html, 'UTF-8'));
        if (trim($html) === '') {
            return '';
        }

        $anterior = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            // O invólucro `<div>` mantém texto solto fora de um `<p>` automático; é sempre o primeiro filho do corpo.
            $doc->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div>' . $html . '</div></body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($anterior);
        }
        $corpo = $doc->getElementsByTagName('body')->item(0);
        if (!$corpo instanceof \DOMElement) {
            return '';
        }

        $saida = '';
        $primeiro = true;
        foreach (iterator_to_array($corpo->childNodes) as $no) {
            // O invólucro (primeiro filho) é aberto; o que vier depois dele, só se o HTML fechou o invólucro por conta própria.
            $saida .= $primeiro && $no instanceof \DOMElement && $no->tagName === 'div' ? self::filhos($no, 0) : self::no($no, 0);
            $primeiro = false;
        }

        return trim($saida);
    }

    private static function filhos(\DOMNode $pai, int $nivel): string
    {
        $saida = '';
        foreach (iterator_to_array($pai->childNodes) as $filho) {
            $saida .= self::no($filho, $nivel);
        }

        return $saida;
    }

    private static function no(\DOMNode $no, int $nivel): string
    {
        if ($no instanceof \DOMText) { // inclui CDATA
            return htmlspecialchars($no->data, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }
        if (!$no instanceof \DOMElement) {
            return ''; // comentário, instrução de processamento, doctype
        }
        $nome = strtolower($no->localName ?? $no->tagName);
        if (in_array($nome, self::DESCARTADOS, true)) {
            return '';
        }
        if ($nivel >= self::PROFUNDIDADE_MAXIMA) {
            return htmlspecialchars($no->textContent, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }
        if (!isset(self::ELEMENTOS[$nome])) {
            return self::filhos($no, $nivel + 1); // desconhecido: fica o texto, some a marcação
        }

        $atributos = self::atributos($no, $nome);
        if ($nome === 'a') {
            if (!isset($atributos['href'])) {
                return self::filhos($no, $nivel + 1); // link sem endereço aceito vira texto
            }
            $atributos['rel'] = 'noopener noreferrer';
        }
        if ($nome === 'img' && !isset($atributos['src'])) {
            return '';
        }
        $abre = '<' . $nome;
        foreach ($atributos as $chave => $valor) {
            $abre .= ' ' . $chave . '="' . htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '"';
        }
        $abre .= '>';

        return isset(self::VAZIOS[$nome]) ? $abre : $abre . self::filhos($no, $nivel + 1) . '</' . $nome . '>';
    }

    /** @return array<string, string> */
    private static function atributos(\DOMElement $no, string $nome): array
    {
        $permitidos = array_merge(self::GLOBAIS, self::ELEMENTOS[$nome]);
        $saida = [];
        foreach (iterator_to_array($no->attributes ?? []) as $a) {
            $chave = strtolower($a->name);
            if (!in_array($chave, $permitidos, true)) {
                continue;
            }
            $valor = self::valor($chave, $a->value);
            if ($valor !== null) {
                $saida[$chave] = $valor;
            }
        }

        return $saida;
    }

    private static function valor(string $atributo, string $valor): ?string
    {
        switch ($atributo) {
            case 'href':
                return self::endereco($valor, self::ESQUEMAS_LINK);
            case 'src':
                return self::endereco($valor, self::ESQUEMAS_IMAGEM);
            case 'width':
            case 'height':
            case 'colspan':
            case 'rowspan':
            case 'start':
                $limpo = trim($valor);

                return preg_match('/^[0-9]{1,4}$/D', $limpo) === 1 ? $limpo : null;
            case 'dir':
                $limpo = strtolower(trim($valor));

                return in_array($limpo, ['ltr', 'rtl', 'auto'], true) ? $limpo : null;
            case 'lang':
                $limpo = trim($valor);

                return preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8}){0,3}$/D', $limpo) === 1 ? $limpo : null;
            case 'scope':
                $limpo = strtolower(trim($valor));

                return in_array($limpo, ['row', 'col', 'rowgroup', 'colgroup'], true) ? $limpo : null;
            default: // title, alt
                $limpo = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $valor) ?? '';

                return mb_substr($limpo, 0, 500);
        }
    }

    /**
     * Endereço aceito (esquema da lista ou relativo) ou null. Espaços e controles são tirados antes de olhar o esquema: o
     * navegador também os ignora (`java<TAB>script:`).
     *
     * @param list<string> $esquemas
     */
    private static function endereco(string $valor, array $esquemas): ?string
    {
        $url = preg_replace('/[\x00-\x20\x7F-\x9F\x{00A0}\x{1680}\x{180E}\x{2000}-\x{200F}\x{2028}-\x{202F}\x{205F}-\x{2064}\x{3000}\x{FEFF}]+/u', '', $valor);
        if ($url === null || $url === '' || strlen($url) > 2000) {
            return null;
        }
        // Se há ":" antes de qualquer "/", "?" ou "#", o que vem antes é um esquema.
        if (preg_match('/^([^\/?#]*):/', $url, $m) === 1) {
            return in_array(strtolower($m[1]), $esquemas, true) && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*$/D', $m[1]) === 1 ? $url : null;
        }

        return $url;
    }
}
