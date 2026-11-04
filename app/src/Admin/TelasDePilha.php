<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Acao;
use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Conteudo\Colagem;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\Pilha;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A pilha de copiar, recortar e colar (fase 5r, ADR 0025). Separar itens (`adicionar`) a partir da lista de Conteúdo ou da tela do
 * objeto, ver e limpar a pilha, e colar numa pasta de destino em duas etapas: uma previsão (GET, não grava nada) e a confirmação
 * (POST com CSRF) que mostra o resultado de cada item. A lógica de verdade é de `Colagem` e `Pilha`; aqui só entram a permissão de
 * ver/alterar na hora de separar, o CSRF e as telas.
 */
final class TelasDePilha
{
    use AuxiliaresDeTela;

    private const ROTULOS = [
        Colagem::MOVER => 'Mover para cá',
        Colagem::COPIA => 'Colar uma cópia',
        Colagem::LINK => 'Colar um link para o original',
    ];

    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
    ) {
    }

    /** @return array{0: string, 1: int|null}|null */
    public static function rota(string $prefixo, string $caminho, string $metodo): ?array
    {
        $base = $prefixo . '/pilha';
        if ($caminho === $base) {
            return $metodo === 'GET' ? ['ver', null] : null;
        }
        if ($metodo === 'POST' && in_array($caminho, [$base . '/adicionar', $base . '/remover', $base . '/esvaziar'], true)) {
            return [substr($caminho, strlen($base) + 1), null];
        }
        if (preg_match('#^' . preg_quote($base, '#') . '/colar/([1-9][0-9]{0,9})$#D', $caminho, $m) === 1) {
            return $metodo === 'GET' ? ['previa', (int) $m[1]] : ($metodo === 'POST' ? ['colar', (int) $m[1]] : null);
        }

        return null;
    }

    /** @param array{0: string, 1: int|null} $rota */
    public function tratar(SessaoAtiva $sessao, array $rota, Request $r): Response
    {
        return match ($rota[0]) {
            'ver' => $this->ver($sessao, $r),
            'adicionar' => $this->adicionar($sessao, $r),
            'remover' => $this->remover($sessao, $r),
            'esvaziar' => $this->esvaziar($sessao, $r),
            'previa' => $this->previa($sessao, (int) $rota[1], $r),
            default => $this->colar($sessao, (int) $rota[1], $r),
        };
    }

    // ---------------------------------------------------------------- separar

    private function adicionar(SessaoAtiva $sessao, Request $r): Response
    {
        if (!$this->postConfere($sessao, $r)) {
            return $this->recusado();
        }
        $c = $r->request->all();
        $modo = $c['modo'] ?? null;
        $ids = $this->ids($c['ids'] ?? null);
        if (!in_array($modo, [Pilha::COPIAR, Pilha::RECORTAR], true) || $ids === null) {
            return $this->incompleto($sessao);
        }
        $versoes = is_array($c['versao'] ?? null) ? $c['versao'] : [];
        $aceitos = [];
        $ignorados = 0;
        $siteId = null;
        $vistas = [];
        foreach ($ids as $id) {
            $o = $this->servicos->objetos->porId($id);
            $acao = $modo === Pilha::RECORTAR ? Acao::Editar : Acao::Ver;
            if ($o === null || $o->status === 'apagado' || $o->paiId === null || !$this->servicos->permissoes->pode($sessao->usuario, $acao, $o) || ($siteId !== null && $o->siteId !== $siteId)) {
                ++$ignorados;
                continue;
            }
            $siteId = $o->siteId;
            // A versão que a pessoa estava vendo na lista vale para o recorte: se mudou desde então, a colagem recusa (otimista).
            $vista = $this->numero($versoes[$id] ?? null);
            if ($vista !== null && $modo === Pilha::RECORTAR) {
                $vistas[$id] = $vista;
            }
            $aceitos[] = $o;
        }
        $aviso = ['ok' => 'pilha_' . $modo, 'f' => (string) $ignorados];
        try {
            $n = $this->servicos->pilha->adicionar($sessao->usuario->id, $modo, $aceitos, $vistas);
        } catch (\InvalidArgumentException $e) {
            return Pagina::resposta('Pilha', Pagina::alerta('danger', $this->mensagemDoRepositorio($e)) . '<p><a class="btn btn-outline-secondary" href="' . Painel::PREFIXO . '/pilha">Ver a pilha</a></p>', Response::HTTP_UNPROCESSABLE_ENTITY, false, $this->barra($sessao));
        }
        $aviso['n'] = (string) $n;
        if ($n > 0) {
            $this->servicos->auditoria->registrar('pilha.' . $modo, 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, $aceitos[0]->id, ['itens' => $n]);
        }

        return new RedirectResponse($this->destinoDaVolta($sessao, $c, $aviso), Response::HTTP_SEE_OTHER);
    }

    private function remover(SessaoAtiva $sessao, Request $r): Response
    {
        if (!$this->postConfere($sessao, $r)) {
            return $this->recusado();
        }
        $id = $this->numero($r->request->all()['id'] ?? null);
        if ($id === null) {
            return $this->incompleto($sessao);
        }
        $this->servicos->pilha->remover($sessao->usuario->id, $id);

        return new RedirectResponse(Painel::PREFIXO . '/pilha', Response::HTTP_SEE_OTHER);
    }

    private function esvaziar(SessaoAtiva $sessao, Request $r): Response
    {
        if (!$this->postConfere($sessao, $r)) {
            return $this->recusado();
        }
        $this->servicos->pilha->esvaziar($sessao->usuario->id);
        $volta = $this->destinoDaVolta($sessao, $r->request->all(), ['ok' => 'pilha_vazia']);

        return new RedirectResponse($volta, Response::HTTP_SEE_OTHER);
    }

    // ---------------------------------------------------------------- ver a pilha

    private function ver(SessaoAtiva $sessao, Request $r): Response
    {
        $itens = $this->servicos->pilha->itens($sessao->usuario->id);
        $modo = $itens === [] ? null : $itens[0]['modo'];
        $corpo = '';
        if ($itens === []) {
            $corpo .= '<p class="text-body-secondary">A pilha está vazia. Em Conteúdo, marque itens e use "Copiar para a pilha" ou "Recortar para a pilha".</p>';
        } else {
            $csrf = $this->campoCsrf($sessao);
            $linhas = '';
            foreach ($itens as $i) {
                $o = $this->servicos->objetos->porId($i['objeto_id']);
                if ($o === null) {
                    continue;
                }
                $pai = $o->paiId === null ? null : $this->servicos->objetos->porId($o->paiId);
                $linhas .= '<tr><td><a href="' . Painel::PREFIXO . '/objeto/' . $o->id . '">' . Pagina::h($o->titulo) . '</a>' . ($o->status === 'apagado' ? ' <span class="badge text-bg-secondary">na lixeira</span>' : '') . '</td>'
                    . '<td>' . Pagina::h($this->servicos->classes->porId($o->classeId)->nome ?? '?') . '</td>'
                    . '<td>' . ($pai === null ? '—' : Pagina::h($pai->titulo)) . '</td>'
                    . '<td class="text-end"><form method="post" action="' . Painel::PREFIXO . '/pilha/remover" class="d-inline">' . $csrf . '<input type="hidden" name="id" value="' . $o->id . '">'
                    . '<button type="submit" class="btn btn-sm btn-outline-secondary">Tirar da pilha<span class="visually-hidden"> ' . Pagina::h($o->titulo) . '</span></button></form></td></tr>';
            }
            $corpo .= Pagina::alerta('info', ($modo === Pilha::RECORTAR ? 'Recortados: ' : 'Copiados: ') . count($itens) . ($modo === Pilha::RECORTAR ? ' (saem do lugar de hoje só quando você colar).' : ' (nada muda até você colar).'))
                . '<div class="table-responsive"><table class="table table-sm align-middle"><caption class="visually-hidden">Itens na pilha</caption>'
                . '<thead><tr><th scope="col">Título</th><th scope="col">Tipo</th><th scope="col">Está em</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead><tbody>' . $linhas . '</tbody></table></div>'
                . '<form method="post" action="' . Painel::PREFIXO . '/pilha/esvaziar" class="mb-3">' . $csrf . '<button type="submit" class="btn btn-outline-danger">Esvaziar a pilha</button></form>'
                . '<p>Para colar, abra a pasta de destino em Conteúdo e use <strong>Colar aqui</strong>.</p>';
        }
        $corpo .= '<p><a class="btn btn-success bg-gradient" href="' . Painel::PREFIXO . '/conteudo">Ir para Conteúdo</a></p>';

        return Pagina::resposta('Pilha', $corpo, Response::HTTP_OK, false, $this->barra($sessao), [['Início', Painel::PREFIXO . '/'], ['Pilha', null]], '', $this->ajudaDaPilha());
    }

    // ---------------------------------------------------------------- colar

    private function previa(SessaoAtiva $sessao, int $destinoId, Request $r): Response
    {
        $destino = $this->servicos->objetos->porId($destinoId);
        if ($destino === null || !$this->servicos->permissoes->pode($sessao->usuario, Acao::Ver, $destino)) {
            return $this->semAcesso($sessao);
        }
        $itens = $this->servicos->pilha->itens($sessao->usuario->id);
        if ($itens === []) {
            return Pagina::resposta('Colar aqui', Pagina::alerta('secondary', 'A pilha está vazia. Copie ou recorte itens em Conteúdo primeiro.') . $this->voltar($destino), Response::HTTP_OK, false, $this->barra($sessao), $this->migalhas($destino), '', $this->ajudaDaPilha());
        }
        $modo = $itens[0]['modo'];
        $tipo = $this->tipoDoPedido($r->query->all()['tipo'] ?? null, $modo);
        $profunda = $tipo === Colagem::COPIA && ($r->query->all()['profunda'] ?? '') === '1';
        $colagem = new Colagem($this->servicos);
        $recusa = $colagem->recusaDoDestino($sessao->usuario, $destino);
        $corpo = '';
        if ($recusa !== null) {
            $corpo .= Pagina::alerta('danger', $recusa);
        }
        $corpo .= $this->escolha($destino, $modo, $tipo, $profunda);
        $linhas = $recusa === null ? $colagem->prever($sessao->usuario, $destino, $tipo, $profunda) : [];
        $corpo .= '<h2 class="h5 mt-4">O que vai acontecer</h2>' . $this->tabelaDeLinhas($linhas, false);
        $haOk = array_filter($linhas, static fn (array $l): bool => $l['ok']) !== [];
        if ($haOk) {
            $corpo .= '<form method="post" action="' . Painel::PREFIXO . '/pilha/colar/' . $destino->id . '" class="mt-3">' . $this->campoCsrf($sessao)
                . '<input type="hidden" name="tipo" value="' . $tipo . '">' . ($profunda ? '<input type="hidden" name="profunda" value="1">' : '')
                . '<div class="pbl-acoes"><button type="submit" class="btn btn-success bg-gradient">Confirmar e colar</button>'
                . '<a class="btn btn-outline-secondary" href="' . Painel::PREFIXO . '/conteudo/' . $destino->id . '">Cancelar</a></div></form>';
        } else {
            $corpo .= $this->voltar($destino);
        }

        return Pagina::resposta('Colar em: ' . $destino->titulo, $corpo, Response::HTTP_OK, false, $this->barra($sessao), $this->migalhas($destino), '', $this->ajudaDaPilha());
    }

    private function colar(SessaoAtiva $sessao, int $destinoId, Request $r): Response
    {
        if (!$this->postConfere($sessao, $r)) {
            return $this->recusado();
        }
        $destino = $this->servicos->objetos->porId($destinoId);
        if ($destino === null || !$this->servicos->permissoes->pode($sessao->usuario, Acao::Ver, $destino)) {
            return $this->semAcesso($sessao);
        }
        $itens = $this->servicos->pilha->itens($sessao->usuario->id);
        if ($itens === []) {
            return new RedirectResponse(Painel::PREFIXO . '/pilha/colar/' . $destino->id, Response::HTTP_SEE_OTHER);
        }
        $c = $r->request->all();
        $tipo = $this->tipoDoPedido($c['tipo'] ?? null, $itens[0]['modo']);
        $profunda = $tipo === Colagem::COPIA && ($c['profunda'] ?? '') === '1';
        $colagem = new Colagem($this->servicos);
        $recusa = $colagem->recusaDoDestino($sessao->usuario, $destino);
        if ($recusa !== null) {
            $negado = !$this->servicos->permissoes->podeCriarSob($sessao->usuario, $destino);
            if ($negado) {
                $this->servicos->auditoria->registrar('pilha.colar.negado', 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, $destino->id);
            }

            return Pagina::resposta('Colar aqui', Pagina::alerta('danger', $recusa) . $this->voltar($destino), $negado ? Response::HTTP_FORBIDDEN : Response::HTTP_UNPROCESSABLE_ENTITY, false, $this->barra($sessao), $this->migalhas($destino));
        }
        $res = $colagem->colar($sessao->usuario, $destino, $tipo, $profunda);
        $corpo = '';
        if ($res['ok'] > 0) {
            $corpo .= Pagina::alerta('success', $res['ok'] . ($res['ok'] === 1 ? ' item colado' : ' itens colados') . ' (' . $res['criados'] . ($res['criados'] === 1 ? ' objeto' : ' objetos') . ').');
        }
        if ($res['falhas'] > 0) {
            $corpo .= Pagina::alerta('warning', $res['falhas'] . ($res['falhas'] === 1 ? ' item não foi colado' : ' itens não foram colados') . '. Veja o motivo de cada um abaixo; eles continuam na pilha.');
        }
        if ($res['republicacao'] === 'falhou') {
            $corpo .= Pagina::alerta('warning', 'A mudança foi salva, mas alguma página pública não pôde ser atualizada agora. Publique o site de novo.');
        } elseif ($res['republicacao'] === 'fila') {
            $corpo .= Pagina::alerta('info', 'Foram muitos objetos: as páginas públicas serão atualizadas aos poucos, pela fila de republicação.');
        }
        $corpo .= $this->tabelaDeLinhas($res['linhas'], true) . $this->voltar($destino, 'Ir para ' . $destino->titulo);

        return Pagina::resposta('Resultado da colagem', $corpo, Response::HTTP_OK, false, $this->barra($sessao), $this->migalhas($destino), '', $this->ajudaDaPilha());
    }

    /** @param list<array{id: int, titulo: string, ok: bool, mensagem: string, quantidade: int, novo: int|null}> $linhas */
    private function tabelaDeLinhas(array $linhas, bool $feito): string
    {
        if ($linhas === []) {
            return '<p class="text-body-secondary">Nada a fazer.</p>';
        }
        $html = '';
        foreach ($linhas as $l) {
            $titulo = $l['novo'] !== null && $feito ? '<a href="' . Painel::PREFIXO . '/objeto/' . $l['novo'] . '">' . Pagina::h($l['titulo']) . '</a>' : Pagina::h($l['titulo']);
            $selo = $l['ok'] ? '<span class="badge text-bg-success">' . ($feito ? 'Colado' : 'Vai ser colado') . '</span>' : '<span class="badge text-bg-danger">' . ($feito ? 'Não foi colado' : 'Será recusado') . '</span>';
            $html .= '<tr><td>' . $titulo . '</td><td>' . $selo . '</td><td>' . Pagina::h($l['mensagem']) . '</td></tr>';
        }

        return '<div class="table-responsive"><table class="table table-sm align-middle"><caption class="visually-hidden">Itens da pilha</caption>'
            . '<thead><tr><th scope="col">Item</th><th scope="col">Situação</th><th scope="col">Detalhe</th></tr></thead><tbody>' . $html . '</tbody></table></div>';
    }

    /** Escolha do jeito de colar: um formulário GET (sem JavaScript) que só atualiza a previsão. */
    private function escolha(Objeto $destino, string $modo, string $tipo, bool $profunda): string
    {
        $opcoes = '';
        foreach ($modo === Pilha::RECORTAR ? [Colagem::MOVER] : [Colagem::COPIA, Colagem::LINK] as $t) {
            $opcoes .= '<div class="form-check"><input class="form-check-input" type="radio" name="tipo" id="tipo-' . $t . '" value="' . $t . '"' . ($t === $tipo ? ' checked' : '') . '>'
                . '<label class="form-check-label" for="tipo-' . $t . '">' . Pagina::h(self::ROTULOS[$t]) . '</label></div>';
        }
        $profundaHtml = $modo === Pilha::COPIAR
            ? '<div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="profunda" id="campo-profunda" value="1"' . ($profunda ? ' checked' : '') . '>'
                . '<label class="form-check-label" for="campo-profunda">Copiar também tudo o que está dentro (só vale para "Colar uma cópia")</label></div>'
            : '';

        return '<form method="get" action="' . Painel::PREFIXO . '/pilha/colar/' . $destino->id . '" class="mb-2"><fieldset><legend class="h6">Como colar em "' . Pagina::h($destino->titulo) . '"</legend>'
            . $opcoes . $profundaHtml . '<button type="submit" class="btn btn-sm btn-outline-primary mt-2">Atualizar a previsão</button></fieldset></form>';
    }

    private function tipoDoPedido(mixed $v, string $modo): string
    {
        if (is_string($v) && Colagem::tipoValido($modo, $v)) {
            return $v;
        }

        return $modo === Pilha::RECORTAR ? Colagem::MOVER : Colagem::COPIA;
    }

    // ---------------------------------------------------------------- apoio

    /** @return list<int>|null null = formato inválido */
    private function ids(mixed $v): ?array
    {
        if ($v === null) {
            return [];
        }
        if (!is_array($v) || count($v) > TelasDeNavegacao::MAX_LOTE) {
            return null;
        }
        $ids = [];
        foreach ($v as $item) {
            $n = $this->numero($item);
            if ($n === null) {
                return null;
            }
            $ids[$n] = $n;
        }

        return array_values($ids);
    }

    /**
     * De volta à tela de onde veio (Conteúdo ou a tela do objeto), com o aviso no endereço; sem permissão de ver lá, vai para a pilha.
     *
     * @param array<string, mixed> $c
     * @param array<string, string> $aviso
     */
    private function destinoDaVolta(SessaoAtiva $sessao, array $c, array $aviso): string
    {
        $nivel = $this->numero($c['nivel'] ?? null);
        $o = $nivel === null ? null : $this->servicos->objetos->porId($nivel);
        if ($o === null || !$this->servicos->permissoes->pode($sessao->usuario, Acao::Ver, $o)) {
            return Painel::PREFIXO . '/pilha';
        }
        if (($c['tela'] ?? '') === 'objeto') {
            return Painel::PREFIXO . '/objeto/' . $o->id . '?' . http_build_query($aviso);
        }

        return Painel::PREFIXO . '/conteudo/' . $o->id . '?' . http_build_query($aviso);
    }

    private function voltar(Objeto $destino, string $rotulo = 'Voltar'): string
    {
        return '<p class="mt-3"><a class="btn btn-outline-secondary" href="' . Painel::PREFIXO . '/conteudo/' . $destino->id . '">' . Pagina::h($rotulo) . '</a> '
            . '<a class="btn btn-outline-secondary" href="' . Painel::PREFIXO . '/pilha">Ver a pilha</a></p>';
    }

    /** @return list<array{0: string, 1: string|null}> */
    private function migalhas(Objeto $destino): array
    {
        return [['Início', Painel::PREFIXO . '/'], ['Conteúdo', Painel::PREFIXO . '/conteudo'], [$destino->titulo, Painel::PREFIXO . '/conteudo/' . $destino->id], ['Colar', null]];
    }

    private function semAcesso(SessaoAtiva $sessao): Response
    {
        return Pagina::resposta('Sem acesso', Pagina::alerta('danger', 'Você não pode ver este conteúdo, ou ele não existe.') . '<p><a class="btn btn-outline-secondary" href="' . Painel::PREFIXO . '/conteudo">Voltar ao Conteúdo</a></p>', Response::HTTP_FORBIDDEN, false, $this->barra($sessao));
    }

    /**
     * Aviso de volta depois de separar itens ou esvaziar (parâmetros `ok`, `n`, `f` no endereço); as telas de Conteúdo e do objeto usam o mesmo.
     *
     * @param array<string, mixed> $q
     */
    public static function aviso(array $q): string
    {
        $ok = $q['ok'] ?? null;
        if ($ok === 'pilha_vazia') {
            return Pagina::alerta('secondary', 'A pilha foi esvaziada.');
        }
        if (!in_array($ok, ['pilha_copiar', 'pilha_recortar'], true)) {
            return '';
        }
        $n = is_string($q['n'] ?? null) && ctype_digit($q['n']) ? (int) $q['n'] : 0;
        $f = is_string($q['f'] ?? null) && ctype_digit($q['f']) ? (int) $q['f'] : 0;
        $html = Pagina::alerta($n > 0 ? 'success' : 'secondary', $n . ($ok === 'pilha_copiar' ? ($n === 1 ? ' item copiado' : ' itens copiados') : ($n === 1 ? ' item recortado' : ' itens recortados')) . ' para a pilha.' . ($n > 0 ? ' Abra a pasta de destino e use "Colar aqui".' : ''));
        if ($f > 0) {
            $html .= Pagina::alerta('warning', $f . ($f === 1 ? ' item foi ignorado' : ' itens foram ignorados') . ': sem permissão, na lixeira, raiz do site ou de outro site.');
        }

        return $html;
    }

    public static function ajudaDaPilha(): string
    {
        return 'A <strong>pilha</strong> é a sua lista de itens separados para colar depois, e fica com a sua conta. '
            . '<strong>Copiar</strong> não muda nada até você colar; <strong>recortar</strong> também não: o item só sai do lugar de hoje quando você cola. '
            . 'Em <strong>Colar aqui</strong> (na pasta de destino) há três jeitos: <em>mover</em> (itens recortados), <em>colar uma cópia</em> (rascunho, com título "Cópia de …" e endereço novo; '
            . 'opcionalmente com tudo o que está dentro; os arquivos anexados não são duplicados no disco) e <em>colar um link</em> para o original. '
            . 'A pilha tem um único site e um único modo: separar algo de outro site, ou no outro modo, esvazia o que já estava nela. '
            . 'Cabem até ' . Pilha::LIMITE . ' itens e uma colagem cria ou move até ' . Colagem::LIMITE_TOTAL . ' objetos. '
            . 'Não é possível colar dentro do próprio item. Para recortar é preciso poder editar o item; para colar, poder criar no destino.';
    }
}
