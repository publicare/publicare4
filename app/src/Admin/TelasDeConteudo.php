<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Acao;
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
    public function inicio(SessaoAtiva $sessao, ?\Symfony\Component\HttpFoundation\Request $requisicao = null): Response
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
        $primeiro = null; // [siteId, raiz|null, pode criar sob a raiz]
        foreach ($entradas as $siteId => $lista) {
            $site = $this->servicos->sites->porId($siteId);
            if ($site === null) {
                continue;
            }
            $raiz = $site->objetoRaizId === null ? null : $this->servicos->objetos->porId($site->objetoRaizId);
            $primeiro ??= [$site, $raiz];
            $base = Painel::PREFIXO . '/site/' . $site->id;
            $nomeDoSite = Pagina::h($site->nome);
            $acoes = [];
            if ($this->servicos->permissoes->podeAdministrarSite($u, $raiz)) {
                $acoes[] = [$base . '/dominios', 'Domínios'];
                $acoes[] = [$base . '/configuracoes', 'Configurações'];
            }
            if ($u->administrador) {
                $acoes[] = [$base . '/redirecionamentos', 'Redirecionamentos'];
                $acoes[] = [$base . '/peles', 'Peles e modelos de página'];
                $acoes[] = [$base . '/publicacao', 'Menu e publicação'];
            }
            $corpo .= '<section class="pbl-secao" aria-labelledby="site-' . $site->id . '"><div class="pbl-secao-cab"><h2 id="site-' . $site->id . '">' . $nomeDoSite . '</h2></div>';
            if ($u->administrador) {
                $corpo .= TelasDeSite::avisosDeMenu($this->servicos, $site, $base . '/publicacao');
            }
            if ($lista === []) {
                $corpo .= '<p class="text-body-secondary">Este site ainda não tem conteúdo inicial.</p>';
            } else {
                $corpo .= '<ul class="pbl-lista-mais">';
                foreach ($lista as [$o, $perfil]) {
                    $corpo .= '<li><a href="' . $this->link($o->id) . '"><strong>' . Pagina::h($o->titulo) . '</strong><span>Seu papel: ' . Pagina::h($perfil) . '</span></a></li>';
                }
                $corpo .= '</ul>';
            }
            if ($acoes !== []) {
                $itens = '';
                foreach ($acoes as [$href, $rotulo]) {
                    $itens .= '<li><a href="' . $href . '">' . $rotulo . '<span class="visually-hidden"> do site ' . $nomeDoSite . '</span></a></li>';
                }
                $corpo .= '<div class="pbl-recolhiveis">' . Pagina::recolhivel('site-' . $site->id, 'Mais ações do site', '<ul class="pbl-secundarias" aria-label="Ações do site ' . $nomeDoSite . '">' . $itens . '</ul>') . '</div>';
            }
            $corpo .= '</section>';
        }
        if ($corpo === '') {
            $corpo = '<div class="pbl-vazio">' . Pagina::alerta('info', 'Você ainda não recebeu acesso a nenhuma seção. Peça a um administrador.') . '</div>';
        }
        $avisoDeVersao = TelasDeAtualizacao::avisoHomePage($this->servicos, $u) . TelasDeCron::avisoDaHome($this->servicos, $u);

        return Pagina::resposta('Início', (($requisicao?->query->all()['ok'] ?? null) === 'senha-nova' ? Pagina::alerta('success', TelasDeSenhaProvisoria::AVISO_TROCADA) : '') . $this->avisosDeRevisao($sessao) . $this->acaoPrincipal($sessao, $primeiro) . $avisoDeVersao . $corpo, Response::HTTP_OK, false, $this->barra($sessao), [], 'Olá, <strong>' . Pagina::h($u->nome) . '</strong>.' . ($entradas === [] ? ' Assim que você receber acesso a uma seção, ela aparece aqui.' : ''));
    }

    /**
     * A única ação principal do Início (D5, D14): "Continuar de onde parou" (último conteúdo que a própria pessoa criou ou editou, pelo
     * registro de atividade que já existe), senão "Ver o site" (já há conteúdo publicado) e, num site vazio, "Criar página". Nada aqui
     * mostra conteúdo fora da permissão da pessoa: o conteúdo é conferido de novo com `Acao::Editar`.
     *
     * @param array{0: \Pbl\Conteudo\Site, 1: ?\Pbl\Conteudo\Objeto}|null $primeiro
     */
    private function acaoPrincipal(SessaoAtiva $sessao, ?array $primeiro): string
    {
        $u = $sessao->usuario;
        try {
            foreach ($this->servicos->auditoria->objetosEditadosPor($u->id) as $id) {
                $o = $this->servicos->objetos->porId($id);
                if ($o !== null && $o->status !== 'apagado' && $this->servicos->permissoes->pode($u, Acao::Editar, $o)) {
                    return $this->blocoPrincipal('Continuar de onde parou', 'Você mexeu por último em <strong>' . Pagina::h($o->titulo) . '</strong>.', 'Continuar editando', $this->link($o->id) . '/editar');
                }
            }
        } catch (\Throwable) {
            // o registro de atividade nunca derruba o Início
        }
        if ($primeiro === null) {
            return '';
        }
        [$site, $raiz] = $primeiro;
        $publica = $this->servicos->enderecos->base($site->id);
        $temConteudo = $raiz !== null && $this->servicos->objetos->filhosForaDaLixeira($raiz->id) > 0;
        if ($temConteudo && $publica !== '') {
            return $this->blocoPrincipal('Ver o site', 'Veja o site como o visitante vê.', 'Ver o site ↗', $publica . '/', true);
        }
        if ($raiz !== null && $this->servicos->permissoes->podeCriarSob($u, $raiz)) {
            return $this->blocoPrincipal('Criar página', 'O site ainda não tem páginas. Comece pela primeira.', 'Criar página', $this->link($raiz->id) . '/novo');
        }
        if ($raiz !== null) {
            return $this->blocoPrincipal('Abrir o conteúdo', 'Veja o que já existe no site.', 'Abrir o conteúdo', $this->link($raiz->id));
        }

        return '';
    }

    private function blocoPrincipal(string $titulo, string $textoHtml, string $botao, string $href, bool $externo = false): string
    {
        return '<section class="pbl-acao-principal" aria-labelledby="acao-principal"><h2 id="acao-principal" class="pbl-acao-titulo">' . Pagina::h($titulo) . '</h2><p>' . $textoHtml . '</p>'
            . '<a class="btn btn-primary" href="' . Pagina::h($href) . '"' . ($externo ? ' target="_blank" rel="noopener"' : '') . '>' . Pagina::h($botao) . '</a></section>';
    }

    /** Avisos da tela inicial: envios esperando a revisão desta conta e conteúdos dela que voltaram devolvidos. */
    private function avisosDeRevisao(SessaoAtiva $sessao): string
    {
        $u = $sessao->usuario;
        $revisao = $this->servicos->revisao;
        $html = '';
        $pendentes = $revisao->ehRevisor($u) ? $revisao->contarPendencias($u) : 0;
        if ($pendentes > 0) {
            $html .= Pagina::alerta('warning', '<strong>' . $pendentes . '</strong> ' . ($pendentes === 1 ? 'envio aguarda' : 'envios aguardam') . ' a sua revisão. <a class="alert-link" href="' . Painel::PREFIXO . '/revisar?aba=pendencias">Abrir as pendências</a>', '', true);
        }
        $devolvidos = $revisao->podeEnviar($u) ? $revisao->contarDevolvidos($u) : 0;
        if ($devolvidos > 0) {
            $html .= Pagina::alerta('warning', '<strong>' . $devolvidos . '</strong> ' . ($devolvidos === 1 ? 'conteúdo seu foi devolvido' : 'conteúdos seus foram devolvidos') . ' com ajustes pedidos. <a class="alert-link" href="' . Painel::PREFIXO . '/revisar?aba=envios&amp;estado=devolvidos">Ver em Meus envios</a>', '', true);
        }

        return $html;
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
