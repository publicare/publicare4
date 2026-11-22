<?php

declare(strict_types=1);

namespace Pbl\Admin;

/**
 * Linha única de conteúdo, usada por todas as listas do painel (ADR 0032):
 * [caixa] [ícone da classe] [título em destaque + metadados em uma linha] [3 ações à vista + menu "⋯ Mais"].
 * Classe pura de renderização: sem acesso a serviços; sem JavaScript e sem estilo em linha (o CSS está em pbl.css).
 */
final class LinhaDeConteudo
{
    /** @param list<AcaoDeLinha> $acoes */
    public static function renderizar(DadosDaLinha $d, array $acoes): string
    {
        $t = Pagina::h($d->titulo);
        $sel = $d->selecionavelVersao === null ? ''
            : '<div class="pbl-lc-sel"><input class="form-check-input" type="checkbox" name="ids[]" value="' . $d->id . '" form="lote" aria-label="Selecionar ' . $t . '">'
                . '<input type="hidden" name="versao[' . $d->id . ']" value="' . $d->selecionavelVersao . '" form="lote"></div>';

        return '<li class="pbl-lc' . ($sel === '' ? ' pbl-lc-sem-sel' : '') . '">' . $sel
            . LinhaDeConteudo::icone($d)
            . '<div class="pbl-lc-titulo"><a class="pbl-lc-link" title="' . $t . '" href="' . Pagina::h($d->href) . '">' . $t . '</a>' . self::meta($d) . '</div>'
            . self::acoes($d, $acoes) . '</li>';
    }

    private static function icone(DadosDaLinha $d): string
    {
        return IconesDeClasse::marca($d->classe, $d->nomeDaClasse);
    }

    private static function meta(DadosDaLinha $d): string
    {
        $m = '<span class="pbl-lc-classe">' . Pagina::h($d->nomeDaClasse) . '</span>';
        if ($d->selo !== '') {
            $m .= '<span class="pbl-lc-selo">' . $d->selo . '</span>';
        }
        foreach ($d->extras as $e) {
            $m .= '<span class="pbl-lc-extra">' . Pagina::h($e) . '</span>';
        }
        if ($d->dataIso !== '') {
            $m .= '<time class="pbl-lc-data" datetime="' . Pagina::h($d->dataIso) . '" title="' . Pagina::h($d->rotuloDaData . ' ' . $d->dataCompleta) . '">'
                . '<span aria-hidden="true">' . Pagina::h($d->dataCurta) . '</span><span class="visually-hidden">' . Pagina::h($d->rotuloDaData . ' ' . $d->dataCompleta) . '</span></time>';
        }
        if ($d->filhos > 0) {
            $m .= '<span class="pbl-lc-filhos">' . $d->filhos . ($d->filhos === 1 ? ' filho' : ' filhos') . '</span>';
        }

        return '<p class="pbl-lc-meta">' . $m . '</p>';
    }

    /** @param list<AcaoDeLinha> $acoes */
    private static function acoes(DadosDaLinha $d, array $acoes): string
    {
        $vista = '';
        $menu = '';
        $perigo = '';
        foreach ($acoes as $a) {
            if ($a->aVista()) {
                $vista .= self::controle($a, $d, true);
            } elseif ($a->destaque === AcaoDeLinha::PERIGO) {
                $perigo .= '<li>' . self::controle($a, $d, false) . '</li>';
            } else {
                $menu .= '<li>' . self::controle($a, $d, false) . '</li>';
            }
        }
        $mais = $menu . $perigo === '' ? '' : '<details class="pbl-mais"><summary class="pbl-lc-acao" title="Mais ações" aria-label="Mais ações: ' . Pagina::h($d->titulo) . '">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true" focusable="false"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg></summary>'
            . '<ul class="pbl-mais-lista">' . $menu . $perigo . '</ul></details>';

        return '<div class="pbl-lc-acoes">' . $vista . $mais . '</div>' . $d->aposAcoes;
    }

    private static function controle(AcaoDeLinha $a, DadosDaLinha $d, bool $aVista): string
    {
        $classe = $aVista ? 'pbl-lc-acao pbl-lc-acao--' . $a->destaque : 'pbl-lc-item' . ($a->destaque === AcaoDeLinha::PERIGO ? ' pbl-lc-item--perigo' : '');
        $rotulo = Pagina::h($a->rotulo);
        $completo = Pagina::h($a->rotulo . ': ' . $d->titulo);
        $miolo = $aVista ? Pagina::icone($a->icone) . '<span class="visually-hidden">' . $rotulo . '</span>'
            : Pagina::icone($a->icone) . '<span>' . $rotulo . '</span>';
        $attrs = ' class="' . $classe . '" title="' . $rotulo . '" aria-label="' . $completo . '"';
        if (!$a->post) {
            return '<a' . $attrs . ' href="' . Pagina::h($a->url) . '"' . ($a->externa ? ' target="_blank" rel="noopener"' : '') . '>' . $miolo . '</a>';
        }
        $ocultos = '';
        foreach ($a->campos as [$nome, $valor]) {
            $ocultos .= '<input type="hidden" name="' . Pagina::h($nome) . '" value="' . Pagina::h($valor) . '">';
        }

        return '<form method="post" action="' . Pagina::h($a->url) . '" class="pbl-lc-form">' . $a->csrf . $ocultos . '<button type="submit"' . $attrs . '>' . $miolo . '</button></form>';
    }
}
