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
            $corpo .= '<section class="mb-4"><h2 class="h5">' . Pagina::h($site->nome) . '</h2>';
            if ($lista === []) {
                $corpo .= '<p class="text-body-secondary">Este site ainda não tem conteúdo inicial.</p></section>';
                continue;
            }
            $corpo .= '<ul class="list-group">';
            foreach ($lista as [$o, $perfil]) {
                $corpo .= '<li class="list-group-item d-flex justify-content-between align-items-center"><a href="' . $this->link($o->id) . '">' . Pagina::h($o->titulo) . '</a>'
                    . ' <span class="badge text-bg-secondary">' . Pagina::h($perfil) . '</span></li>';
            }
            $corpo .= '</ul></section>';
        }
        if ($corpo === '') {
            $corpo = '<div class="alert alert-info" role="status">Você ainda não recebeu acesso a nenhuma seção. Peça a um administrador.</div>';
        }
        $saudacao = '<p>Olá, <strong>' . Pagina::h($u->nome) . '</strong>.' . ($u->administrador ? ' Você é administrador.' : '') . '</p>';

        return Pagina::resposta('Administração', $saudacao . $corpo, Response::HTTP_OK, false, $this->barra($sessao));
    }

    private function barra(SessaoAtiva $sessao): string
    {
        return Pagina::barra($sessao->usuario->nome, $this->csrf->daSessao($sessao->idHash));
    }

    private function link(int $id): string
    {
        return Painel::PREFIXO . '/objeto/' . $id;
    }
}
