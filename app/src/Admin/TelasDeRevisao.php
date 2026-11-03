<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Acao;
use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Conteudo\ErroDeRevisao;
use Pbl\Conteudo\FluxoDeRevisao;
use Pbl\Conteudo\Objeto;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fluxo de revisão no painel (fase 6): "Pendências" (a fila de quem revisa), "Meus envios" (o que a conta enviou) e as ações
 * enviar, reenviar, retirar, aprovar e devolver, todas por POST com origem e CSRF conferidos antes de qualquer coisa. As regras
 * ficam em `FluxoDeRevisao` (que usa `Permissoes`); aqui só se monta a tela. Botão que a conta não pode usar não aparece, mas
 * cada rota confere de novo. Sem JavaScript: o formulário de devolver abre num `<details>`.
 */
final class TelasDeRevisao
{
    use AuxiliaresDeTela;

    private const PASSOS = ['enviar', 'reenviar', 'retirar', 'aprovar', 'devolver'];
    private const FUSO = 'America/Sao_Paulo';

    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
    ) {
    }

    /** @return array{0: string, 1: int|null}|null */
    public static function rota(string $prefixo, string $caminho, string $metodo): ?array
    {
        if ($caminho === $prefixo . '/pendencias') {
            return $metodo === 'GET' ? ['pendencias', null] : null;
        }
        if ($caminho === $prefixo . '/meus-envios') {
            return $metodo === 'GET' ? ['envios', null] : null;
        }
        if ($metodo === 'POST' && preg_match('#^' . preg_quote($prefixo, '#') . '/revisao/([1-9][0-9]{0,9})/(' . implode('|', self::PASSOS) . ')$#D', $caminho, $m) === 1) {
            return [$m[2], (int) $m[1]];
        }

        return null;
    }

    /** @param array{0: string, 1: int|null} $rota */
    public function tratar(SessaoAtiva $sessao, array $rota, Request $requisicao): Response
    {
        [$tela, $id] = $rota;
        if ($tela === 'pendencias') {
            return $this->pendencias($sessao, $requisicao);
        }
        if ($tela === 'envios') {
            return $this->meusEnvios($sessao, $requisicao);
        }
        if (!$this->postConfere($sessao, $requisicao)) {
            return $this->recusado();
        }

        return $this->executar($sessao, $tela, (int) $id, $requisicao);
    }

    // ---------------------------------------------------------------- ações

    private function executar(SessaoAtiva $sessao, string $passo, int $id, Request $r): Response
    {
        $u = $sessao->usuario;
        $versao = $this->numero($this->texto($r, 'versao'));
        $voltar = $this->texto($r, 'voltar');
        $voltar = in_array($voltar, ['pendencias', 'meus-envios'], true) ? $voltar : 'objeto';
        $area = $this->numero($this->texto($r, 'area'));
        try {
            $revisao = $this->servicos->revisao;
            $novo = match ($passo) {
                'enviar', 'reenviar' => $revisao->enviar($u, $id, $versao),
                'retirar' => $revisao->retirar($u, $id, $versao),
                'aprovar' => $revisao->aprovar($u, $id, $versao),
                default => $revisao->devolver($u, $id, $this->texto($r, 'motivo') ?? '', $versao),
            };
        } catch (ErroDeRevisao $e) {
            if ($e->codigo === ErroDeRevisao::NEGADO) {
                // fora da transação do serviço, para o registro não ser desfeito junto com ela
                $this->servicos->auditoria->registrar('revisao.negado', 'usuario:' . $u->id, $u->id, $id, ['acao' => $passo]);
            }

            return $this->falha($sessao, $e, $voltar);
        }
        if ($passo === 'aprovar' && !$this->sincronizar($novo)) {
            $link = $voltar === 'objeto' ? Painel::PREFIXO . '/objeto/' . $novo->id : Painel::PREFIXO . '/' . $voltar;
            $corpo = Pagina::alerta('warning', 'O envio foi aprovado e o conteúdo está publicado no painel, mas a página pública não pôde ser atualizada agora. Publique de novo pelo painel ou rode a publicação do site.')
                . '<p><a class="btn btn-outline-secondary" href="' . Pagina::h($link) . '">Voltar</a></p>';

            return Pagina::resposta('Aprovado, com aviso', $corpo, Response::HTTP_OK, false, $this->barra($sessao));
        }
        $codigo = ['enviar' => 'enviado', 'reenviar' => 'reenviado', 'retirar' => 'retirado', 'aprovar' => 'aprovado', 'devolver' => 'devolvido'][$passo];
        if ($voltar === 'objeto') {
            return new RedirectResponse(Painel::PREFIXO . '/objeto/' . $novo->id . '?salvo=' . $codigo, Response::HTTP_SEE_OTHER);
        }
        $query = ['ok' => $codigo] + ($voltar === 'pendencias' && $area !== null ? ['area' => (string) $area] : []);

        return new RedirectResponse(Painel::PREFIXO . '/' . $voltar . '?' . http_build_query($query), Response::HTTP_SEE_OTHER);
    }

    private function falha(SessaoAtiva $sessao, ErroDeRevisao $e, string $voltar): Response
    {
        [$status, $texto] = match ($e->codigo) {
            ErroDeRevisao::NEGADO => [Response::HTTP_FORBIDDEN, 'Você não pode fazer isto com este conteúdo, ou ele não existe.'],
            ErroDeRevisao::ESTADO => [Response::HTTP_CONFLICT, 'Este conteúdo não está mais no estado esperado: outra pessoa pode ter agido antes. Volte e confira a situação atual.'],
            ErroDeRevisao::CONFLITO => [Response::HTTP_CONFLICT, 'Outra pessoa alterou este conteúdo depois que a tela foi aberta. Volte, recarregue e confira antes de decidir.'],
            default => [Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage()],
        };
        $link = $voltar === 'objeto' ? Painel::PREFIXO . '/' : Painel::PREFIXO . '/' . $voltar;
        $corpo = Pagina::alerta('warning', $texto) . '<p><a class="btn btn-outline-secondary" href="' . Pagina::h($link) . '">Voltar</a></p>';

        return Pagina::resposta('Não foi possível concluir', $corpo, $status, false, $this->barra($sessao));
    }

    /** Regrava a página aprovada (e a do pai, que lista filhos). A mudança já está salva: se a publicação falha, a próxima publicação do site recupera. */
    private function sincronizar(Objeto $o): bool
    {
        $site = $this->servicos->sites->porId($o->siteId);
        if ($site === null) {
            return true;
        }
        $ok = true;
        $agora = gmdate('c');
        foreach ([$o, $o->paiId === null ? null : $this->servicos->objetos->porId($o->paiId)] as $alvo) {
            if ($alvo === null) {
                continue;
            }
            try {
                $alvo->visivel($agora) ? $this->servicos->publicador->publicar($site, $alvo) : $this->servicos->publicador->despublicar($site, $alvo);
            } catch (\Throwable) {
                $ok = false;
            }
        }

        return $ok;
    }

    // ---------------------------------------------------------------- Pendências

    private function pendencias(SessaoAtiva $sessao, Request $r): Response
    {
        $u = $sessao->usuario;
        $revisao = $this->servicos->revisao;
        if (!$revisao->ehRevisor($u)) {
            return Pagina::resposta('Sem acesso', Pagina::alerta('warning', 'Só quem é editor de alguma seção (ou administrador) revisa envios.'), Response::HTTP_FORBIDDEN, false, $this->barra($sessao));
        }
        $q = $r->query->all();
        $areas = $revisao->areasParaFiltro($u);
        $areaId = $this->numero($q['area'] ?? null);
        if ($areaId !== null && !in_array($areaId, array_map(static fn (Objeto $a): int => $a->id, $areas), true)) {
            $areaId = null;
        }
        $lista = $revisao->pendencias($u, $areaId);
        $total = $areaId === null ? $revisao->contarPendencias($u) : count($lista);

        $corpo = $this->avisoDe($q['ok'] ?? null) . $this->filtroDeArea($areas, $areaId);
        if ($lista === []) {
            $corpo .= Pagina::alerta('info', $areaId === null ? 'Nada aguardando revisão agora.' : 'Nada aguardando revisão nesta área.');
        } else {
            $enviados = $revisao->ultimos(array_map(static fn (Objeto $o): int => $o->id, $lista), ['enviou', 'reenviou']);
            $corpo .= '<p class="text-body-secondary">' . $total . ' envio(s) aguardando' . ($total > count($lista) ? '; mostrando os ' . count($lista) . ' mais antigos' : '') . '.</p><ul class="list-group pbl-pendencias mb-4">';
            foreach ($lista as $o) {
                $corpo .= $this->itemDePendencia($sessao, $o, $enviados[$o->id] ?? null, $areaId);
            }
            $corpo .= '</ul>';
        }

        return Pagina::resposta('Pendências', $corpo, Response::HTTP_OK, false, $this->barra($sessao), [['Início', Painel::PREFIXO . '/'], ['Pendências', null]], 'Envios aguardando a sua revisão. Aprovar publica; devolver exige dizer o motivo.');
    }

    /** @param array{acao: string, quando: string, quem: string, motivo: ?string}|null $envio */
    private function itemDePendencia(SessaoAtiva $sessao, Objeto $o, ?array $envio, ?int $areaId): string
    {
        $pai = $o->paiId === null ? null : $this->servicos->objetos->porId($o->paiId);
        $dentro = $pai !== null && $this->servicos->permissoes->pode($sessao->usuario, Acao::Ver, $pai) ? ' · dentro de ' . Pagina::h($pai->titulo) : '';
        $quem = $envio === null ? 'Enviado' : ($envio['acao'] === 'reenviou' ? 'Reenviado' : 'Enviado') . ' por ' . Pagina::h($envio['quem']) . ' em ' . $this->quando($envio['quando']);
        $campos = $this->campoCsrf($sessao) . '<input type="hidden" name="versao" value="' . $o->versao . '"><input type="hidden" name="voltar" value="pendencias">'
            . ($areaId === null ? '' : '<input type="hidden" name="area" value="' . $areaId . '">');
        $base = Painel::PREFIXO . '/revisao/' . $o->id;
        $idMotivo = 'motivo-' . $o->id;

        return '<li class="list-group-item pbl-pendencia"><div class="mb-2"><a class="fw-semibold" href="' . Painel::PREFIXO . '/objeto/' . $o->id . '">' . Pagina::h($o->titulo) . '</a>'
            . '<div class="small text-body-secondary"><span class="badge text-bg-light border">' . Pagina::h($this->classe($o)) . '</span>' . $dentro . '</div>'
            . '<div class="small text-body-secondary">' . $quem . '</div></div>'
            . '<div class="d-flex flex-wrap gap-2 align-items-start">'
            . '<form method="post" action="' . $base . '/aprovar">' . $campos . '<button type="submit" class="btn btn-success bg-gradient">Aprovar e publicar</button></form>'
            . '<details class="pbl-devolver"><summary class="btn btn-warning bg-gradient">Devolver…</summary>'
            . '<form method="post" action="' . $base . '/devolver" class="mt-2">' . $campos
            . Pagina::campo($idMotivo, 'motivo', 'Motivo da devolução', '', ['tipo' => 'textarea', 'linhas' => 3, 'obrigatorio' => true, 'maxlength' => FluxoDeRevisao::MOTIVO_MAX, 'ajuda' => 'A pessoa que enviou lê este texto. Obrigatório.'])
            . '<button type="submit" class="btn btn-warning bg-gradient">Devolver ao autor</button></form></details></div></li>';
    }

    /** @param list<Objeto> $areas */
    private function filtroDeArea(array $areas, ?int $escolhida): string
    {
        if (count($areas) < 2) {
            return '';
        }
        $opcoes = ['' => 'Todas as áreas'];
        foreach ($areas as $a) {
            $opcoes[(string) $a->id] = $a->titulo;
        }

        return '<form method="get" action="' . Painel::PREFIXO . '/pendencias" class="row g-2 align-items-end mb-3">'
            . Pagina::campo('filtro-area', 'area', 'Área', $escolhida === null ? '' : (string) $escolhida, ['tipo' => 'select', 'opcoes' => $opcoes, 'grupo' => 'col-12 col-md-6'])
            . '<div class="col-12 col-md-auto mb-3"><button type="submit" class="btn btn-outline-secondary">Filtrar</button></div></form>';
    }

    // ---------------------------------------------------------------- Meus envios

    private function meusEnvios(SessaoAtiva $sessao, Request $r): Response
    {
        $u = $sessao->usuario;
        $revisao = $this->servicos->revisao;
        if (!$revisao->podeEnviar($u)) {
            return Pagina::resposta('Sem acesso', Pagina::alerta('warning', 'Só quem é autor ou editor de alguma seção envia conteúdo.'), Response::HTTP_FORBIDDEN, false, $this->barra($sessao));
        }
        $q = $r->query->all();
        $filtro = is_string($q['estado'] ?? null) && in_array($q['estado'], ['revisao', 'devolvidos', 'publicados', 'rascunhos'], true) ? $q['estado'] : '';
        $mapa = ['revisao' => 'submetido', 'devolvidos' => 'rejeitado', 'publicados' => 'publicado', 'rascunhos' => 'privado'];
        $todos = $revisao->meusEnvios($u);
        $lista = $filtro === '' ? $todos : array_values(array_filter($todos, static fn (Objeto $o): bool => $o->status === $mapa[$filtro]));
        $ids = array_map(static fn (Objeto $o): int => $o->id, $lista);
        $devolucoes = $revisao->ultimos($ids, ['devolveu']);
        $enviados = $revisao->ultimos($ids, ['enviou', 'reenviou']);

        $corpo = $this->avisoDe($q['ok'] ?? null) . $this->abas($filtro);
        if ($lista === []) {
            $corpo .= Pagina::alerta('info', 'Nenhum envio por aqui.');
        } else {
            $corpo .= '<ul class="list-group pbl-envios mb-4">';
            foreach ($lista as $o) {
                $corpo .= $this->itemDeEnvio($sessao, $o, $devolucoes[$o->id] ?? null, $enviados[$o->id] ?? null);
            }
            $corpo .= '</ul>';
        }

        return Pagina::resposta('Meus envios', $corpo, Response::HTTP_OK, false, $this->barra($sessao), [['Início', Painel::PREFIXO . '/'], ['Meus envios', null]], 'O que você mandou para revisão e o que ainda é rascunho seu.');
    }

    private function abas(string $atual): string
    {
        $abas = ['' => 'Todos', 'revisao' => 'Em revisão', 'devolvidos' => 'Devolvidos', 'publicados' => 'Publicados', 'rascunhos' => 'Rascunhos'];
        $html = '<ul class="nav nav-pills mb-3 flex-wrap">';
        foreach ($abas as $chave => $rotulo) {
            $html .= '<li class="nav-item"><a class="nav-link' . ($chave === $atual ? ' active' : '') . '"' . ($chave === $atual ? ' aria-current="page"' : '') . ' href="' . Painel::PREFIXO . '/meus-envios' . ($chave === '' ? '' : '?estado=' . $chave) . '">' . $rotulo . '</a></li>';
        }

        return $html . '</ul>';
    }

    /**
     * @param array{acao: string, quando: string, quem: string, motivo: ?string}|null $devolucao
     * @param array{acao: string, quando: string, quem: string, motivo: ?string}|null $envio
     */
    private function itemDeEnvio(SessaoAtiva $sessao, Objeto $o, ?array $devolucao, ?array $envio): string
    {
        $u = $sessao->usuario;
        [$rotulo, $cor] = match ($o->status) {
            'submetido' => ['em revisão', 'text-bg-info'],
            'publicado' => ['publicado', 'text-bg-success'],
            'rejeitado' => ['devolvido', 'text-bg-warning'],
            default => ['rascunho', 'text-bg-secondary'],
        };
        $link = Painel::PREFIXO . '/objeto/' . $o->id;
        $base = Painel::PREFIXO . '/revisao/' . $o->id;
        $campos = $this->campoCsrf($sessao) . '<input type="hidden" name="versao" value="' . $o->versao . '"><input type="hidden" name="voltar" value="meus-envios">';
        $botoes = '';
        $podeEditar = $this->servicos->permissoes->pode($u, Acao::Editar, $o) && ($o->status !== 'submetido' || $this->servicos->permissoes->pode($u, Acao::Publicar, $o));
        if ($podeEditar) {
            $botoes .= '<a class="btn btn-sm btn-outline-primary" href="' . $link . '/editar">Editar</a>';
        }
        $podeEditarSimples = $this->servicos->permissoes->pode($u, Acao::Editar, $o);
        if ($o->status === 'rejeitado' && $podeEditarSimples) {
            $botoes .= '<form method="post" action="' . $base . '/reenviar">' . $campos . '<button type="submit" class="btn btn-sm btn-success bg-gradient">Reenviar para revisão</button></form>';
        }
        if ($o->status === 'privado' && $podeEditarSimples) {
            $botoes .= '<form method="post" action="' . $base . '/enviar">' . $campos . '<button type="submit" class="btn btn-sm btn-success bg-gradient">Enviar para revisão</button></form>';
        }
        if ($this->servicos->revisao->podeRetirar($u, $o)) {
            $botoes .= '<form method="post" action="' . $base . '/retirar">' . $campos . '<button type="submit" class="btn btn-sm btn-outline-secondary">Retirar envio</button></form>';
        }
        $detalhe = '';
        if ($o->status === 'rejeitado') {
            $detalhe = '<div class="alert alert-warning py-2 my-2" role="alert"><strong>Devolvido'
                . ($devolucao === null ? '' : ' por ' . Pagina::h($devolucao['quem']) . ' em ' . $this->quando($devolucao['quando'])) . '.</strong> '
                . ($devolucao === null || $devolucao['motivo'] === null ? 'Sem motivo registrado.' : '<span class="pbl-motivo">' . nl2br(Pagina::h($devolucao['motivo'])) . '</span>') . '</div>';
        } elseif ($o->status === 'submetido' && $envio !== null) {
            $detalhe = '<div class="small text-body-secondary">Enviado em ' . $this->quando($envio['quando']) . '.</div>';
        }

        return '<li class="list-group-item pbl-envio"><div class="mb-1"><a class="fw-semibold" href="' . $link . '">' . Pagina::h($o->titulo) . '</a> '
            . '<span class="badge ' . $cor . '">' . $rotulo . '</span></div>'
            . '<div class="small text-body-secondary"><span class="badge text-bg-light border">' . Pagina::h($this->classe($o)) . '</span></div>' . $detalhe
            . ($botoes === '' ? '' : '<div class="d-flex flex-wrap gap-2 mt-2">' . $botoes . '</div>') . '</li>';
    }

    // ---------------------------------------------------------------- bloco na página do conteúdo

    /** O quadro de revisão da página de um conteúdo: botões que a conta pode usar e os últimos passos. Vazio quando não há nada a mostrar. */
    public static function bloco(Servicos $servicos, Csrf $csrf, SessaoAtiva $sessao, Objeto $o): string
    {
        $u = $sessao->usuario;
        $revisao = $servicos->revisao;
        $perm = $servicos->permissoes;
        $campos = '<input type="hidden" name="_csrf" value="' . Pagina::h($csrf->daSessao($sessao->idHash)) . '"><input type="hidden" name="versao" value="' . $o->versao . '"><input type="hidden" name="voltar" value="objeto">';
        $base = Painel::PREFIXO . '/revisao/' . $o->id;
        $acoes = '';
        $texto = '';
        $revisor = $perm->pode($u, Acao::Publicar, $o);
        if (in_array($o->status, ['privado', 'rejeitado'], true) && !$revisor && $perm->pode($u, Acao::Editar, $o)) {
            $reenvio = $o->status === 'rejeitado';
            $acoes .= '<form method="post" action="' . $base . '/' . ($reenvio ? 'reenviar' : 'enviar') . '">' . $campos . '<button type="submit" class="btn btn-success bg-gradient">' . ($reenvio ? 'Reenviar para revisão' : 'Enviar para revisão') . '</button></form>';
        }
        if ($revisao->podeRetirar($u, $o)) {
            $acoes .= '<form method="post" action="' . $base . '/retirar">' . $campos . '<button type="submit" class="btn btn-outline-secondary">Retirar envio</button></form>';
        }
        if ($o->status === 'submetido' && $revisor) {
            $acoes .= '<form method="post" action="' . $base . '/aprovar">' . $campos . '<button type="submit" class="btn btn-success bg-gradient">Aprovar e publicar</button></form>'
                . '<details class="pbl-devolver"><summary class="btn btn-warning bg-gradient">Devolver…</summary><form method="post" action="' . $base . '/devolver" class="mt-2">' . $campos
                . Pagina::campo('motivo-objeto', 'motivo', 'Motivo da devolução', '', ['tipo' => 'textarea', 'linhas' => 3, 'obrigatorio' => true, 'maxlength' => FluxoDeRevisao::MOTIVO_MAX])
                . '<button type="submit" class="btn btn-warning bg-gradient">Devolver ao autor</button></form></details>';
        }
        if ($o->status === 'submetido' && $acoes === '') {
            $texto = '<p class="mb-2">Aguardando revisão. Enquanto isso o conteúdo não pode ser editado por quem o enviou.</p>';
        }
        $historico = $revisao->historico($o->id, 5);
        if ($o->status === 'rejeitado') {
            foreach ($historico as $h) {
                if ($h['acao'] === 'devolveu') {
                    $texto .= '<p class="mb-2"><strong>Devolvido por ' . Pagina::h($h['quem']) . ':</strong> ' . ($h['motivo'] === null ? 'sem motivo registrado.' : nl2br(Pagina::h($h['motivo']))) . '</p>';
                    break;
                }
            }
        }
        if ($acoes === '' && $texto === '' && $historico === []) {
            return '';
        }
        $rotulos = ['enviou' => 'enviou para revisão', 'reenviou' => 'reenviou', 'retirou' => 'retirou o envio', 'aprovou' => 'aprovou e publicou', 'devolveu' => 'devolveu'];
        $lista = '';
        foreach ($historico as $h) {
            $lista .= '<li><strong>' . Pagina::h($h['quem']) . '</strong> ' . ($rotulos[$h['acao']] ?? Pagina::h($h['acao'])) . ' em ' . Pagina::h(self::formatar($h['quando'])) . '</li>';
        }

        return '<section class="pbl-card pbl-revisao" aria-labelledby="revisao-titulo"><h2 id="revisao-titulo" class="pbl-card-titulo">Revisão</h2>' . $texto
            . ($acoes === '' ? '' : '<div class="d-flex flex-wrap gap-2 align-items-start mb-2">' . $acoes . '</div>')
            . ($lista === '' ? '' : '<ul class="small text-body-secondary mb-0">' . $lista . '</ul>') . '</section>';
    }

    // ---------------------------------------------------------------- apoio

    private function avisoDe(mixed $ok): string
    {
        $m = match ($ok) {
            'enviado' => ['success', 'Enviado para revisão.'],
            'reenviado' => ['success', 'Reenviado para revisão.'],
            'retirado' => ['info', 'Envio retirado: o conteúdo voltou a ser privado.'],
            'aprovado' => ['success', 'Aprovado e publicado.'],
            'devolvido' => ['info', 'Devolvido ao autor com o motivo informado.'],
            default => null,
        };

        return $m === null ? '' : Pagina::alerta($m[0], $m[1]);
    }

    private function classe(Objeto $o): string
    {
        return $this->servicos->classes->porId($o->classeId)->nome ?? '?';
    }

    private function quando(string $iso): string
    {
        return Pagina::h(self::formatar($iso));
    }

    private static function formatar(string $iso): string
    {
        try {
            return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone(self::FUSO))->format('d/m/Y H:i');
        } catch (\Throwable) {
            return $iso;
        }
    }
}
