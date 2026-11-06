<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Acao;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Conteudo\Objeto;
use Symfony\Component\HttpFoundation\Request;

/**
 * Escolha do recorte das telas que varrem a árvore (vencidos, lixeira): um site ou uma seção. Sem escolha vale tudo que a pessoa
 * alcança (o administrador, a raiz de cada site; os demais, as seções em que têm papel). Quem usa declara `$servicos`.
 */
trait EscopoDeSecoes
{
    /**
     * @return list<Objeto> as seções a varrer: a pedida em `?secao=` (se existe, não está na lixeira e a pessoa a vê) ou os pontos de entrada
     */
    private function secoesDoEscopo(SessaoAtiva $sessao, Request $r): array
    {
        $pedida = $this->secaoPedida($sessao, $r);
        if ($pedida !== null) {
            return [$pedida];
        }
        $secoes = [];
        foreach (TelasDeNavegacao::pontosDeEntrada($this->servicos, $sessao->usuario) as $lista) {
            foreach ($lista as [$o]) {
                $secoes[] = $o;
            }
        }

        return $secoes;
    }

    private function secaoPedida(SessaoAtiva $sessao, Request $r): ?Objeto
    {
        $valor = $r->query->all()['secao'] ?? $r->request->all()['secao'] ?? null;
        $id = is_string($valor) && preg_match('/^[1-9][0-9]{0,9}$/D', $valor) === 1 ? (int) $valor : null;
        $o = $id === null ? null : $this->servicos->objetos->porId($id);

        return $o !== null && $o->status !== 'apagado' && $this->servicos->permissoes->pode($sessao->usuario, Acao::Ver, $o) ? $o : null;
    }

    /** Formulário GET com o seletor de seção. */
    private function seletorDeSecao(SessaoAtiva $sessao, string $acao, ?Objeto $atual): string
    {
        $opcoes = '<option value="">Todos os meus sites e seções</option>';
        $vistos = [];
        foreach (TelasDeNavegacao::pontosDeEntrada($this->servicos, $sessao->usuario) as $siteId => $lista) {
            $site = $this->servicos->sites->porId($siteId);
            foreach ($lista as [$o]) {
                $vistos[$o->id] = true;
                $opcoes .= '<option value="' . $o->id . '"' . ($atual?->id === $o->id ? ' selected' : '') . '>' . Pagina::h(($site->nome ?? 'Site') . ' — ' . $o->titulo) . '</option>';
            }
        }
        if ($atual !== null && !isset($vistos[$atual->id])) {
            $opcoes .= '<option value="' . $atual->id . '" selected>' . Pagina::h($atual->titulo) . '</option>';
        }

        return '<form method="get" action="' . Pagina::h($acao) . '" class="row g-2 align-items-end mb-3">'
            . '<div class="col-12 col-md-8"><label for="secao" class="form-label">Onde procurar</label><select id="secao" name="secao" class="form-select">' . $opcoes . '</select></div>'
            . '<div class="col-auto"><button type="submit" class="btn btn-outline-primary">Filtrar</button></div></form>';
    }

    private function dataBrasilia(?string $gravada): string
    {
        if ($gravada === null || $gravada === '') {
            return '—';
        }
        try {
            return (new \DateTimeImmutable($gravada))->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i');
        } catch (\Exception) {
            return '—';
        }
    }
}
