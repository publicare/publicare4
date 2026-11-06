<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Acao;
use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Acesso\Usuario;
use Pbl\Consulta\Consulta;
use Pbl\Consulta\Filtro;
use Pbl\Conteudo\Classe;
use Pbl\Conteudo\ConflitoDeArvore;
use Pbl\Conteudo\ConflitoDeVersao;
use Pbl\Conteudo\ErroDeRevisao;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tela "Conteúdo" (ADR 0012 §14): navegar pela árvore por níveis, como no Publicare 3. Mostra o objeto atual e a lista dos filhos,
 * com migalhas, "Subir um nível", filtros, busca por título, paginação e ações por linha e em lote (publicar, despublicar, enviar
 * para revisão, mandar para a lixeira). Nada daqui grava por conta própria: publicar e despublicar usam `ObjetoRepositorio::atualizar`
 * com a versão otimista, o envio usa `FluxoDeRevisao`, a lixeira usa `mandarParaLixeira`; mover, editar e histórico continuam nas
 * telas de `TelasDeObjetos`. A lista só traz filhos de um objeto que a pessoa pode ver (o perfil vale para a subárvore inteira).
 */
final class TelasDeNavegacao
{
    use AuxiliaresDeTela;

    public const OPCOES_POR_PAGINA = [25, 50, 100];
    public const POR_PAGINA_PADRAO = 25;
    /** Teto de itens num único pedido em lote. */
    public const MAX_LOTE = 200;
    private const FUSO = 'America/Sao_Paulo';
    private const LIXEIRA = 'apagado';
    private const ACOES = ['publicar', 'despublicar', 'enviar', 'excluir'];
    private const ORDENS = [
        'peso' => ['Posição (peso) e título', 'peso, titulo'],
        'titulo' => ['Título (A a Z)', 'titulo'],
        'recentes' => ['Alterados por último', '-atualizado_em'],
        'publicacao' => ['Data de publicação (mais nova primeiro)', '-data_publicacao'],
    ];
    private const ESTADOS = [
        'privado' => 'Privado', 'submetido' => 'Em revisão', 'rejeitado' => 'Devolvido', 'publicado' => 'Publicado (qualquer vigência)',
        'agendado' => 'Agendado', 'expirado' => 'Expirado', 'apagado' => 'Na lixeira',
    ];

    /** @var array<int, string> */
    private array $nomesDeClasse = [];
    /** @var array<int, string> */
    private array $nomesDeUsuario = [];

    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
    ) {
    }

    /** @return array{0: string, 1: int|null}|null */
    public static function rota(string $prefixo, string $caminho, string $metodo): ?array
    {
        if ($caminho === $prefixo . '/conteudo') {
            return $metodo === 'GET' ? ['inicio', null] : null;
        }
        if ($caminho === $prefixo . '/conteudo/acao') {
            return $metodo === 'POST' ? ['acao', null] : null;
        }
        if ($metodo === 'GET' && preg_match('#^' . preg_quote($prefixo, '#') . '/conteudo/([1-9][0-9]{0,9})$#D', $caminho, $m) === 1) {
            return ['nivel', (int) $m[1]];
        }

        return null;
    }

    /** @param array{0: string, 1: int|null} $rota */
    public function tratar(SessaoAtiva $sessao, array $rota, Request $r): Response
    {
        return match ($rota[0]) {
            'inicio' => $this->inicio($sessao),
            'nivel' => $this->nivel($sessao, (int) $rota[1], $r),
            default => $this->acao($sessao, $r),
        };
    }

    /**
     * De onde cada pessoa começa: o administrador, na raiz de cada site; os demais, nas seções em que receberam um papel (a que já
     * está dentro de outra sua no mesmo site fica de fora).
     *
     * @return array<int, list<array{0: Objeto, 1: string}>> siteId => [objeto, papel]
     */
    public static function pontosDeEntrada(Servicos $servicos, Usuario $u): array
    {
        $entradas = [];
        if ($u->administrador) {
            foreach ($servicos->sites->todos() as $site) {
                $raiz = $site->objetoRaizId === null ? null : $servicos->objetos->porId($site->objetoRaizId);
                $entradas[$site->id] = $raiz === null ? [] : [[$raiz, 'administrador']];
            }

            return $entradas;
        }
        $objetos = [];
        foreach ($servicos->usuarios->acessosDe($u->id) as $acesso) {
            $o = $servicos->objetos->porId($acesso['objeto_id']);
            if ($o !== null) {
                $objetos[] = [$o, $acesso['perfil']->value];
            }
        }
        foreach ($objetos as [$o, $perfil]) {
            foreach ($objetos as [$outro]) {
                if ($outro->id !== $o->id && $outro->siteId === $o->siteId && str_starts_with($o->caminho, $outro->caminho)) {
                    continue 2;
                }
            }
            $entradas[$o->siteId][] = [$o, $perfil];
        }

        return $entradas;
    }

    // ---------------------------------------------------------------- entrada

    private function inicio(SessaoAtiva $sessao): Response
    {
        $pontos = [];
        foreach (self::pontosDeEntrada($this->servicos, $sessao->usuario) as $siteId => $lista) {
            $site = $this->servicos->sites->porId($siteId);
            foreach ($lista as [$o, $perfil]) {
                $pontos[] = [$site->nome ?? '?', $o, $perfil];
            }
        }
        if (count($pontos) === 1) {
            return new RedirectResponse($this->link($pontos[0][1]->id), Response::HTTP_SEE_OTHER);
        }
        if ($pontos === []) {
            return Pagina::resposta('Conteúdo', Pagina::alerta('info', 'Você ainda não recebeu acesso a nenhuma seção. Peça a um administrador.'), Response::HTTP_OK, false, $this->barra($sessao), [['Início', Painel::PREFIXO . '/'], ['Conteúdo', null]]);
        }
        $itens = '';
        foreach ($pontos as [$nomeDoSite, $o, $perfil]) {
            $itens .= '<li class="list-group-item d-flex justify-content-between align-items-center gap-2"><a href="' . $this->link($o->id) . '">' . Pagina::h($nomeDoSite) . ' — ' . Pagina::h($o->titulo) . '</a>'
                . '<span class="badge text-bg-secondary" title="Seu papel nesta seção">' . Pagina::h($perfil) . '</span></li>';
        }

        return Pagina::resposta('Conteúdo', '<p class="text-body-secondary mb-2">Escolha por onde começar:</p><ul class="list-group">' . $itens . '</ul>', Response::HTTP_OK, false, $this->barra($sessao), [['Início', Painel::PREFIXO . '/'], ['Conteúdo', null]]);
    }

    // ---------------------------------------------------------------- um nível

    private function nivel(SessaoAtiva $sessao, int $id, Request $r): Response
    {
        $o = $this->servicos->objetos->porId($id);
        if ($o === null || !$this->pode($sessao, Acao::Ver, $o)) {
            return $this->semAcesso($sessao);
        }
        $q = $r->query->all();
        $f = $this->filtros($q);
        $agora = gmdate('c');
        $lista = $this->servicos->motor->buscar(new Consulta($o->siteId, $o->id, 1, $f['classe'] === null ? null : [$f['classe']], $this->condicoes($f, $agora), self::ORDENS[$f['ordem']][1], $f['inicio'], $f['por'], false, null));
        $total = $this->servicos->motor->contar(new Consulta($o->siteId, $o->id, 1, $f['classe'] === null ? null : [$f['classe']], $this->condicoes($f, $agora), self::ORDENS[$f['ordem']][1], 0, 1, false, null));
        $resumos = $this->servicos->objetos->resumosParaLista(array_map(static fn (Objeto $x): int => $x->id, $lista));

        $corpo = $this->avisoDoResultado($q) . $this->faixaDaPilha($sessao, $o) . $this->selosDoAtual($o, $agora) . $this->acoesDoTopo($sessao, $o) . $this->formularioDeFiltros($o, $f);
        if ($lista === []) {
            $corpo .= '<p class="text-body-secondary">' . ($this->filtrando($f) ? 'Nenhum conteúdo com estes filtros neste nível.' : 'Nada dentro deste conteúdo ainda.') . '</p>';
        } else {
            $corpo .= $this->tabela($sessao, $o, $lista, $resumos, $agora, $f) . $this->paginacao($o, $f, $total, count($lista));
        }

        return Pagina::resposta($o->titulo, $corpo, Response::HTTP_OK, false, $this->barra($sessao), $this->trilha($sessao, $o), $this->ajuda(), $this->ajudaDaTela());
    }

    /**
     * @param array<string, mixed> $q
     * @return array{q: string, estado: string, classe: int|null, ordem: string, inicio: int, por: int}
     */
    private function filtros(array $q): array
    {
        $texto = is_string($q['q'] ?? null) ? trim(mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $q['q']), 0, 100)) : '';
        $estado = is_string($q['estado'] ?? null) && isset(self::ESTADOS[$q['estado']]) ? $q['estado'] : '';
        $classe = $this->numero($q['classe'] ?? null);
        if ($classe !== null && $this->servicos->classes->porId($classe) === null) {
            $classe = null;
        }
        $ordem = is_string($q['ordem'] ?? null) && isset(self::ORDENS[$q['ordem']]) ? $q['ordem'] : 'peso';
        $por = $this->numero($q['por'] ?? null);
        $por = in_array($por, self::OPCOES_POR_PAGINA, true) ? $por : self::POR_PAGINA_PADRAO;
        $inicio = $this->numero($q['inicio'] ?? null) ?? 0;

        return ['q' => $texto, 'estado' => $estado, 'classe' => $classe, 'ordem' => $ordem, 'inicio' => intdiv($inicio, $por) * $por, 'por' => $por];
    }

    /** @param array{q: string, estado: string, classe: int|null, ordem: string, inicio: int, por: int} $f */
    private function filtrando(array $f): bool
    {
        return $f['q'] !== '' || $f['estado'] !== '' || $f['classe'] !== null;
    }

    /**
     * @param array{q: string, estado: string, classe: int|null, ordem: string, inicio: int, por: int} $f
     * @return list<Filtro>
     */
    private function condicoes(array $f, string $agora): array
    {
        $c = match ($f['estado']) {
            '' => [new Filtro('status', '!=', self::LIXEIRA)],
            'agendado' => [new Filtro('status', '=', 'publicado'), new Filtro('data_publicacao', '>', $agora)],
            'expirado' => [new Filtro('status', '=', 'publicado'), new Filtro('data_validade', '<', $agora)],
            default => [new Filtro('status', '=', $f['estado'])],
        };
        if ($f['q'] !== '') {
            $c[] = new Filtro('titulo', 'contem', $f['q']);
        }

        return $c;
    }

    private function ajuda(): string
    {
        return 'Clique no título para descer um nível; use as migalhas ou "Subir um nível" para voltar.';
    }

    private function ajudaDaTela(): string
    {
        return '<strong>Posição</strong> é o peso do conteúdo (menor primeiro); para mudar, edite o conteúdo. '
            . '<strong>Publicar</strong> e <strong>Despublicar</strong> exigem papel de editor; quem é autor usa <strong>Enviar para revisão</strong>. '
            . '<strong>Mover</strong> e <strong>Excluir</strong> (vai para a lixeira, com histórico) abrem uma tela de confirmação. '
            . 'Marque várias linhas para publicar, despublicar, enviar ou excluir de uma vez. A lixeira aparece no filtro de estado. '
            . '<strong>Copiar para a pilha</strong> e <strong>Recortar para a pilha</strong> separam itens (nas linhas, em "Mais", ou marcados) para <strong>Colar aqui</strong> em outra pasta: ver o "?" da faixa da pilha.';
    }

    /** @return list<array{0: string, 1: string|null}> */
    private function trilha(SessaoAtiva $sessao, Objeto $o): array
    {
        $trilha = [['Início', Painel::PREFIXO . '/'], ['Conteúdo', Painel::PREFIXO . '/conteudo']];
        foreach ($this->ancestraisVisiveis($sessao, $o) as $a) {
            $trilha[] = [$a->titulo, $this->link($a->id)];
        }
        $trilha[] = [$o->titulo, null];

        return $trilha;
    }

    /**
     * Ancestrais que a pessoa pode ver, da raiz para baixo. Quem vê um ancestral vê tudo abaixo dele, então a trilha começa no
     * primeiro que ela pode ver e nunca expõe o título de um acima disso.
     *
     * @return list<Objeto>
     */
    private function ancestraisVisiveis(SessaoAtiva $sessao, Objeto $o): array
    {
        $ids = array_values(array_filter(array_map('intval', array_filter(explode('/', trim($o->caminho, '/')), static fn (string $p): bool => $p !== '')), static fn (int $i): bool => $i !== $o->id));
        $achados = $ids === [] ? [] : $this->servicos->motor->porIds($o->siteId, $ids, false);
        $saida = [];
        $liberado = false;
        foreach ($ids as $i) {
            $a = $achados[$i] ?? null;
            if ($a === null) {
                continue;
            }
            $liberado = $liberado || $this->pode($sessao, Acao::Ver, $a);
            if ($liberado) {
                $saida[] = $a;
            }
        }

        return $saida;
    }

    private function selosDoAtual(Objeto $o, string $agora): string
    {
        return '<p class="pbl-selos"><span class="badge text-bg-light border">' . $this->iconeDaClasse($o->classeId) . Pagina::h($this->nomeDaClasse($o->classeId)) . '</span> '
            . $this->selo($o, $agora) . '</p>'
            . ($o->descricao === '' ? '' : '<p class="text-body-secondary">' . Pagina::h($o->descricao) . '</p>');
    }

    private function acoesDoTopo(SessaoAtiva $sessao, Objeto $o): string
    {
        $botoes = '';
        $ancestrais = $this->ancestraisVisiveis($sessao, $o);
        $pai = $o->paiId === null ? null : end($ancestrais);
        if ($pai instanceof Objeto && $pai->id === $o->paiId) {
            $botoes .= '<a class="btn btn-outline-secondary" href="' . $this->link($pai->id) . '">Subir um nível</a>';
        } else {
            $botoes .= '<span class="btn btn-outline-secondary disabled" aria-disabled="true">Subir um nível</span>';
        }
        if ($o->status !== self::LIXEIRA && $this->pode($sessao, Acao::Editar, $o)) {
            $botoes .= Pagina::botaoIcone('editar', 'Editar este objeto', 'btn-primary bg-gradient', ['href' => $this->objeto($o->id) . '/editar', 'tamanho' => '']);
        }
        if ($o->status !== self::LIXEIRA && $this->servicos->permissoes->podeCriarSob($sessao->usuario, $o) && $this->aceitaFilhos($o)) {
            $botoes .= Pagina::botaoIcone('mais', 'Criar filho', 'btn-success bg-gradient', ['href' => $this->objeto($o->id) . '/novo', 'tamanho' => '']);
        }
        $botoes .= Pagina::botaoIcone('detalhes', 'Detalhes', 'btn-outline-secondary', ['href' => $this->objeto($o->id), 'tamanho' => '']);
        $publica = $this->urlPublica($o);
        if ($publica !== null) {
            $botoes .= Pagina::botaoIcone('externo', 'Ver página pública', 'btn-outline-secondary', ['href' => $publica, 'externo' => true, 'tamanho' => '']);
        }

        return '<div class="pbl-barra-acoes">' . $botoes . '</div>';
    }

    /** @param array{q: string, estado: string, classe: int|null, ordem: string, inicio: int, por: int} $f */
    private function formularioDeFiltros(Objeto $o, array $f): string
    {
        $estados = $this->opcao('', 'Todos (menos a lixeira)', $f['estado'] === '');
        foreach (self::ESTADOS as $chave => $rotulo) {
            $estados .= $this->opcao($chave, $rotulo, $f['estado'] === $chave);
        }
        $tipos = $this->opcao('', 'Todos os tipos', $f['classe'] === null);
        foreach ($this->servicos->classes->todas() as $c) {
            $tipos .= $this->opcao((string) $c->id, $c->nome, $f['classe'] === $c->id);
        }
        $ordens = '';
        foreach (self::ORDENS as $chave => [$rotulo]) {
            $ordens .= $this->opcao($chave, $rotulo, $f['ordem'] === $chave);
        }
        $por = '';
        foreach (self::OPCOES_POR_PAGINA as $n) {
            $por .= $this->opcao((string) $n, (string) $n, $f['por'] === $n);
        }

        return '<form method="get" action="' . $this->link($o->id) . '" class="pbl-filtros-conteudo mb-3" aria-label="Busca e filtros deste nível">'
            . '<div><label for="campo-q" class="form-label">Título contém</label><input id="campo-q" name="q" type="search" class="form-control form-control-sm" maxlength="100" value="' . Pagina::h($f['q']) . '"></div>'
            . '<div><label for="campo-estado" class="form-label">Estado</label><select id="campo-estado" name="estado" class="form-select form-select-sm">' . $estados . '</select></div>'
            . '<div><label for="campo-classe" class="form-label">Tipo</label><select id="campo-classe" name="classe" class="form-select form-select-sm">' . $tipos . '</select></div>'
            . '<div><label for="campo-ordem" class="form-label">Ordem</label><select id="campo-ordem" name="ordem" class="form-select form-select-sm">' . $ordens . '</select></div>'
            . '<div><label for="campo-por" class="form-label">Por página</label><select id="campo-por" name="por" class="form-select form-select-sm">' . $por . '</select></div>'
            . '<div class="pbl-filtros-aplicar"><button type="submit" class="btn btn-sm btn-primary">Aplicar</button>'
            . ($this->filtrando($f) ? ' <a class="btn btn-sm btn-outline-secondary" href="' . $this->link($o->id) . '">Limpar</a>' : '') . '</div></form>';
    }

    /**
     * @param list<Objeto> $lista
     * @param array<int, array{atualizado_em: string, alterado_por: int|null, filhos: int}> $resumos
     * @param array{q: string, estado: string, classe: int|null, ordem: string, inicio: int, por: int} $f
     */
    private function tabela(SessaoAtiva $sessao, Objeto $atual, array $lista, array $resumos, string $agora, array $f): string
    {
        $csrf = $this->campoCsrf($sessao);
        $volta = http_build_query($this->consultaDe($f));
        // Só quem pode mudar alguma linha ganha as caixas de seleção e a barra de lote; o leitor só navega.
        $lote = false;
        foreach ($lista as $x) {
            $lote = $lote || $this->pode($sessao, Acao::Editar, $x) || $this->pode($sessao, Acao::Publicar, $x) || $this->pode($sessao, Acao::Apagar, $x);
        }
        $linhas = '';
        foreach ($lista as $x) {
            $res = $resumos[$x->id] ?? ['atualizado_em' => '', 'alterado_por' => null, 'filhos' => 0];
            $linhas .= '<tr><td>' . ($lote ? '<input class="form-check-input" type="checkbox" name="ids[]" value="' . $x->id . '" form="lote" aria-label="Selecionar ' . Pagina::h($x->titulo) . '">'
                . '<input type="hidden" name="versao[' . $x->id . ']" value="' . $x->versao . '" form="lote">' : '') . '</td>'
                . '<td><span class="badge text-bg-light border" title="Tipo">' . $this->iconeDaClasse($x->classeId) . Pagina::h($this->nomeDaClasse($x->classeId)) . '</span></td>'
                . '<td><a class="fw-semibold" href="' . $this->link($x->id) . '">' . Pagina::h($x->titulo) . '</a></td>'
                . '<td>' . $this->selo($x, $agora) . '</td>'
                . '<td>' . Pagina::h($this->data($res['atualizado_em'])) . '</td>'
                . '<td>' . Pagina::h($res['alterado_por'] === null ? '—' : $this->nomeDe($res['alterado_por'])) . '</td>'
                . '<td>' . $res['filhos'] . '</td>'
                . '<td class="text-end">' . $this->acoesDaLinha($sessao, $x, $res['filhos'], $atual->id, $volta, $csrf) . '</td></tr>';
        }

        return '<div class="table-responsive"><table class="table table-sm table-hover align-middle"><caption class="visually-hidden">Conteúdo dentro de ' . Pagina::h($atual->titulo) . '</caption>'
            . '<thead><tr><th scope="col">' . ($lote ? '<input class="form-check-input" type="checkbox" data-pbl-marcar-todos="ids[]" aria-label="Selecionar todos desta página">' : '<span class="visually-hidden">Seleção</span>') . '</th>'
            . '<th scope="col">Tipo</th><th scope="col">Título</th><th scope="col">Estado</th><th scope="col">Alterado em</th><th scope="col">Por</th><th scope="col">Filhos</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead>'
            . '<tbody>' . $linhas . '</tbody></table></div>'
            . ($lote ? $this->barraDeLote($sessao, $atual, $volta) : '');
    }

    private function barraDeLote(SessaoAtiva $sessao, Objeto $atual, string $volta): string
    {
        return '<form method="post" action="' . Painel::PREFIXO . '/conteudo/acao" id="lote" class="pbl-lote d-flex flex-wrap gap-2 align-items-end mb-3">' . $this->campoCsrf($sessao)
            . '<input type="hidden" name="nivel" value="' . $atual->id . '"><input type="hidden" name="volta" value="' . Pagina::h($volta) . '">'
            . '<div><label for="campo-lote" class="form-label mb-0">Com os selecionados</label><select id="campo-lote" name="acao" class="form-select form-select-sm">'
            . '<option value="publicar">Publicar</option><option value="despublicar">Despublicar</option><option value="enviar">Enviar para revisão</option><option value="excluir">Mandar para a lixeira</option></select></div>'
            . '<button type="submit" class="btn btn-sm btn-outline-primary">Aplicar aos selecionados</button>'
            . Pagina::botaoIcone('copiar', 'Copiar para a pilha', 'btn-outline-secondary', ['extra' => 'name="modo" value="copiar" formaction="' . Painel::PREFIXO . '/pilha/adicionar"'])
            . Pagina::botaoIcone('recortar', 'Recortar para a pilha', 'btn-outline-secondary', ['extra' => 'name="modo" value="recortar" formaction="' . Painel::PREFIXO . '/pilha/adicionar"'])
            . '<span class="small text-body-secondary">Para mover, recorte para a pilha ou use o ícone de mover na linha.</span></form>';
    }

    private function acoesDaLinha(SessaoAtiva $sessao, Objeto $x, int $filhos, int $nivel, string $volta, string $csrf): string
    {
        $naLixeira = $x->status === self::LIXEIRA;
        $b = '';
        $item = $x->titulo;
        if (!$naLixeira && $this->pode($sessao, Acao::Editar, $x)) {
            $b .= Pagina::botaoIcone('editar', 'Editar', 'btn-primary bg-gradient', ['href' => $this->objeto($x->id) . '/editar', 'item' => $item]);
        }
        $b .= Pagina::botaoIcone('abrir', $filhos > 0 ? 'Abrir (' . $filhos . ')' : 'Abrir', 'btn-outline-secondary', ['href' => $this->link($x->id), 'item' => $item]);
        $publica = $this->urlPublica($x);
        $publicar = !$naLixeira && $this->pode($sessao, Acao::Publicar, $x);
        if ($publicar && $x->status === 'publicado') {
            $b .= $this->formularioUnico('despublicar', 'Despublicar', 'despublicar', 'btn-warning bg-gradient', $x, $nivel, $volta, $csrf);
        } elseif ($publicar && in_array($x->status, ['privado', 'rejeitado', 'submetido'], true)) {
            $b .= $this->formularioUnico('publicar', 'Publicar', 'publicar', 'btn-success bg-gradient', $x, $nivel, $volta, $csrf);
        } elseif (!$naLixeira && in_array($x->status, ['privado', 'rejeitado'], true) && $this->pode($sessao, Acao::Editar, $x)) {
            $b .= $this->formularioUnico('enviar', $x->status === 'rejeitado' ? 'Reenviar para revisão' : 'Enviar para revisão', 'enviar', 'btn-success bg-gradient', $x, $nivel, $volta, $csrf);
        }
        if ($publica !== null) {
            $b .= Pagina::botaoIcone('externo', 'Ver página pública', 'btn-outline-secondary', ['href' => $publica, 'externo' => true, 'item' => $item]);
        }
        $b .= Pagina::botaoIcone('historico', 'Histórico', 'btn-outline-secondary', ['href' => $this->objeto($x->id) . '/versoes', 'item' => $item]);
        if (!$naLixeira && $x->paiId !== null) {
            $b .= $this->formularioDePilha('copiar', 'Copiar para a pilha', 'copiar', $x, $nivel, $csrf);
            if ($this->pode($sessao, Acao::Editar, $x)) {
                $b .= $this->formularioDePilha('recortar', 'Recortar para a pilha', 'recortar', $x, $nivel, $csrf);
            }
        }
        if (!$naLixeira && $x->paiId !== null && $this->pode($sessao, Acao::Apagar, $x)) {
            $b .= Pagina::botaoIcone('mover', 'Mover', 'btn-outline-secondary', ['href' => $this->objeto($x->id) . '/mover', 'item' => $item])
                . Pagina::botaoIcone('excluir', 'Excluir (lixeira)', 'btn-danger bg-gradient', ['href' => $this->objeto($x->id) . '/apagar', 'item' => $item]);
        }
        if ($naLixeira && $this->pode($sessao, Acao::Apagar, $x)) {
            $b .= '<form method="post" action="' . $this->objeto($x->id) . '/recuperar">' . $csrf . '<input type="hidden" name="versao" value="' . $x->versao . '">'
                . Pagina::botaoIcone('restaurar', 'Tirar da lixeira', 'btn-outline-dark', ['item' => $item]) . '</form>';
        }

        return '<div class="pbl-linha-acoes">' . $b . '</div>';
    }

    private function formularioDePilha(string $modo, string $rotulo, string $icone, Objeto $x, int $nivel, string $csrf): string
    {
        return '<form method="post" action="' . Painel::PREFIXO . '/pilha/adicionar">' . $csrf
            . '<input type="hidden" name="modo" value="' . $modo . '"><input type="hidden" name="ids[]" value="' . $x->id . '"><input type="hidden" name="versao[' . $x->id . ']" value="' . $x->versao . '">'
            . '<input type="hidden" name="nivel" value="' . $nivel . '">' . Pagina::botaoIcone($icone, $rotulo, 'btn-outline-secondary', ['item' => $x->titulo]) . '</form>';
    }

    /**
     * A faixa da pilha (fase 5r, ADR 0025): só aparece quando há itens separados. "Colar aqui" leva à previsão deste nível como
     * destino (só para quem pode criar aqui); "Ver a pilha" lista os itens; "Esvaziar" limpa.
     */
    private function faixaDaPilha(SessaoAtiva $sessao, Objeto $atual): string
    {
        $itens = $this->servicos->pilha->itens($sessao->usuario->id);
        if ($itens === []) {
            return '';
        }
        $recortar = $itens[0]['modo'] === 'recortar';
        $botoes = '';
        if ($atual->status !== self::LIXEIRA && $this->servicos->permissoes->podeCriarSob($sessao->usuario, $atual) && $atual->siteId === $itens[0]['site_id']) {
            $botoes .= Pagina::botaoIcone('colar', 'Colar aqui', 'btn-success bg-gradient', ['href' => Painel::PREFIXO . '/pilha/colar/' . $atual->id]);
        }
        $botoes .= '<a class="btn btn-sm btn-outline-secondary" href="' . Painel::PREFIXO . '/pilha">Ver a pilha</a> '
            . '<form method="post" action="' . Painel::PREFIXO . '/pilha/esvaziar" class="d-inline">' . $this->campoCsrf($sessao) . '<input type="hidden" name="nivel" value="' . $atual->id . '">'
            . '<button type="submit" class="btn btn-sm btn-outline-danger bg-gradient">Esvaziar</button></form>';

        return '<div class="alert alert-info d-flex flex-wrap align-items-center gap-2" role="status"><span>'
            . count($itens) . ($recortar ? (count($itens) === 1 ? ' item recortado' : ' itens recortados') : (count($itens) === 1 ? ' item copiado' : ' itens copiados')) . ' na pilha.</span>'
            . '<span class="ms-auto d-flex flex-wrap align-items-center gap-2">' . $botoes . Pagina::ajuda('ajuda-pilha', 'Pilha', TelasDePilha::ajudaDaPilha()) . '</span></div>';
    }

    private function formularioUnico(string $acao, string $rotulo, string $icone, string $classe, Objeto $x, int $nivel, string $volta, string $csrf): string
    {
        return '<form method="post" action="' . Painel::PREFIXO . '/conteudo/acao" class="d-inline">' . $csrf
            . '<input type="hidden" name="acao" value="' . $acao . '"><input type="hidden" name="ids[]" value="' . $x->id . '"><input type="hidden" name="versao[' . $x->id . ']" value="' . $x->versao . '">'
            . '<input type="hidden" name="nivel" value="' . $nivel . '"><input type="hidden" name="volta" value="' . Pagina::h($volta) . '">'
            . '' . Pagina::botaoIcone($icone, $rotulo, $classe, ['item' => $x->titulo]) . '</form>';
    }

    /** @param array{q: string, estado: string, classe: int|null, ordem: string, inicio: int, por: int} $f */
    private function paginacao(Objeto $o, array $f, int $total, int $nestaPagina): string
    {
        if ($total <= $f['por']) {
            return '<p class="text-body-secondary small">' . $total . ($total === 1 ? ' item.' : ' itens.') . '</p>';
        }
        $base = $this->consultaDe($f);
        $url = fn (int $inicio): string => Pagina::h($this->link($o->id) . '?' . http_build_query($base + ['inicio' => (string) $inicio]));
        $html = '<nav aria-label="Páginas da lista"><p class="text-body-secondary small mb-1">Mostrando ' . ($f['inicio'] + 1) . ' a ' . ($f['inicio'] + $nestaPagina) . ' de ' . $total . '.</p><ul class="pagination pagination-sm">';
        if ($f['inicio'] > 0) {
            $html .= '<li class="page-item"><a class="page-link" href="' . $url(max(0, $f['inicio'] - $f['por'])) . '">Anteriores</a></li>';
        }
        if ($f['inicio'] + $f['por'] < $total) {
            $html .= '<li class="page-item"><a class="page-link" href="' . $url($f['inicio'] + $f['por']) . '">Próximos</a></li>';
        }

        return $html . '</ul></nav>';
    }

    /**
     * Só o que difere do padrão, para a URL ficar curta.
     *
     * @param array{q: string, estado: string, classe: int|null, ordem: string, inicio: int, por: int} $f
     * @return array<string, string>
     */
    private function consultaDe(array $f): array
    {
        return array_filter([
            'q' => $f['q'], 'estado' => $f['estado'], 'classe' => $f['classe'] === null ? '' : (string) $f['classe'],
            'ordem' => $f['ordem'] === 'peso' ? '' : $f['ordem'], 'por' => $f['por'] === self::POR_PAGINA_PADRAO ? '' : (string) $f['por'],
        ], static fn (string $v): bool => $v !== '');
    }

    // ---------------------------------------------------------------- ações (uma ou em lote)

    private function acao(SessaoAtiva $sessao, Request $r): Response
    {
        if (!$this->postConfere($sessao, $r)) {
            return $this->recusado();
        }
        $c = $r->request->all();
        $acao = is_string($c['acao'] ?? null) ? $c['acao'] : '';
        $ids = $this->idsDoPedido($c['ids'] ?? null);
        $versoes = is_array($c['versao'] ?? null) ? $c['versao'] : [];
        $nivel = $this->numero($c['nivel'] ?? null);
        $volta = $this->voltaSegura($c['volta'] ?? null);
        if (!in_array($acao, self::ACOES, true) || $ids === null || $ids === []) {
            return $ids === [] ? new RedirectResponse($this->destino($sessao, $nivel, $volta, ['ok' => 'nada']), Response::HTTP_SEE_OTHER) : $this->incompleto($sessao);
        }
        if ($acao === 'excluir' && ($c['confirmar'] ?? '') !== '1') {
            return $this->confirmarExclusao($sessao, $ids, $versoes, $nivel, $volta);
        }
        $feitos = 0;
        $ignorados = 0;
        $tocados = [];
        foreach ($ids as $id) {
            $versao = $this->numero($versoes[$id] ?? null);
            $novo = $versao === null || $versao < 1 ? null : $this->aplicar($sessao, $acao, $id, $versao);
            if ($novo === null) {
                ++$ignorados;
                continue;
            }
            ++$feitos;
            $tocados[] = $novo;
        }
        $sincronizado = $this->sincronizar($tocados);

        return new RedirectResponse($this->destino($sessao, $nivel, $volta, ['ok' => $acao, 'n' => (string) $feitos, 'f' => (string) $ignorados] + ($sincronizado ? [] : ['s' => '0'])), Response::HTTP_SEE_OTHER);
    }

    /** Aplica a ação a um objeto; devolve o objeto novo, ou null se foi ignorado (sem permissão, inexistente, estado errado ou versão velha). */
    private function aplicar(SessaoAtiva $sessao, string $acao, int $id, int $versao): ?Objeto
    {
        $u = $sessao->usuario;
        $o = $this->servicos->objetos->porId($id);
        if ($o === null) {
            return null;
        }
        $exigida = match ($acao) {
            'publicar', 'despublicar' => Acao::Publicar,
            'excluir' => Acao::Apagar,
            default => Acao::Editar,
        };
        if (!$this->servicos->permissoes->pode($u, $exigida, $o)) {
            $this->servicos->auditoria->registrar('objeto.lote.negado', 'usuario:' . $u->id, $u->id, $o->id, ['acao' => $acao]);

            return null;
        }
        if ($o->status === self::LIXEIRA) {
            return null;
        }
        try {
            switch ($acao) {
                case 'publicar':
                    return $o->status === 'publicado' ? null : $this->servicos->objetos->atualizar($o->id, ['status' => 'publicado'], TelasDeObjetos::MOTIVO, $versao, false, $u->id);
                case 'despublicar':
                    return $o->status !== 'publicado' ? null : $this->servicos->objetos->atualizar($o->id, ['status' => 'privado'], TelasDeObjetos::MOTIVO, $versao, false, $u->id);
                case 'enviar':
                    return $this->servicos->revisao->enviar($u, $o->id, $versao);
                default:
                    if ($o->paiId === null) {
                        return null;
                    }

                    return $this->servicos->objetos->mandarParaLixeira($o->id, $versao, TelasDeObjetos::MOTIVO, $u->id);
            }
        } catch (ConflitoDeVersao|ConflitoDeArvore|ErroDeRevisao|\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Confirmação antes de mandar vários para a lixeira: lista só o que a pessoa pode apagar (nada de título alheio) e repete o CSRF.
     *
     * @param list<int> $ids
     * @param array<mixed> $versoes
     */
    private function confirmarExclusao(SessaoAtiva $sessao, array $ids, array $versoes, ?int $nivel, string $volta): Response
    {
        $itens = '';
        $campos = '';
        foreach ($ids as $id) {
            $o = $this->servicos->objetos->porId($id);
            $versao = $this->numero($versoes[$id] ?? null);
            if ($o === null || $versao === null || $o->paiId === null || $o->status === self::LIXEIRA || !$this->servicos->permissoes->pode($sessao->usuario, Acao::Apagar, $o)) {
                continue;
            }
            $itens .= '<li>' . Pagina::h($o->titulo) . '</li>';
            $campos .= '<input type="hidden" name="ids[]" value="' . $o->id . '"><input type="hidden" name="versao[' . $o->id . ']" value="' . $versao . '">';
        }
        $voltar = $this->destino($sessao, $nivel, $volta, []);
        if ($itens === '') {
            return Pagina::resposta('Mandar para a lixeira', Pagina::alerta('warning', 'Nada do que foi marcado pode ir para a lixeira por você.') . Pagina::acoes('', [['Voltar', $voltar]]), Response::HTTP_OK, false, $this->barra($sessao));
        }
        $corpo = '<p>Mandar para a lixeira? Eles saem do ar na hora; nada é apagado de vez e dá para tirar da lixeira depois. Quem ainda tem conteúdo dentro será ignorado.</p><ul>' . $itens . '</ul>'
            . '<form method="post" action="' . Painel::PREFIXO . '/conteudo/acao">' . $this->campoCsrf($sessao) . $campos
            . '<input type="hidden" name="acao" value="excluir"><input type="hidden" name="confirmar" value="1"><input type="hidden" name="nivel" value="' . (int) $nivel . '"><input type="hidden" name="volta" value="' . Pagina::h($volta) . '">'
            . Pagina::acoes('Mandar para a lixeira', [['Cancelar', $voltar]], null, 'btn-danger') . '</form>';

        return Pagina::resposta('Mandar para a lixeira', $corpo, Response::HTTP_OK, false, $this->barra($sessao));
    }

    /**
     * @param list<Objeto> $objetos
     */
    private function sincronizar(array $objetos): bool
    {
        $ok = true;
        $agora = gmdate('c');
        // Cada objeto afetado e cada pai entram uma única vez, relidos do banco (o estado vigente, não o do momento da gravação).
        $alvos = [];
        foreach ($objetos as $gravado) {
            $alvos[$gravado->id] = true;
            if ($gravado->paiId !== null) {
                $alvos[$gravado->paiId] = true;
            }
        }
        foreach (array_keys($alvos) as $id) {
            $alvo = $this->servicos->objetos->porId($id);
            $site = $alvo === null ? null : $this->servicos->sites->porId($alvo->siteId);
            if ($alvo === null || $site === null) {
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

    /** @return list<int>|null null = formato inválido; lista vazia = nada marcado */
    private function idsDoPedido(mixed $v): ?array
    {
        if ($v === null) {
            return [];
        }
        if (!is_array($v) || count($v) > self::MAX_LOTE) {
            return null;
        }
        $ids = [];
        foreach ($v as $item) {
            $n = $this->numero($item);
            if ($n === null || $n < 1) {
                return null;
            }
            $ids[$n] = $n;
        }

        return array_values($ids);
    }

    /** Filtros de volta: só as chaves conhecidas, com os mesmos limites da tela. */
    private function voltaSegura(mixed $v): string
    {
        if (!is_string($v) || $v === '' || strlen($v) > 500) {
            return '';
        }
        parse_str($v, $q);

        return http_build_query($this->consultaDe($this->filtros(array_intersect_key($q, array_flip(['q', 'estado', 'classe', 'ordem', 'por'])))));
    }

    /** @param array<string, string> $extra */
    private function destino(SessaoAtiva $sessao, ?int $nivel, string $volta, array $extra): string
    {
        $o = $nivel === null ? null : $this->servicos->objetos->porId($nivel);
        if ($o === null || !$this->pode($sessao, Acao::Ver, $o)) {
            return Painel::PREFIXO . '/conteudo' . ($extra === [] ? '' : '?' . http_build_query($extra));
        }
        parse_str($volta, $base);

        return $this->link($o->id) . ($base === [] && $extra === [] ? '' : '?' . http_build_query($base + $extra));
    }

    /** @param array<string, mixed> $q */
    private function avisoDoResultado(array $q): string
    {
        $ok = $q['ok'] ?? null;
        if (!is_string($ok)) {
            return '';
        }
        if ($ok === 'nada') {
            return Pagina::alerta('secondary', 'Nada foi marcado.');
        }
        if (str_starts_with($ok, 'pilha_')) {
            return TelasDePilha::aviso($q);
        }
        $verbo = ['publicar' => ['publicado', 'publicados'], 'despublicar' => ['despublicado', 'despublicados'], 'enviar' => ['enviado para revisão', 'enviados para revisão'], 'excluir' => ['enviado para a lixeira', 'enviados para a lixeira']][$ok] ?? null;
        $n = $this->numero($q['n'] ?? null);
        $f = $this->numero($q['f'] ?? null) ?? 0;
        if ($verbo === null || $n === null) {
            return '';
        }
        $html = $n > 0 ? Pagina::alerta('success', $n . ' ' . ($n === 1 ? $verbo[0] : $verbo[1]) . '.') : '';
        if ($f > 0) {
            $html .= Pagina::alerta('warning', $f . ($f === 1 ? ' item foi ignorado' : ' itens foram ignorados') . ': sem permissão, já no estado pedido, alterado por outra pessoa desde que a lista abriu, ou com conteúdo dentro (no caso da lixeira). Recarregue e tente de novo.');
        }
        if (($q['s'] ?? null) === '0') {
            $html .= Pagina::alerta('warning', 'A mudança foi salva, mas alguma página pública não pôde ser atualizada agora. Publique o site de novo.');
        }

        return $html;
    }

    // ---------------------------------------------------------------- apoio

    private function selo(Objeto $o, string $agora): string
    {
        [$texto, $classe] = match (true) {
            $o->status === self::LIXEIRA => ['na lixeira', 'text-bg-danger'],
            $o->status === 'publicado' && $o->dataPublicacao !== null && $o->dataPublicacao > $agora => ['agendado para ' . $this->data($o->dataPublicacao), 'text-bg-info'],
            $o->status === 'publicado' && $o->dataValidade !== null && $o->dataValidade < $agora => ['expirado', 'text-bg-warning'],
            $o->status === 'publicado' => ['publicado', 'text-bg-success'],
            $o->status === 'submetido' => ['em revisão', 'text-bg-warning'],
            $o->status === 'rejeitado' => ['devolvido', 'text-bg-warning'],
            default => ['privado', 'text-bg-secondary'],
        };

        return '<span class="badge ' . $classe . '">' . Pagina::h($texto) . '</span>';
    }

    private function data(string $gravada): string
    {
        if ($gravada === '') {
            return '—';
        }
        try {
            return (new \DateTimeImmutable($gravada))->setTimezone(new \DateTimeZone(self::FUSO))->format('d/m/Y H:i');
        } catch (\Exception) {
            return '—';
        }
    }

    private function urlPublica(Objeto $o): ?string
    {
        if (!$o->visivel(gmdate('c'))) {
            return null;
        }
        $base = $this->servicos->enderecos->base($o->siteId);

        return $base === '' ? null : $base . '/' . ($o->urlAmigavel === '' ? '' : ltrim($o->urlAmigavel, '/'));
    }

    private function aceitaFilhos(Objeto $pai): bool
    {
        $classe = $this->servicos->classes->porId($pai->classeId);
        if ($classe === null) {
            return false;
        }
        foreach ($this->servicos->classes->todas() as $c) {
            if ($classe->aceita($c)) {
                return true;
            }
        }

        return false;
    }

    private function nomeDe(int $usuarioId): string
    {
        return $this->nomesDeUsuario[$usuarioId] ??= $this->servicos->usuarios->porId($usuarioId)->nome ?? 'conta removida';
    }

    /** @var array<int, string> */
    private array $iconesDeClasse = [];

    private function iconeDaClasse(int $classeId): string
    {
        $this->iconesDeClasse[$classeId] ??= IconesDeClasse::svg($this->servicos->classes->porId($classeId)->icone ?? '');

        return $this->iconesDeClasse[$classeId] === '' ? '' : $this->iconesDeClasse[$classeId] . ' ';
    }

    private function nomeDaClasse(int $classeId): string
    {
        return $this->nomesDeClasse[$classeId] ??= $this->servicos->classes->porId($classeId)->nome ?? '?';
    }

    private function pode(SessaoAtiva $sessao, Acao $acao, Objeto $o): bool
    {
        return $this->servicos->permissoes->pode($sessao->usuario, $acao, $o);
    }

    private function semAcesso(SessaoAtiva $sessao): Response
    {
        return Pagina::resposta('Sem acesso', Pagina::alerta('danger', 'Você não pode ver este conteúdo, ou ele não existe.') . '<p><a class="btn btn-outline-secondary" href="' . Painel::PREFIXO . '/conteudo">Voltar ao Conteúdo</a></p>', Response::HTTP_FORBIDDEN, false, $this->barra($sessao));
    }

    private function opcao(string $valor, string $rotulo, bool $escolhida): string
    {
        return '<option value="' . Pagina::h($valor) . '"' . ($escolhida ? ' selected' : '') . '>' . Pagina::h($rotulo) . '</option>';
    }

    private function link(int $id): string
    {
        return Painel::PREFIXO . '/conteudo/' . $id;
    }

    private function objeto(int $id): string
    {
        return Painel::PREFIXO . '/objeto/' . $id;
    }
}
