<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Pbl\Arquivos\ArquivoController;
use Pbl\Arquivos\ArquivoRepositorio;
use Pbl\Conteudo\ConfigDoSiteRepositorio;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Conteudo\Site;

/**
 * Feed RSS 2.0 de uma seção (ADR 0009, adendo de 2026-11-04). O XML é montado como árvore (DOM), nunca por concatenação, então o
 * escape é sempre correto; o resultado é gravado como arquivo estático na publicação (`Publicador`), ao lado da página.
 *
 * O feed de um contêiner (a página inicial ou qualquer objeto com conteúdo abaixo) traz os itens publicados e vigentes da
 * subárvore, do mais recente ao mais antigo, até `rss_itens` (padrão 20). Só entra o que o público já vê: rascunho, agendado,
 * vencido, lixeira e `seo_nao_indexar` ficam de fora. Os endereços são absolutos, com o host do domínio principal
 * (`EnderecoDoSite`); sem domínio principal não há feed. A descrição é texto puro (resumo, senão o começo do corpo sem marcação).
 */
final class Feeds
{
    public const MAX_DESCRICAO = 300;

    public function __construct(
        private readonly ObjetoRepositorio $objetos,
        private readonly ConfigDoSiteRepositorio $configs,
        private readonly EnderecoDoSite $enderecos,
        private readonly ArquivoRepositorio $arquivos,
    ) {
    }

    /** Endereço absoluto do feed da seção, ou null se os feeds estão desligados, falta o domínio principal ou a seção não tem itens. */
    public function url(Site $site, Objeto $conteiner): ?string
    {
        $base = $this->enderecos->base($site->id);
        if ($base === '' || !$this->ligado($site) || $this->itens($site, $conteiner, 1) === []) {
            return null;
        }

        return $this->enderecoDoFeed($base, $conteiner);
    }

    /** Endereço do feed do site (o da página inicial), nas mesmas condições de `url`. */
    public function urlDaRaiz(Site $site): ?string
    {
        $raiz = $site->objetoRaizId === null ? null : $this->objetos->porId($site->objetoRaizId);

        return $raiz === null ? null : $this->url($site, $raiz);
    }

    /** O XML do feed, ou null quando não há feed (desligado, sem domínio principal ou sem itens). */
    public function gerar(Site $site, Objeto $conteiner): ?string
    {
        $base = $this->enderecos->base($site->id);
        if ($base === '' || !$this->ligado($site)) {
            return null;
        }
        $itens = $this->itens($site, $conteiner, $this->limite($site));
        if ($itens === []) {
            return null;
        }
        $idioma = $this->configs->valor($site->id, 'idioma');
        $nome = $this->texto($this->configs->valor($site->id, 'nome')) ?: $site->nome;
        $ehRaiz = $site->objetoRaizId === $conteiner->id;
        $titulo = $ehRaiz ? $nome : $conteiner->titulo . ' - ' . $nome;
        $descricao = $this->texto($conteiner->dados['resumo'] ?? '')
            ?: ($this->texto($conteiner->descricao) ?: ($ehRaiz ? $this->texto($this->configs->valor($site->id, 'descricao')) : ''));

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $rss = $doc->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:atom', 'http://www.w3.org/2005/Atom');
        $doc->appendChild($rss);
        $canal = $doc->createElement('channel');
        $rss->appendChild($canal);

        $this->no($doc, $canal, 'title', $titulo);
        $this->no($doc, $canal, 'link', $base . '/' . $conteiner->urlAmigavel);
        $this->no($doc, $canal, 'description', $descricao !== '' ? $descricao : $titulo);
        $this->no($doc, $canal, 'language', is_string($idioma) && $idioma !== '' ? $idioma : 'pt-BR');
        $self = $doc->createElementNS('http://www.w3.org/2005/Atom', 'atom:link');
        $self->setAttribute('href', $this->limpar($this->enderecoDoFeed($base, $conteiner)));
        $self->setAttribute('rel', 'self');
        $self->setAttribute('type', 'application/rss+xml');
        $canal->appendChild($self);

        $maisRecente = $this->data($itens[0]['data']);
        foreach ($itens as $par) {
            $item = $par['objeto'];
            $data = $this->data($par['data']);
            $link = $base . '/' . $item->urlAmigavel;
            $no = $doc->createElement('item');
            $this->no($doc, $no, 'title', $item->titulo);
            $this->no($doc, $no, 'link', $link);
            $this->no($doc, $no, 'guid', $link)->setAttribute('isPermaLink', 'true');
            $this->no($doc, $no, 'pubDate', $data->format(\DATE_RFC2822));
            $resumo = $this->resumo($item);
            if ($resumo !== '') {
                $this->no($doc, $no, 'description', $resumo);
            }
            $enclosure = $this->enclosure($site, $item, $base);
            if ($enclosure !== null) {
                $e = $doc->createElement('enclosure');
                $e->setAttribute('url', $this->limpar($enclosure['url']));
                $e->setAttribute('length', (string) $enclosure['tamanho']);
                $e->setAttribute('type', $this->limpar($enclosure['tipo']));
                $no->appendChild($e);
            }
            $canal->appendChild($no);
        }
        // Estável entre publicações sem mudança (nada de "agora"): a data do item mais recente.
        $this->no($doc, $canal, 'lastBuildDate', $maisRecente->format(\DATE_RFC2822));

        $xml = $doc->saveXML();

        return $xml === false ? null : $xml;
    }

    private function enderecoDoFeed(string $base, Objeto $conteiner): string
    {
        return $base . '/' . ($conteiner->urlAmigavel === '' ? '' : $conteiner->urlAmigavel . '/') . 'feed.xml';
    }

    private function ligado(Site $site): bool
    {
        return $this->configs->valor($site->id, 'rss_ligado') !== false;
    }

    private function limite(Site $site): int
    {
        $n = $this->configs->valor($site->id, 'rss_itens');

        return is_int($n) ? max(1, min(100, $n)) : 20;
    }

    /** @return list<array{objeto: Objeto, data: string}> */
    private function itens(Site $site, Objeto $conteiner, int $limite): array
    {
        // Folga para os `seo_nao_indexar`, que saem depois da consulta.
        $lista = [];
        foreach ($this->objetos->itensDeFeed($site->id, $conteiner, $limite * 3) as $par) {
            $v = $par['objeto']->dados['seo_nao_indexar'] ?? null;
            if ($v === 1 || $v === '1') {
                continue;
            }
            $lista[] = $par;
            if (count($lista) >= $limite) {
                break;
            }
        }

        return $lista;
    }

    private function data(string $texto): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($texto, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return new \DateTimeImmutable('@0');
        }
    }

    /** Resumo, senão a descrição do objeto, senão o começo do corpo sem marcação. Sempre texto puro. */
    private function resumo(Objeto $o): string
    {
        $resumo = $this->texto($o->dados['resumo'] ?? '');
        if ($resumo === '') {
            $resumo = $this->texto($o->descricao);
        }
        if ($resumo === '') {
            $corpo = $o->dados['corpo'] ?? '';
            if (is_string($corpo) && $corpo !== '') {
                $separado = str_ireplace(['</p>', '<br>', '<br/>', '<br />', '</li>', '</h1>', '</h2>', '</h3>'], ' ', $corpo);
                $resumo = $this->texto(html_entity_decode(strip_tags($separado), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
            }
        }

        return mb_strlen($resumo) > self::MAX_DESCRICAO ? rtrim(mb_substr($resumo, 0, self::MAX_DESCRICAO - 1)) . '…' : $resumo;
    }

    /** @return array{url: string, tamanho: int, tipo: string}|null só se o arquivo é do site e o objeto dono está publicado e vigente */
    private function enclosure(Site $site, Objeto $item, string $base): ?array
    {
        $id = $item->dados['imagem_destaque'] ?? null;
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }
        $arquivo = $this->arquivos->porId((int) $id);
        if ($arquivo === null || $arquivo->siteId !== $site->id || !$arquivo->ehImagem()) {
            return null;
        }
        $dono = $arquivo->objetoId === $item->id ? $item : $this->objetos->porId($arquivo->objetoId);
        if ($dono === null || $dono->siteId !== $site->id || !$dono->visivel(gmdate('c'))) {
            return null;
        }

        return ['url' => $base . ArquivoController::PREFIXO . '/' . $arquivo->id, 'tamanho' => $arquivo->tamanho, 'tipo' => $arquivo->mime];
    }

    private function no(\DOMDocument $doc, \DOMElement $pai, string $nome, string $valor): \DOMElement
    {
        $e = $doc->createElement($nome);
        $e->appendChild($doc->createTextNode($this->limpar($valor)));
        $pai->appendChild($e);

        return $e;
    }

    private function texto(mixed $v): string
    {
        return is_string($v) ? trim((string) preg_replace('/\s+/u', ' ', $this->limpar($v))) : '';
    }

    /** Tira o que o XML 1.0 não admite (controles, substitutos soltos); o resto o DOM escapa. */
    private function limpar(string $texto): string
    {
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $texto);
    }
}
