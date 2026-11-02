<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\Response;

/**
 * Painel inicial da administração (ADR 0012): os sites e as seções que a pessoa pode ver. As telas de um conteúdo (ver, criar,
 * editar, estado, mover, lixeira, histórico) ficam em `TelasDeObjetos`.
 */
final class TelasDeConteudo
{
    public function __construct(
        private readonly Servicos $servicos,
        private readonly Csrf $csrf,
    ) {
    }

    /** Painel inicial: sites e seções que a pessoa pode ver. */
    public function inicio(SessaoAtiva $sessao): Response
    {
        $u = $sessao->usuario;
        $entradas = []; // siteId => list<array{0: Objeto, 1: string}>
        if ($u->administrador) {
            foreach ($this->servicos->sites->todos() as $site) {
                $raiz = $site->objetoRaizId === null ? null : $this->servicos->objetos->porId($site->objetoRaizId);
                $entradas[$site->id] = $raiz === null ? [] : [[$raiz, 'administrador']];
            }
        } else {
            $objetos = [];
            foreach ($this->servicos->usuarios->acessosDe($u->id) as $acesso) {
                $o = $this->servicos->objetos->porId($acesso['objeto_id']);
                if ($o !== null) {
                    $objetos[] = [$o, $acesso['perfil']->value];
                }
            }
            foreach ($objetos as [$o, $perfil]) {
                // Seção que já está dentro de outra seção da pessoa no mesmo site: basta a de cima.
                foreach ($objetos as [$outro]) {
                    if ($outro->id !== $o->id && $outro->siteId === $o->siteId && str_starts_with($o->caminho, $outro->caminho)) {
                        continue 2;
                    }
                }
                $entradas[$o->siteId][] = [$o, $perfil];
            }
        }

        $corpo = '';
        foreach ($entradas as $siteId => $lista) {
            $site = $this->servicos->sites->porId($siteId);
            if ($site === null) {
                continue;
            }
            $raiz = $site->objetoRaizId === null ? null : $this->servicos->objetos->porId($site->objetoRaizId);
            $base = Painel::PREFIXO . '/site/' . $site->id;
            $nomeDoSite = Pagina::h($site->nome);
            $acoes = [];
            if ($this->servicos->permissoes->podeAdministrarSite($u, $raiz)) {
                $acoes[] = [$base . '/dominios', 'Domínios'];
                $acoes[] = [$base . '/configuracoes', 'Configurações'];
            }
            if ($u->administrador) {
                $acoes[] = [$base . '/redirecionamentos', 'Redirecionamentos'];
                $acoes[] = [$base . '/peles', 'Peles e moldes'];
                $acoes[] = [$base . '/publicacao', 'Menu e publicação'];
            }
            $corpo .= '<section class="pbl-card" aria-labelledby="site-' . $site->id . '"><h2 id="site-' . $site->id . '" class="h5">' . $nomeDoSite . '</h2>';
            if ($acoes !== []) {
                $corpo .= '<ul class="pbl-lista-acoes" aria-label="Ações do site ' . $nomeDoSite . '">';
                foreach ($acoes as [$href, $rotulo]) {
                    $corpo .= '<li><a class="btn btn-sm btn-outline-secondary" href="' . $href . '">' . $rotulo . '<span class="visually-hidden"> do site ' . $nomeDoSite . '</span></a></li>';
                }
                $corpo .= '</ul>';
            }
            if ($u->administrador) {
                $corpo .= TelasDeSite::avisosDeMenu($this->servicos, $site, $base . '/publicacao');
            }
            if ($lista === []) {
                $corpo .= '<p class="text-body-secondary">Este site ainda não tem conteúdo inicial.</p></section>';
                continue;
            }
            $corpo .= '<p class="form-text mb-1">Seções que você pode abrir:</p><ul class="list-group">';
            foreach ($lista as [$o, $perfil]) {
                $corpo .= '<li class="list-group-item d-flex justify-content-between align-items-center gap-2"><a href="' . $this->link($o->id) . '">' . Pagina::h($o->titulo) . '</a>'
                    . ' <span class="badge text-bg-secondary" title="Seu papel nesta seção">' . Pagina::h($perfil) . '</span></li>';
            }
            $corpo .= '</ul></section>';
        }
        if ($corpo === '') {
            $corpo = Pagina::alerta('info', 'Você ainda não recebeu acesso a nenhuma seção. Peça a um administrador.');
        }
        $saudacao = '';
        $avisoDeVersao = TelasDeAtualizacao::avisoHomePage($this->servicos, $u);

        return Pagina::resposta('Sites e conteúdo', $saudacao . $avisoDeVersao . $corpo, Response::HTTP_OK, false, $this->barra($sessao), [], 'Olá, ' . Pagina::h($u->nome) . '. ' . ($u->administrador ? 'Você é administrador: escolha um site para cuidar dele ou abra uma seção para editar conteúdo.' : 'Abra uma seção para editar o conteúdo.'));
    }

    private function barra(SessaoAtiva $sessao): string
    {
        return Pagina::barra($sessao->usuario->nome, $this->csrf->daSessao($sessao->idHash), $sessao->usuario->administrador);
    }

    private function link(int $id): string
    {
        return Painel::PREFIXO . '/objeto/' . $id;
    }
}
