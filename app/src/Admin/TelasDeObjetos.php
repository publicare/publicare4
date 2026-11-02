<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Acao;
use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Caminhos;
use Pbl\Busca\Texto;
use Pbl\Consulta\Consulta;
use Pbl\Consulta\Filtro;
use Pbl\Conteudo\Classe;
use Pbl\Conteudo\HtmlSeguro;
use Pbl\Conteudo\ViolacaoDeRegra;
use Pbl\Conteudo\ConflitoDeArvore;
use Pbl\Conteudo\ConflitoDeVersao;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telas de um conteúdo na administração (ADR 0012, fatias 5a e 5b): ver com a lista de filhos (ordem e filtros), criar filho,
 * editar com as propriedades da classe, estado e datas, mover, mandar para a lixeira e tirar dela, histórico de versões e
 * restauração. Toda rota recebe a sessão já validada pelo `Painel` e decide com `Permissoes::pode`, com a ação fixa no
 * código; todo POST confere origem e CSRF antes de qualquer outra coisa; toda saída é escapada; a gravação passa só pelo
 * `ObjetoRepositorio` (versão otimista, histórico e auditoria com nomes de campo).
 */
final class TelasDeObjetos
{
    public const POR_PAGINA = 100;
    public const MAX_TITULO = 300;
    public const MAX_DESCRICAO = 2000;
    /** Teto de uma propriedade `texto` e de uma `html` (em caracteres). */
    public const MAX_TEXTO = 10000;
    public const MAX_HTML = 200000;
    /** Até quantas versões a tela de histórico mostra (as mais novas). */
    public const MAX_VERSOES = 200;
    /** Motivo gravado na versão e na auditoria: fixo, nunca texto digitado. */
    public const MOTIVO = 'administracao';
    /** Fuso em que as datas de publicação e validade são digitadas e mostradas (gravadas em UTC). */
    public const FUSO = 'America/Sao_Paulo';

    /** Sub-rota de `/_pbl/objeto/{id}` => métodos aceitos. O resto é 404. */
    private const ROTAS = [
        '' => ['GET'], 'editar' => ['GET', 'POST'], 'novo' => ['GET', 'POST'], 'estado' => ['GET', 'POST'],
        'mover' => ['GET', 'POST'], 'apagar' => ['GET', 'POST'], 'recuperar' => ['POST'], 'versoes' => ['GET'], 'restaurar' => ['POST'],
    ];
    /** Ordens da lista de filhos: chave da URL => [rótulo, ordem da Consulta]. Só estas chegam ao motor. */
    private const ORDENS = [
        'peso' => ['Peso e título', 'peso, titulo'],
        'titulo' => ['Título (A a Z)', 'titulo'],
        'recentes' => ['Alterados por último', '-atualizado_em'],
        'publicacao' => ['Data de publicação (mais nova primeiro)', '-data_publicacao'],
    ];
    /** Estados que a tela de estado oferece (o `apagado` é a lixeira, com tela própria). */
    private const ESTADOS = [
        'privado' => 'Privado (fora do ar)', 'submetido' => 'Enviado para revisão (fora do ar)',
        'rejeitado' => 'Devolvido ao autor (fora do ar)', 'publicado' => 'Publicado',
    ];
    private const LIXEIRA = 'apagado';

    /** @var array<int, string> nomes de classe já lidos nesta requisição */
    private array $classes = [];

    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
    ) {
    }

    /** A sub-rota existe para esse método? (conferido pelo `Painel` antes da sessão: rota desconhecida é 404 para todos) */
    public static function atende(string $subrota, string $metodo): bool
    {
        return in_array($metodo, self::ROTAS[$subrota] ?? [], true);
    }

    public function tratar(SessaoAtiva $sessao, int $id, string $subrota, Request $r): Response
    {
        $post = $r->getMethod() === 'POST';

        return match ($subrota) {
            '' => $this->ver($sessao, $id, $r),
            'editar' => $post ? $this->salvar($sessao, $id, $r) : $this->formulario($sessao, $id),
            'novo' => $post ? $this->criar($sessao, $id, $r) : $this->formularioNovo($sessao, $id, $r),
            'estado' => $post ? $this->salvarEstado($sessao, $id, $r) : $this->formularioEstado($sessao, $id),
            'mover' => $post ? $this->mover($sessao, $id, $r) : $this->formularioMover($sessao, $id, $r),
            'apagar' => $post ? $this->apagar($sessao, $id, $r) : $this->confirmarApagar($sessao, $id),
            'recuperar' => $this->recuperar($sessao, $id, $r),
            'versoes' => $this->versoes($sessao, $id),
            default => $this->restaurar($sessao, $id, $r),
        };
    }

    // ---------------------------------------------------------------- ver

    /** Um objeto, o caminho até ele, as ações que a pessoa pode fazer e os filhos (ordem, filtro por classe e estado, paginação). */
    private function ver(SessaoAtiva $sessao, int $id, Request $r): Response
    {
        $o = $this->servicos->objetos->porId($id);
        if ($o === null || !$this->pode($sessao, Acao::Ver, $o)) {
            return $this->semAcesso($sessao);
        }
        $q = $r->query->all();
        $inicio = $this->numero($q['inicio'] ?? null) ?? 0;
        $ordem = is_string($q['ordem'] ?? null) && isset(self::ORDENS[$q['ordem']]) ? $q['ordem'] : 'peso';
        $classes = $this->servicos->classes->todas();
        $classeId = $this->numero($q['classe'] ?? null);
        if ($classeId !== null && !in_array($classeId, array_map(static fn (Classe $c): int => $c->id, $classes), true)) {
            $classeId = null;
        }
        $estado = is_string($q['estado'] ?? null) && in_array($q['estado'], ObjetoRepositorio::STATUS, true) ? $q['estado'] : '';
        $filtros = [$estado === '' ? new Filtro('status', '!=', self::LIXEIRA) : new Filtro('status', '=', $estado)];
        $consulta = new Consulta($o->siteId, $o->id, 1, $classeId === null ? null : [$classeId], $filtros, self::ORDENS[$ordem][1], $inicio, self::POR_PAGINA, false);
        $filhos = $this->servicos->motor->buscar($consulta);
        $total = $this->servicos->motor->contar($consulta);
        $agora = gmdate('c');

        $corpo = $this->migalhas($sessao, $o) . $this->aviso($q['salvo'] ?? null);
        if ($o->status === self::LIXEIRA) {
            $corpo .= '<div class="alert alert-warning" role="status">Este conteúdo está na lixeira: fora do ar e sem edição. ';
            if ($this->pode($sessao, Acao::Apagar, $o)) {
                $corpo .= '<form method="post" action="' . $this->link($o->id) . '/recuperar" class="d-inline">' . $this->camposOcultos($sessao, $o->versao)
                    . '<button type="submit" class="btn btn-sm btn-outline-dark">Tirar da lixeira</button></form>';
            }
            $corpo .= '</div>';
        }
        $corpo .= $this->tabelaDeDados($sessao, $o, $agora) . $this->botoes($sessao, $o);

        $corpo .= '<h2 class="h5 mt-4">Conteúdo dentro desta página</h2>' . $this->filtrosDaLista($o, $classes, $ordem, $classeId, $estado);
        if ($filhos === []) {
            $corpo .= '<p class="text-body-secondary">Nenhum.</p>';
        } else {
            $corpo .= '<div class="table-responsive"><table class="table table-sm table-hover align-middle"><caption class="visually-hidden">Conteúdo dentro desta página</caption>'
                . '<thead><tr><th scope="col">Título</th><th scope="col">Tipo</th><th scope="col">Estado</th><th scope="col">Peso</th></tr></thead><tbody>';
            foreach ($filhos as $f) {
                $corpo .= '<tr><td><a href="' . $this->link($f->id) . '">' . Pagina::h($f->titulo) . '</a></td>'
                    . '<td>' . Pagina::h($this->nomeDaClasse($f->classeId)) . '</td>'
                    . '<td>' . Pagina::h($f->visivel($agora) ? $f->status : $this->estadoLegivel($f, $agora, true)) . '</td>'
                    . '<td>' . $f->peso . '</td></tr>';
            }
            $corpo .= '</tbody></table></div>';
            if ($total > self::POR_PAGINA) {
                $base = $this->link($o->id) . '?' . http_build_query(array_filter(['ordem' => $ordem === 'peso' ? '' : $ordem, 'classe' => $classeId === null ? '' : (string) $classeId, 'estado' => $estado], static fn (string $v): bool => $v !== ''));
                $corpo .= '<p class="text-body-secondary">Mostrando ' . ($inicio + 1) . ' a ' . min($total, $inicio + count($filhos)) . ' de ' . $total . '.';
                if ($inicio > 0) {
                    $corpo .= ' <a href="' . Pagina::h($base . '&inicio=' . max(0, $inicio - self::POR_PAGINA)) . '">Anteriores</a>';
                }
                if ($inicio + self::POR_PAGINA < $total) {
                    $corpo .= ' <a href="' . Pagina::h($base . '&inicio=' . ($inicio + self::POR_PAGINA)) . '">Próximos</a>';
                }
                $corpo .= '</p>';
            }
        }

        return Pagina::resposta($o->titulo, $corpo, Response::HTTP_OK, false, $this->barra($sessao));
    }

    private function aviso(mixed $salvo): string
    {
        $texto = match ($salvo) {
            'sim' => ['success', 'Alterações salvas.'],
            'nada' => ['secondary', 'Nada mudou.'],
            'aviso' => ['warning', 'Alterações salvas, mas alguma página não pôde ser atualizada agora. Publique o site de novo (bin/pbl publicar).'],
            'criado' => ['success', 'Conteúdo criado. Ele começa fora do ar (privado); publique quando estiver pronto.'],
            'estado' => ['success', 'Estado e datas salvos.'],
            'agendado' => ['info', 'Estado salvo e publicação agendada: a página entra no ar na data marcada, quando o agendamento do servidor rodar (bin/pbl agendados, de preferência no cron). Até lá ela fica fora do ar.'],
            'movido' => ['success', 'Conteúdo movido.'],
            'apagado' => ['success', 'Conteúdo enviado para a lixeira. Para ver a lixeira, filtre a lista pelo estado "Na lixeira".'],
            'recuperado' => ['success', 'Conteúdo tirado da lixeira. Ele volta fora do ar (privado).'],
            'restaurado' => ['success', 'Versão restaurada (gravada como uma versão nova).'],
            default => null,
        };

        return $texto === null ? '' : '<div class="alert alert-' . $texto[0] . '" role="status">' . Pagina::h($texto[1]) . '</div>';
    }

    private function tabelaDeDados(SessaoAtiva $sessao, Objeto $o, string $agora): string
    {
        $html = '<table class="table table-sm"><caption class="visually-hidden">Dados deste conteúdo</caption><tbody>'
            . '<tr><th scope="row">Número</th><td>' . $o->id . '</td></tr>'
            . '<tr><th scope="row">Tipo</th><td>' . Pagina::h($this->nomeDaClasse($o->classeId)) . '</td></tr>'
            . '<tr><th scope="row">Estado</th><td>' . Pagina::h($this->estadoLegivel($o, $agora)) . '</td></tr>'
            . '<tr><th scope="row">Endereço</th><td><code>/' . Pagina::h($o->urlAmigavel) . '</code></td></tr>'
            . '<tr><th scope="row">Peso</th><td>' . $o->peso . '</td></tr>';
        if ($o->dataPublicacao !== null) {
            $html .= '<tr><th scope="row">Publicação a partir de</th><td>' . Pagina::h($this->dataLegivel($o->dataPublicacao)) . '</td></tr>';
        }
        if ($o->dataValidade !== null) {
            $html .= '<tr><th scope="row">Fora do ar a partir de</th><td>' . Pagina::h($this->dataLegivel($o->dataValidade)) . '</td></tr>';
        }
        $html .= '<tr><th scope="row">Versão</th><td>' . $o->versao . '</td></tr>'
            . '<tr><th scope="row">Descrição</th><td>' . ($o->descricao === '' ? '<em>sem descrição</em>' : nl2br(Pagina::h($o->descricao))) . '</td></tr>';
        foreach ($this->classeDe($o)->propriedades ?? [] as $p) {
            $valor = $o->dados[$p['nome']] ?? null;
            $html .= '<tr><th scope="row">' . Pagina::h(Classe::rotuloDe($p)) . '</th><td>' . $this->valorLegivel($sessao, $o, $p, $valor) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * O estado em palavras, com o agendamento: "publicado, agendado para…" (data de publicação no futuro) ou "publicado, vencido
     * em…" (data de validade passada). `$curto` tira o "(fora do ar)" repetido da lista.
     */
    private function estadoLegivel(Objeto $o, string $agora, bool $curto = false): string
    {
        if ($o->status === 'publicado' && $o->dataPublicacao !== null && $o->dataPublicacao > $agora) {
            return 'publicado, agendado para ' . $this->dataLegivel($o->dataPublicacao) . ' (fora do ar até lá)';
        }
        if ($o->status === 'publicado' && $o->dataValidade !== null && $o->dataValidade < $agora) {
            return 'publicado, vencido em ' . $this->dataLegivel($o->dataValidade) . ' (fora do ar)';
        }

        return $o->status . ($o->visivel($agora) ? ($curto ? '' : ' (visível ao público)') : ' (fora do ar)');
    }

    /**
     * @param array{nome: string, tipo: string, indexavel: bool, classe?: string} $p
     */
    private function valorLegivel(SessaoAtiva $sessao, Objeto $o, array $p, string|int|float|null $valor): string
    {
        if ($valor === null || $valor === '') {
            return '<em>vazio</em>';
        }
        if ($p['tipo'] === 'booleano') {
            return (int) $valor === 1 ? 'Sim' : 'Não';
        }
        if ($p['tipo'] === 'ref') {
            $alvo = $this->servicos->objetos->porId((int) $valor);
            if ($alvo !== null && $alvo->siteId === $o->siteId && $this->pode($sessao, Acao::Ver, $alvo)) {
                return '<a href="' . $this->link($alvo->id) . '">' . Pagina::h($alvo->titulo) . '</a> (nº ' . $alvo->id . ')';
            }

            return 'nº ' . (int) $valor;
        }
        $texto = (string) $valor;
        $curto = mb_strlen($texto) > 300 ? mb_substr($texto, 0, 300) . '…' : $texto;

        // HTML guardado aparece como texto (nunca interpretado na administração).
        return $p['tipo'] === 'html' ? '<code class="text-break">' . Pagina::h($curto) . '</code>' : nl2br(Pagina::h($curto));
    }

    /** Botões das ações que a pessoa pode fazer aqui (esconder é só conforto: cada rota confere de novo). */
    private function botoes(SessaoAtiva $sessao, Objeto $o): string
    {
        $links = [];
        $naLixeira = $o->status === self::LIXEIRA;
        if (!$naLixeira && $this->pode($sessao, Acao::Editar, $o)) {
            $links[] = '<a class="btn btn-primary" href="' . $this->link($o->id) . '/editar">Editar</a>';
        }
        if (!$naLixeira && $this->servicos->permissoes->podeCriarSob($sessao->usuario, $o) && $this->classesAceitas($o) !== []) {
            $links[] = '<a class="btn btn-outline-primary" href="' . $this->link($o->id) . '/novo">Criar conteúdo aqui dentro</a>';
        }
        if (!$naLixeira && $this->pode($sessao, Acao::Publicar, $o)) {
            $links[] = '<a class="btn btn-outline-primary" href="' . $this->link($o->id) . '/estado">Publicar ou tirar do ar</a>';
        }
        if (!$naLixeira && $o->paiId !== null && $this->pode($sessao, Acao::Apagar, $o)) {
            $links[] = '<a class="btn btn-outline-secondary" href="' . $this->link($o->id) . '/mover">Mover</a>';
            $links[] = '<a class="btn btn-outline-danger" href="' . $this->link($o->id) . '/apagar">Mandar para a lixeira</a>';
        }
        $links[] = '<a class="btn btn-outline-secondary" href="' . $this->link($o->id) . '/versoes">Histórico de versões</a>';

        return '<div class="d-flex flex-wrap gap-2 mb-3">' . implode('', $links) . '</div>';
    }

    /** @param list<Classe> $classes */
    private function filtrosDaLista(Objeto $o, array $classes, string $ordem, ?int $classeId, string $estado): string
    {
        $opcoesOrdem = '';
        foreach (self::ORDENS as $chave => [$rotulo]) {
            $opcoesOrdem .= $this->opcao($chave, $rotulo, $chave === $ordem);
        }
        $opcoesClasse = $this->opcao('', 'Todos os tipos', $classeId === null);
        foreach ($classes as $c) {
            $opcoesClasse .= $this->opcao((string) $c->id, $c->nome, $c->id === $classeId);
        }
        $opcoesEstado = $this->opcao('', 'Todos (menos a lixeira)', $estado === '');
        foreach (self::ESTADOS as $chave => $rotulo) {
            $opcoesEstado .= $this->opcao($chave, $rotulo, $chave === $estado);
        }
        $opcoesEstado .= $this->opcao(self::LIXEIRA, 'Na lixeira', $estado === self::LIXEIRA);

        return '<form method="get" action="' . $this->link($o->id) . '" class="row g-2 align-items-end mb-3" aria-label="Ordem e filtros da lista">'
            . '<div class="col-sm-4"><label for="campo-ordem" class="form-label">Ordem</label><select id="campo-ordem" name="ordem" class="form-select form-select-sm">' . $opcoesOrdem . '</select></div>'
            . '<div class="col-sm-3"><label for="campo-classe" class="form-label">Tipo</label><select id="campo-classe" name="classe" class="form-select form-select-sm">' . $opcoesClasse . '</select></div>'
            . '<div class="col-sm-3"><label for="campo-estado" class="form-label">Estado</label><select id="campo-estado" name="estado" class="form-select form-select-sm">' . $opcoesEstado . '</select></div>'
            . '<div class="col-sm-2"><button type="submit" class="btn btn-sm btn-outline-secondary w-100">Aplicar</button></div></form>';
    }

    // ---------------------------------------------------------------- editar

    private function formulario(SessaoAtiva $sessao, int $id): Response
    {
        $o = $this->servicos->objetos->porId($id);
        if ($o === null || !$this->pode($sessao, Acao::Editar, $o)) {
            return $this->semAcesso($sessao);
        }
        if ($o->status === self::LIXEIRA) {
            return $this->naLixeira($sessao, $o);
        }
        $classe = $this->classeDe($o);
        if ($classe === null) {
            return $this->classeSumiu($sessao);
        }

        return $this->telaDeEdicao($sessao, $o, $classe, $this->digitadoDe($o, $classe), $o->versao);
    }

    private function salvar(SessaoAtiva $sessao, int $id, Request $r): Response
    {
        $campos = $r->request->all();
        if (($recusa = $this->recusarPedido($sessao, $r)) !== null) {
            return $recusa;
        }
        $o = $this->autorizado($sessao, $id, Acao::Editar, 'objeto.editar.negado');
        if ($o === null) {
            return $this->semAcesso($sessao);
        }
        if ($o->status === self::LIXEIRA) {
            return $this->naLixeira($sessao, $o);
        }
        $classe = $this->classeDe($o);
        if ($classe === null) {
            return $this->classeSumiu($sessao);
        }
        $versao = $this->numero($campos['versao'] ?? null);
        $lido = $this->lerFormulario($sessao, $o->siteId, $classe, $campos, $o);
        if ($versao === null || $versao < 1 || $lido === null) {
            return $this->pedidoInvalido($sessao);
        }
        [$valores, $digitado, $erros] = $lido;
        if ($erros !== []) {
            return $this->telaDeEdicao($sessao, $o, $classe, $digitado, $versao, $erros, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $mudancas = [];
        foreach (['titulo' => $o->titulo, 'descricao' => $o->descricao, 'url_amigavel' => $o->urlAmigavel, 'peso' => $o->peso] as $campo => $atual) {
            if (array_key_exists($campo, $valores) && $valores[$campo] !== $atual) {
                $mudancas[$campo] = $valores[$campo];
            }
        }
        $dados = [];
        foreach ($valores['dados'] as $nome => $novo) {
            $atual = $o->dados[$nome] ?? null;
            if ($novo === null ? $atual !== null : ($atual === null || (string) $atual !== (string) $novo)) {
                $dados[$nome] = $novo;
            }
        }
        if ($dados !== []) {
            $mudancas['dados'] = $dados;
        }
        if ($mudancas === []) {
            return new RedirectResponse($this->link($o->id) . '?salvo=nada', Response::HTTP_SEE_OTHER);
        }
        try {
            $novo = $this->servicos->objetos->atualizar($o->id, $mudancas, self::MOTIVO, $versao, false, $sessao->usuario->id);
        } catch (ConflitoDeVersao) {
            $erro = 'Outra pessoa alterou este conteúdo depois que você abriu o formulário. O que está salvo agora aparece abaixo do formulário; '
                . 'o seu texto continua nos campos. Salve de novo só se quiser substituir a versão atual.';
            $atual = $this->servicos->objetos->porId($o->id) ?? $o;

            return $this->telaDeEdicao($sessao, $atual, $classe, $digitado, $atual->versao, ['_' => $erro], Response::HTTP_CONFLICT, true);
        } catch (ViolacaoDeRegra $e) {
            return $this->telaDeEdicao($sessao, $o, $classe, $digitado, $versao, $this->erroDaRegra($e), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\InvalidArgumentException) {
            return $this->telaDeEdicao($sessao, $o, $classe, $digitado, $versao, ['_' => 'Não foi possível salvar: algum valor não foi aceito. Confira os campos e tente de novo.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ok = true;
        if ($novo->urlAmigavel !== $o->urlAmigavel) {
            $ok = $this->tirarDoAr($o); // o endereço antigo passa a redirecionar (histórico de URLs)
        }
        $ok = $this->sincronizar($novo, $this->pai($novo)) && $ok;

        return new RedirectResponse($this->link($novo->id) . '?salvo=' . ($ok ? 'sim' : 'aviso'), Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, string> $digitado
     * @param array<string, string> $erros campo => mensagem (`_` = erro geral)
     */
    private function telaDeEdicao(SessaoAtiva $sessao, Objeto $o, Classe $classe, array $digitado, int $versao, array $erros = [], int $status = Response::HTTP_OK, bool $mostrarAtual = false): Response
    {
        $corpo = $this->migalhas($sessao, $o) . $this->resumoDeErros($erros)
            . '<form method="post" action="' . $this->link($o->id) . '/editar" novalidate>' . $this->camposOcultos($sessao, $versao)
            . $this->camposDoFormulario($classe, $digitado, $erros, $o->paiId === null)
            . '<button type="submit" class="btn btn-primary">Salvar</button> <a class="btn btn-outline-secondary" href="' . $this->link($o->id) . '">Cancelar</a></form>';
        if ($mostrarAtual) {
            $corpo .= '<h2 class="h5 mt-4">Salvo agora (versão ' . $o->versao . ')</h2><table class="table table-sm"><caption class="visually-hidden">Valores salvos agora</caption><tbody>'
                . '<tr><th scope="row">Título</th><td>' . Pagina::h($o->titulo) . '</td></tr>'
                . '<tr><th scope="row">Descrição</th><td>' . nl2br(Pagina::h($o->descricao)) . '</td></tr>'
                . '<tr><th scope="row">Endereço</th><td><code>/' . Pagina::h($o->urlAmigavel) . '</code></td></tr>'
                . '<tr><th scope="row">Peso</th><td>' . $o->peso . '</td></tr>';
            foreach ($classe->propriedades as $p) {
                $corpo .= '<tr><th scope="row">' . Pagina::h(Classe::rotuloDe($p)) . '</th><td>' . $this->valorLegivel($sessao, $o, $p, $o->dados[$p['nome']] ?? null) . '</td></tr>';
            }
            $corpo .= '</tbody></table>';
        }

        return Pagina::resposta('Editar: ' . $o->titulo, $corpo, $status, false, $this->barra($sessao));
    }

    // ---------------------------------------------------------------- criar

    private function formularioNovo(SessaoAtiva $sessao, int $paiId, Request $r): Response
    {
        $pai = $this->servicos->objetos->porId($paiId);
        if ($pai === null || !$this->servicos->permissoes->podeCriarSob($sessao->usuario, $pai)) {
            return $this->semAcesso($sessao);
        }
        if ($pai->status === self::LIXEIRA) {
            return $this->naLixeira($sessao, $pai);
        }
        $classeId = $this->numero($r->query->all()['classe'] ?? null);
        $classe = $classeId === null ? null : $this->servicos->classes->porId($classeId);
        if ($classe === null || !$this->aceita($pai, $classe)) {
            return $this->escolherClasse($sessao, $pai);
        }
        $vazio = ['titulo' => '', 'descricao' => '', 'url' => '', 'peso' => '0'];

        return $this->telaDeCriacao($sessao, $pai, $classe, $vazio);
    }

    private function escolherClasse(SessaoAtiva $sessao, Objeto $pai): Response
    {
        $classes = $this->classesAceitas($pai);
        if ($classes === []) {
            return Pagina::resposta('Criar conteúdo', $this->migalhas($sessao, $pai) . '<div class="alert alert-info" role="status">O tipo de ' . Pagina::h($pai->titulo) . ' não aceita conteúdo dentro dele (ou ainda não há tipos cadastrados).</div>'
                . '<p><a class="btn btn-outline-secondary" href="' . $this->link($pai->id) . '">Voltar</a></p>', Response::HTTP_OK, false, $this->barra($sessao));
        }
        $opcoes = '';
        foreach ($classes as $c) {
            $opcoes .= $this->opcao((string) $c->id, $c->nome, false);
        }
        $corpo = $this->migalhas($sessao, $pai)
            . '<p>O novo conteúdo vai ficar dentro de <strong>' . Pagina::h($pai->titulo) . '</strong>. Primeiro escolha o tipo: os campos do formulário dependem dele.</p>'
            . '<form method="get" action="' . $this->link($pai->id) . '/novo">'
            . '<div class="mb-3"><label for="campo-tipo" class="form-label">Tipo de conteúdo</label><select id="campo-tipo" name="classe" class="form-select" required>' . $opcoes . '</select></div>'
            . '<button type="submit" class="btn btn-primary">Continuar</button> <a class="btn btn-outline-secondary" href="' . $this->link($pai->id) . '">Cancelar</a></form>';

        return Pagina::resposta('Criar conteúdo', $corpo, Response::HTTP_OK, false, $this->barra($sessao));
    }

    private function criar(SessaoAtiva $sessao, int $paiId, Request $r): Response
    {
        $campos = $r->request->all();
        if (($recusa = $this->recusarPedido($sessao, $r)) !== null) {
            return $recusa;
        }
        $u = $sessao->usuario;
        $pai = $this->servicos->objetos->porId($paiId);
        if ($pai === null || !$this->servicos->permissoes->podeCriarSob($u, $pai)) {
            if ($pai !== null) {
                $this->servicos->auditoria->registrar('objeto.criar.negado', 'usuario:' . $u->id, $u->id, $pai->id);
            }

            return $this->semAcesso($sessao);
        }
        if ($pai->status === self::LIXEIRA) {
            return $this->naLixeira($sessao, $pai);
        }
        $classeId = $this->numero($campos['classe'] ?? null);
        $classe = $classeId === null ? null : $this->servicos->classes->porId($classeId);
        $lido = $classe === null ? null : $this->lerFormulario($sessao, $pai->siteId, $classe, $campos, null);
        if ($classe === null || $lido === null) {
            return $this->pedidoInvalido($sessao);
        }
        if (!$this->aceita($pai, $classe)) {
            return $this->recusa($sessao, 'Tipo não aceito', 'O tipo de ' . $pai->titulo . ' não aceita conteúdo do tipo ' . $classe->nome . ' dentro dele.', $pai, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        [$valores, $digitado, $erros] = $lido;
        $url = $valores['url_amigavel'] ?? '';
        if ($erros === [] && $url === '') {
            $url = $this->urlLivre($pai->siteId, $valores['titulo']) ?? '';
            if ($url === '') {
                $erros['url'] = 'Não foi possível criar um endereço a partir do título. Digite um endereço.';
            }
        }
        if ($erros !== []) {
            return $this->telaDeCriacao($sessao, $pai, $classe, $digitado, $erros, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $dados = array_filter($valores['dados'], static fn (string|int|float|null $v): bool => $v !== null);
        try {
            $novo = $this->servicos->objetos->criarDentro($pai->siteId, $classe, $pai->id, $valores['titulo'], $url, $dados, 'privado', [
                'peso' => $valores['peso'], 'descricao' => $valores['descricao'], 'criado_por' => $u->id,
            ]);
        } catch (ConflitoDeArvore) {
            return $this->naLixeira($sessao, $pai);
        } catch (ViolacaoDeRegra $e) {
            return $this->telaDeCriacao($sessao, $pai, $classe, $digitado, $this->erroDaRegra($e), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\InvalidArgumentException|\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            return $this->telaDeCriacao($sessao, $pai, $classe, $digitado, ['_' => 'Não foi possível criar: algum valor não foi aceito (o endereço pode ter acabado de ser usado). Confira e tente de novo.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Defesa em profundidade: se, apesar da trava, o pai foi parar na lixeira, o filho novo (ainda privado) vai junto para ela.
        if ($this->servicos->objetos->porId($pai->id)?->status === self::LIXEIRA) {
            $this->desfazer(fn () => $this->servicos->objetos->atualizar($novo->id, ['status' => self::LIXEIRA], self::MOTIVO . ': desfeito', null, false, $u->id));

            return $this->naLixeira($sessao, $pai);
        }

        return new RedirectResponse($this->link($novo->id) . '?salvo=criado', Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, string> $digitado
     * @param array<string, string> $erros
     */
    private function telaDeCriacao(SessaoAtiva $sessao, Objeto $pai, Classe $classe, array $digitado, array $erros = [], int $status = Response::HTTP_OK): Response
    {
        $corpo = $this->migalhas($sessao, $pai) . $this->resumoDeErros($erros)
            . '<p>Novo conteúdo do tipo <strong>' . Pagina::h($classe->nome) . '</strong> dentro de <strong>' . Pagina::h($pai->titulo) . '</strong>. Ele começa fora do ar (privado).</p>'
            . '<form method="post" action="' . $this->link($pai->id) . '/novo" novalidate>'
            . '<input type="hidden" name="_csrf" value="' . Pagina::h($this->csrf->daSessao($sessao->idHash)) . '">'
            . '<input type="hidden" name="classe" value="' . $classe->id . '">'
            . $this->camposDoFormulario($classe, $digitado, $erros, false, true)
            . '<button type="submit" class="btn btn-primary">Criar</button> <a class="btn btn-outline-secondary" href="' . $this->link($pai->id) . '">Cancelar</a></form>';

        return Pagina::resposta('Criar conteúdo', $corpo, $status, false, $this->barra($sessao));
    }

    /**
     * Erro de regra do repositório (tipo não aceito, campo obrigatório) como erro de formulário: ao lado do campo, se houver um.
     *
     * @return array<string, string>
     */
    private function erroDaRegra(ViolacaoDeRegra $e): array
    {
        return [$e->campo !== null ? 'dados.' . $e->campo : '_' => $e->getMessage()];
    }

    /** Endereço livre a partir do título (`Notícia de Hoje!` → `noticia-de-hoje`, `-2`, `-3`… se já usado), ou null. */
    private function urlLivre(int $siteId, string $titulo): ?string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', Texto::normalizar($titulo)), '-');
        $base = rtrim(substr($base !== '' ? $base : 'conteudo', 0, 80), '-');
        for ($n = 1; $n <= 50; $n++) {
            $candidata = $n === 1 ? $base : $base . '-' . $n;
            if (Caminhos::urlValida($candidata) && $this->servicos->objetos->urlDisponivel($siteId, $candidata)) {
                return $candidata;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- formulário (criar e editar)

    /**
     * Lê e valida título, descrição, endereço, peso e as propriedades da classe. null = o pedido veio malformado (campo não
     * textual ou ausente): 400. Valor vazio numa propriedade = remover o valor.
     *
     * @param array<string, mixed> $campos
     * @return array{0: array{titulo: string, descricao: string, url_amigavel?: string, peso: int, dados: array<string, string|int|float|null>}, 1: array<string, string>, 2: array<string, string>}|null
     */
    private function lerFormulario(SessaoAtiva $sessao, int $siteId, Classe $classe, array $campos, ?Objeto $atual): ?array
    {
        // Na edição, campo que nem veio no pedido mantém o valor atual (campo vazio, que o navegador sempre envia, remove o valor).
        $titulo = $campos['titulo'] ?? null;
        $descricao = $campos['descricao'] ?? null;
        $url = $campos['url'] ?? ($atual === null ? '' : $atual->urlAmigavel);
        $peso = $campos['peso'] ?? ($atual === null ? '0' : (string) $atual->peso);
        $dadosBrutos = $campos['dados'] ?? [];
        if (!is_string($titulo) || !is_string($descricao) || !is_string($url) || !is_string($peso) || !is_array($dadosBrutos)) {
            return null;
        }
        $titulo = trim($titulo);
        $descricao = trim($this->linhas($descricao));
        $url = trim($url);
        $peso = trim($peso);
        $digitado = ['titulo' => $titulo, 'descricao' => $descricao, 'url' => $url, 'peso' => $peso];
        $erros = [];
        $valores = ['titulo' => $titulo, 'descricao' => $descricao, 'peso' => 0, 'dados' => []];

        if (!mb_check_encoding($titulo, 'UTF-8') || $this->temControle($titulo, false)) {
            $erros['titulo'] = 'O título tem caracteres que não são permitidos.';
        } elseif ($titulo === '') {
            $erros['titulo'] = 'O título é obrigatório.';
        } elseif (mb_strlen($titulo) > self::MAX_TITULO) {
            $erros['titulo'] = 'O título pode ter até ' . self::MAX_TITULO . ' caracteres.';
        }
        if (!mb_check_encoding($descricao, 'UTF-8') || $this->temControle($descricao, true)) {
            $erros['descricao'] = 'A descrição tem caracteres que não são permitidos.';
        } elseif (mb_strlen($descricao) > self::MAX_DESCRICAO) {
            $erros['descricao'] = 'A descrição pode ter até ' . self::MAX_DESCRICAO . ' caracteres.';
        }
        // A página inicial do site (sem pai) mantém o endereço vazio; nos demais, endereço vazio só ao criar (gera do título).
        if ($atual === null || $atual->paiId !== null) {
            if ($url === '' && $atual !== null) {
                $erros['url'] = 'O endereço é obrigatório.';
            } elseif ($url !== '' && !Caminhos::urlValida($url)) {
                $erros['url'] = 'Use só letras minúsculas sem acento, números e hífen, com "/" separando partes (exemplo: noticias/2026/aviso).';
            } elseif ($url !== '' && !$this->servicos->objetos->urlDisponivel($siteId, $url, $atual?->id)) {
                $erros['url'] = 'Este endereço já é de outro conteúdo (ou já foi, e hoje leva até ele). Escolha outro.';
            } else {
                $valores['url_amigavel'] = $url;
            }
        }
        if (preg_match('/^-?[0-9]{1,6}$/D', $peso) === 1) {
            $valores['peso'] = (int) $peso;
        } else {
            $erros['peso'] = 'O peso precisa ser um número inteiro (exemplo: 0, 10 ou -5).';
        }

        // Campo arquivado não aparece no formulário e não é tocado: o valor guardado continua como está.
        foreach ($this->propriedadesAtivas($classe) as $p) {
            $nome = $p['nome'];
            $guardado = $atual === null || !isset($atual->dados[$nome]) ? null : (string) $atual->dados[$nome];
            $bruto = array_key_exists($nome, $dadosBrutos) ? $dadosBrutos[$nome] : ($guardado ?? '');
            if (!is_string($bruto)) {
                return null;
            }
            $texto = in_array($p['tipo'], ['texto', 'html'], true) ? trim($this->linhas($bruto)) : trim($bruto);
            $digitado['dados.' . $nome] = $texto;
            [$valor, $erro] = $this->lerPropriedade($sessao, $siteId, $p, $texto, $guardado);
            if ($erro === null && $valor === null && ($p['obrigatorio'] ?? false)) {
                $erro = 'Este campo é obrigatório.';
            }
            if ($erro !== null) {
                $erros['dados.' . $nome] = $erro;
            } else {
                $valores['dados'][$nome] = $valor;
            }
        }

        return [$valores, $digitado, $erros];
    }

    /**
     * @param array{nome: string, tipo: string, indexavel: bool, classe?: string} $p
     * @return array{0: string|int|float|null, 1: string|null} [valor, erro]
     */
    private function lerPropriedade(SessaoAtiva $sessao, int $siteId, array $p, string $texto, ?string $guardado = null): array
    {
        if ($texto === '') {
            return [null, null];
        }
        if (!mb_check_encoding($texto, 'UTF-8')) {
            return [null, 'Este campo tem caracteres que não são permitidos.'];
        }
        switch ($p['tipo']) {
            case 'numero':
                $n = str_replace(',', '.', $texto);
                if (preg_match('/^-?[0-9]{1,15}$/D', $n) === 1) {
                    return [(int) $n, null];
                }
                if (preg_match('/^-?[0-9]{1,15}\.[0-9]{1,10}$/D', $n) === 1) {
                    return [(float) $n, null];
                }

                return [null, 'Este campo precisa ser um número (exemplo: 42 ou 3,5).'];
            case 'booleano':
                return in_array($texto, ['0', '1'], true) ? [(int) $texto, null] : [null, 'Escolha "Sim" ou "Não".'];
            case 'data':
                if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}$/D', $texto) === 1) {
                    $texto .= ':00';
                }
                foreach (['!Y-m-d', '!Y-m-d H:i:s', '!Y-m-d\TH:i:sP'] as $formato) {
                    $d = \DateTimeImmutable::createFromFormat($formato, $texto);
                    $problemas = \DateTimeImmutable::getLastErrors();
                    if ($d !== false && ($problemas === false || ($problemas['warning_count'] === 0 && $problemas['error_count'] === 0))) {
                        return [$texto, null];
                    }
                }

                return [null, 'Use uma data válida no formato aaaa-mm-dd, com hora opcional (exemplo: 2026-12-31 ou 2026-12-31 18:30).'];
            case 'ref':
                $alvo = preg_match('/^[1-9][0-9]{0,9}$/D', $texto) === 1 ? $this->servicos->objetos->porId((int) $texto) : null;
                // Mesma mensagem para "não existe", "é de outro site" e "você não pode ver": não revela ids alheios. A referência
                // que já estava gravada continua valendo mesmo que a pessoa não possa ver o alvo (ela só não pode apontar para outro).
                $mantida = $guardado !== null && $alvo !== null && (string) $alvo->id === $guardado;
                if ($alvo === null || $alvo->siteId !== $siteId || (!$mantida && !$this->pode($sessao, Acao::Ver, $alvo))) {
                    return [null, 'Indique o número de um conteúdo deste site que você possa ver.'];
                }
                if (isset($p['classe']) && $this->nomeDaClasse($alvo->classeId) !== $p['classe']) {
                    return [null, 'Este campo precisa apontar para um conteúdo do tipo ' . $p['classe'] . '.'];
                }

                return [$alvo->id, null];
            default: // texto e html
                $max = $p['tipo'] === 'html' ? self::MAX_HTML : self::MAX_TEXTO;
                if ($this->temControle($texto, true)) {
                    return [null, 'Este campo tem caracteres de controle, que não são permitidos.'];
                }
                if (mb_strlen($texto) > $max) {
                    return [null, 'Este campo pode ter até ' . $max . ' caracteres.'];
                }
                if ($p['tipo'] === 'html') {
                    // O repositório limpa de novo ao gravar; limpar aqui faz o formulário mostrar o que de fato ficará salvo.
                    $limpo = HtmlSeguro::limpar($texto);

                    return $limpo === '' ? [null, 'Nada deste HTML é permitido (scripts, estilos, formulários e eventos são removidos). Escreva o texto com as marcações comuns.'] : [$limpo, null];
                }

                return [$texto, null];
        }
    }

    /** @return array<string, string> os valores atuais como texto, para preencher o formulário */
    private function digitadoDe(Objeto $o, Classe $classe): array
    {
        $d = ['titulo' => $o->titulo, 'descricao' => $o->descricao, 'url' => $o->urlAmigavel, 'peso' => (string) $o->peso];
        foreach ($classe->propriedades as $p) {
            $d['dados.' . $p['nome']] = isset($o->dados[$p['nome']]) ? (string) $o->dados[$p['nome']] : '';
        }

        return $d;
    }

    /**
     * @param array<string, string> $digitado
     * @param array<string, string> $erros
     */
    private function camposDoFormulario(Classe $classe, array $digitado, array $erros, bool $raiz, bool $criando = false): string
    {
        $html = $this->campo('titulo', 'Título', '<input id="campo-titulo" class="form-control' . $this->invalido($erros, 'titulo') . '" name="titulo" maxlength="' . self::MAX_TITULO . '" required value="' . Pagina::h($digitado['titulo'] ?? '') . '"' . $this->descrito($erros, 'titulo') . '>', $erros)
            . $this->campo('descricao', 'Descrição', '<textarea id="campo-descricao" class="form-control' . $this->invalido($erros, 'descricao') . '" name="descricao" rows="3" maxlength="' . self::MAX_DESCRICAO . '"' . $this->descrito($erros, 'descricao', true) . '>' . Pagina::h($digitado['descricao'] ?? '') . '</textarea>', $erros, 'Opcional, até ' . self::MAX_DESCRICAO . ' caracteres.');
        if (!$raiz) {
            $html .= $this->campo('url', 'Endereço (URL amigável)', '<div class="input-group"><span class="input-group-text">/</span><input id="campo-url" class="form-control' . $this->invalido($erros, 'url') . '" name="url" maxlength="' . Caminhos::URL_TAMANHO_MAXIMO . '" value="' . Pagina::h($digitado['url'] ?? '') . '"' . $this->descrito($erros, 'url', true) . '></div>', $erros,
                $criando ? 'Opcional: se ficar vazio, é criado a partir do título. Letras minúsculas sem acento, números, hífen e "/".' : 'Letras minúsculas sem acento, números, hífen e "/". Se mudar, o endereço antigo passa a levar ao novo.');
        }
        $html .= $this->campo('peso', 'Peso', '<input id="campo-peso" class="form-control' . $this->invalido($erros, 'peso') . '" name="peso" type="number" step="1" min="-999999" max="999999" value="' . Pagina::h($digitado['peso'] ?? '0') . '"' . $this->descrito($erros, 'peso', true) . '>', $erros, 'Ordem nas listas: menor aparece primeiro.');
        $ativas = $this->propriedadesAtivas($classe);
        if ($ativas !== []) {
            $html .= '<fieldset class="mb-3"><legend class="h6">Campos do tipo ' . Pagina::h($classe->nome) . '</legend>';
            foreach ($ativas as $p) {
                $html .= $this->campoDePropriedade($p, $digitado['dados.' . $p['nome']] ?? '', $erros);
            }
            $html .= '</fieldset>';
        }

        return $html;
    }

    /**
     * @param array{nome: string, tipo: string, indexavel: bool, classe?: string, rotulo?: string, obrigatorio?: bool, arquivada?: bool} $p
     * @param array<string, string> $erros
     */
    private function campoDePropriedade(array $p, string $valor, array $erros): string
    {
        $chave = 'dados.' . $p['nome'];
        // O nome de propriedade já é [a-z][a-z0-9_]* (ClasseRepositorio), mas é dado do banco: escapado mesmo assim.
        $id = 'campo-dados-' . $p['nome'];
        $classe = $this->invalido($erros, $chave);
        $comum = ' id="' . Pagina::h($id) . '" name="' . Pagina::h('dados[' . $p['nome'] . ']') . '"' . $this->descrito($erros, $chave, true)
            . (($p['obrigatorio'] ?? false) ? ' required' : '');
        [$controle, $ajuda] = match ($p['tipo']) {
            'numero' => ['<input class="form-control' . $classe . '"' . $comum . ' inputmode="decimal" value="' . Pagina::h($valor) . '">', 'Número (use vírgula ou ponto para decimais).'],
            'data' => ['<input class="form-control' . $classe . '"' . $comum . ' maxlength="25" value="' . Pagina::h($valor) . '">', 'Data no formato aaaa-mm-dd, com hora opcional (2026-12-31 18:30).'],
            'booleano' => ['<select class="form-select' . $classe . '"' . $comum . '>' . $this->opcao('', '(sem valor)', $valor === '') . $this->opcao('1', 'Sim', $valor === '1') . $this->opcao('0', 'Não', $valor === '0') . '</select>', 'Sim ou não.'],
            'ref' => ['<input class="form-control' . $classe . '"' . $comum . ' type="number" min="1" step="1" value="' . Pagina::h($valor) . '">', 'Número de outro conteúdo deste site' . (isset($p['classe']) ? ' (do tipo ' . Pagina::h($p['classe']) . ')' : '') . '. O número aparece na página de cada conteúdo.'],
            'html' => ['<textarea class="form-control font-monospace' . $classe . '"' . $comum . ' rows="10" maxlength="' . self::MAX_HTML . '">' . Pagina::h($valor) . '</textarea>', 'Texto com marcação HTML, até ' . self::MAX_HTML . ' caracteres. Ao salvar, o HTML é limpo: ficam só marcações comuns (parágrafos, títulos, listas, tabelas, links, imagens); scripts, estilos, formulários e eventos são removidos.'],
            default => ['<textarea class="form-control' . $classe . '"' . $comum . ' rows="3" maxlength="' . self::MAX_TEXTO . '">' . Pagina::h($valor) . '</textarea>', 'Texto, até ' . self::MAX_TEXTO . ' caracteres.'],
        };

        return $this->campo($chave, Classe::rotuloDe($p) . (($p['obrigatorio'] ?? false) ? ' (obrigatório)' : ''), $controle, $erros, $ajuda, $id);
    }

    /**
     * Um campo com label, ajuda e mensagem de erro ligadas por `aria-describedby`. `$ajuda` já vem escapada.
     *
     * @param array<string, string> $erros
     */
    private function campo(string $chave, string $rotulo, string $controle, array $erros, string $ajuda = '', ?string $id = null): string
    {
        $id ??= 'campo-' . $this->idDe($chave);
        $base = Pagina::h($this->idDe($chave));

        return '<div class="mb-3"><label for="' . Pagina::h($id) . '" class="form-label">' . Pagina::h($rotulo) . '</label>' . $controle
            . ($ajuda === '' ? '' : '<div id="ajuda-' . $base . '" class="form-text">' . $ajuda . '</div>')
            . (isset($erros[$chave]) ? '<div id="erro-' . $base . '" class="invalid-feedback d-block">' . Pagina::h($erros[$chave]) . '</div>' : '') . '</div>';
    }

    /** @param array<string, string> $erros */
    private function invalido(array $erros, string $chave): string
    {
        return isset($erros[$chave]) ? ' is-invalid' : '';
    }

    /** @param array<string, string> $erros */
    private function descrito(array $erros, string $chave, bool $temAjuda = false): string
    {
        $ids = [];
        if ($temAjuda) {
            $ids[] = 'ajuda-' . Pagina::h($this->idDe($chave));
        }
        if (isset($erros[$chave])) {
            $ids[] = 'erro-' . Pagina::h($this->idDe($chave));
        }

        return ($ids === [] ? '' : ' aria-describedby="' . implode(' ', $ids) . '"') . (isset($erros[$chave]) ? ' aria-invalid="true"' : '');
    }

    private function idDe(string $chave): string
    {
        return str_replace('.', '-', $chave);
    }

    /** @param array<string, string> $erros */
    private function resumoDeErros(array $erros): string
    {
        if ($erros === []) {
            return '';
        }
        if (isset($erros['_'])) {
            return '<div class="alert alert-danger" role="alert">' . Pagina::h($erros['_']) . '</div>';
        }

        return '<div class="alert alert-danger" role="alert">Confira ' . (count($erros) === 1 ? 'o campo marcado' : 'os ' . count($erros) . ' campos marcados') . ' abaixo.</div>';
    }

    // ---------------------------------------------------------------- estado e datas

    private function formularioEstado(SessaoAtiva $sessao, int $id): Response
    {
        $o = $this->servicos->objetos->porId($id);
        if ($o === null || !$this->pode($sessao, Acao::Publicar, $o)) {
            return $this->semAcesso($sessao);
        }
        if ($o->status === self::LIXEIRA) {
            return $this->naLixeira($sessao, $o);
        }

        return $this->telaDeEstado($sessao, $o, $o->status, $this->dataParaCampo($o->dataPublicacao), $this->dataParaCampo($o->dataValidade), $o->versao);
    }

    private function salvarEstado(SessaoAtiva $sessao, int $id, Request $r): Response
    {
        $campos = $r->request->all();
        if (($recusa = $this->recusarPedido($sessao, $r)) !== null) {
            return $recusa;
        }
        $o = $this->autorizado($sessao, $id, Acao::Publicar, 'objeto.estado.negado');
        if ($o === null) {
            return $this->semAcesso($sessao);
        }
        if ($o->status === self::LIXEIRA) {
            return $this->naLixeira($sessao, $o);
        }
        $versao = $this->numero($campos['versao'] ?? null);
        $estado = $campos['estado'] ?? null;
        $inicio = $campos['data_publicacao'] ?? '';
        $fim = $campos['data_validade'] ?? '';
        if ($versao === null || $versao < 1 || !is_string($estado) || !is_string($inicio) || !is_string($fim)) {
            return $this->pedidoInvalido($sessao);
        }
        $inicio = trim($inicio);
        $fim = trim($fim);
        $erros = [];
        if (!isset(self::ESTADOS[$estado])) {
            $erros['estado'] = 'Escolha um dos estados da lista.';
        }
        $dataInicio = $this->dataDoCampo($inicio);
        $dataFim = $this->dataDoCampo($fim);
        if ($dataInicio === false) {
            $erros['data_publicacao'] = 'Data inválida. Use dia e hora (exemplo: 2026-12-31 18:30).';
        }
        if ($dataFim === false) {
            $erros['data_validade'] = 'Data inválida. Use dia e hora (exemplo: 2026-12-31 18:30).';
        }
        if (is_string($dataInicio) && is_string($dataFim) && $dataFim <= $dataInicio) {
            $erros['data_validade'] = 'A data de saída do ar precisa ser depois da data de publicação.';
        }
        if ($erros !== [] || $dataInicio === false || $dataFim === false) {
            return $this->telaDeEstado($sessao, $o, $estado, $inicio, $fim, $versao, $erros, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $mudancas = [];
        if ($estado !== $o->status) {
            $mudancas['status'] = $estado;
        }
        if ($dataInicio !== $this->normalizarGravada($o->dataPublicacao)) {
            $mudancas['data_publicacao'] = $dataInicio;
        }
        if ($dataFim !== $this->normalizarGravada($o->dataValidade)) {
            $mudancas['data_validade'] = $dataFim;
        }
        if ($mudancas === []) {
            return new RedirectResponse($this->link($o->id) . '?salvo=nada', Response::HTTP_SEE_OTHER);
        }
        try {
            $novo = $this->servicos->objetos->atualizar($o->id, $mudancas, self::MOTIVO, $versao, false, $sessao->usuario->id);
        } catch (ConflitoDeVersao) {
            $atual = $this->servicos->objetos->porId($o->id) ?? $o;
            $erro = 'Outra pessoa alterou este conteúdo depois que você abriu esta tela. Agora ele está "' . $atual->status . '". '
                . 'As suas escolhas continuam no formulário; salve de novo só se quiser substituí-lo.';

            return $this->telaDeEstado($sessao, $atual, $estado, $inicio, $fim, $atual->versao, ['_' => $erro], Response::HTTP_CONFLICT);
        }
        $ok = $this->sincronizar($novo, $this->pai($novo));
        $agendado = $novo->status === 'publicado' && $novo->dataPublicacao !== null && $novo->dataPublicacao > gmdate('c');

        return new RedirectResponse($this->link($novo->id) . '?salvo=' . (!$ok ? 'aviso' : ($agendado ? 'agendado' : 'estado')), Response::HTTP_SEE_OTHER);
    }

    /** @param array<string, string> $erros */
    private function telaDeEstado(SessaoAtiva $sessao, Objeto $o, string $estado, string $inicio, string $fim, int $versao, array $erros = [], int $status = Response::HTTP_OK): Response
    {
        $opcoes = '';
        foreach (self::ESTADOS as $chave => $rotulo) {
            $opcoes .= $this->opcao($chave, $rotulo, $chave === $estado);
        }
        $ajudaData = 'Opcional. Horário de Brasília.';
        $corpo = $this->migalhas($sessao, $o) . $this->resumoDeErros($erros)
            . '<p>Hoje: <strong>' . Pagina::h($this->estadoLegivel($o, gmdate('c'))) . '</strong>. Só o estado "Publicado", dentro das datas, aparece no site. '
            . 'Datas futuras dependem do agendamento do servidor (<code>bin/pbl agendados</code>).</p>'
            . '<form method="post" action="' . $this->link($o->id) . '/estado" novalidate>' . $this->camposOcultos($sessao, $versao)
            . $this->campo('estado', 'Estado', '<select id="campo-estado" name="estado" class="form-select' . $this->invalido($erros, 'estado') . '"' . $this->descrito($erros, 'estado') . '>' . $opcoes . '</select>', $erros)
            . $this->campo('data_publicacao', 'Publicar a partir de', '<input id="campo-data_publicacao" type="datetime-local" class="form-control' . $this->invalido($erros, 'data_publicacao') . '" name="data_publicacao" value="' . Pagina::h($inicio) . '"' . $this->descrito($erros, 'data_publicacao', true) . '>', $erros, $ajudaData . ' Vazio = assim que publicado.')
            . $this->campo('data_validade', 'Tirar do ar a partir de', '<input id="campo-data_validade" type="datetime-local" class="form-control' . $this->invalido($erros, 'data_validade') . '" name="data_validade" value="' . Pagina::h($fim) . '"' . $this->descrito($erros, 'data_validade', true) . '>', $erros, $ajudaData . ' Vazio = sem prazo.')
            . '<button type="submit" class="btn btn-primary">Salvar</button> <a class="btn btn-outline-secondary" href="' . $this->link($o->id) . '">Cancelar</a></form>';

        return Pagina::resposta('Publicar ou tirar do ar: ' . $o->titulo, $corpo, $status, false, $this->barra($sessao));
    }

    /** Texto do campo (`2026-12-31T18:30`, no fuso da administração) → ISO 8601 em UTC; '' → null; inválido → false. */
    private function dataDoCampo(string $texto): string|false|null
    {
        if ($texto === '') {
            return null;
        }
        $texto = str_replace(' ', 'T', $texto);
        foreach (['!Y-m-d\TH:i', '!Y-m-d\TH:i:s'] as $formato) {
            $d = \DateTimeImmutable::createFromFormat($formato, $texto, new \DateTimeZone(self::FUSO));
            $problemas = \DateTimeImmutable::getLastErrors();
            if ($d !== false && ($problemas === false || ($problemas['warning_count'] === 0 && $problemas['error_count'] === 0))) {
                return $d->setTimezone(new \DateTimeZone('UTC'))->format('c');
            }
        }

        return false;
    }

    /** Data gravada (qualquer formato aceito) → valor do campo `datetime-local` no fuso da administração. */
    private function dataParaCampo(?string $gravada): string
    {
        $d = $this->dataGravada($gravada);

        return $d === null ? '' : $d->setTimezone(new \DateTimeZone(self::FUSO))->format('Y-m-d\TH:i');
    }

    private function dataLegivel(string $gravada): string
    {
        $d = $this->dataGravada($gravada);

        return $d === null ? $gravada : $d->setTimezone(new \DateTimeZone(self::FUSO))->format('d/m/Y H:i') . ' (Brasília)';
    }

    /** A data gravada, reescrita como o formulário a gravaria (para comparar sem criar versão à toa). */
    private function normalizarGravada(?string $gravada): ?string
    {
        $d = $this->dataGravada($gravada);

        if ($d === null) {
            return $gravada;
        }
        $normalizada = $this->dataDoCampo($d->setTimezone(new \DateTimeZone(self::FUSO))->format('Y-m-d\TH:i'));

        return is_string($normalizada) ? $normalizada : $gravada;
    }

    private function dataGravada(?string $gravada): ?\DateTimeImmutable
    {
        if ($gravada === null || $gravada === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($gravada, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    // ---------------------------------------------------------------- mover

    private function formularioMover(SessaoAtiva $sessao, int $id, Request $r): Response
    {
        $o = $this->servicos->objetos->porId($id);
        if ($o === null || !$this->pode($sessao, Acao::Apagar, $o)) {
            return $this->semAcesso($sessao);
        }
        if ($o->status === self::LIXEIRA) {
            return $this->naLixeira($sessao, $o);
        }
        if ($o->paiId === null) {
            return $this->ehRaiz($sessao, $o);
        }

        $q = $r->query->all();

        return $this->telaDeMover($sessao, $o, $this->numero($q['em'] ?? null), $o->versao, null, Response::HTTP_OK, $this->numero($q['inicio'] ?? null) ?? 0);
    }

    private function mover(SessaoAtiva $sessao, int $id, Request $r): Response
    {
        $campos = $r->request->all();
        if (($recusa = $this->recusarPedido($sessao, $r)) !== null) {
            return $recusa;
        }
        $o = $this->autorizado($sessao, $id, Acao::Apagar, 'objeto.mover.negado');
        if ($o === null) {
            return $this->semAcesso($sessao);
        }
        if ($o->status === self::LIXEIRA) {
            return $this->naLixeira($sessao, $o);
        }
        if ($o->paiId === null) {
            return $this->ehRaiz($sessao, $o);
        }
        $versao = $this->numero($campos['versao'] ?? null);
        $destinoTexto = $campos['destino'] ?? null;
        if ($versao === null || $versao < 1 || !is_string($destinoTexto)) {
            return $this->pedidoInvalido($sessao);
        }
        $destinoTexto = trim($destinoTexto);
        $destino = preg_match('/^[1-9][0-9]{0,9}$/D', $destinoTexto) === 1 ? $this->servicos->objetos->porId((int) $destinoTexto) : null;
        // Mesma mensagem para "não existe", "é de outro site" e "sem permissão de criar lá".
        if ($destino === null || $destino->siteId !== $o->siteId || !$this->servicos->permissoes->podeCriarSob($sessao->usuario, $destino)) {
            return $this->telaDeMover($sessao, $o, null, $versao, 'Escolha na lista um conteúdo deste site onde você possa criar conteúdo.');
        }
        if ($destino->status === self::LIXEIRA) {
            return $this->telaDeMover($sessao, $o, $destino->id, $versao, 'O destino está na lixeira.');
        }
        if (str_starts_with($destino->caminho, $o->caminho)) {
            return $this->telaDeMover($sessao, $o, $destino->id, $versao, 'Não é possível mover um conteúdo para dentro dele mesmo.');
        }
        if ($destino->id === $o->paiId) {
            return new RedirectResponse($this->link($o->id) . '?salvo=nada', Response::HTTP_SEE_OTHER);
        }
        $classe = $this->classeDe($o);
        if ($classe === null || !$this->aceita($destino, $classe)) {
            return $this->telaDeMover($sessao, $o, $destino->id, $versao, 'O tipo do destino não aceita conteúdo do tipo ' . $this->nomeDaClasse($o->classeId) . ' dentro dele.');
        }
        $paiAntigo = $this->pai($o);
        try {
            $novo = $this->servicos->objetos->mover($o->id, $destino->id, self::MOTIVO, $sessao->usuario->id, $versao, true);
        } catch (ConflitoDeArvore) {
            return $this->telaDeMover($sessao, $o, $destino->id, $versao, 'O destino está na lixeira.', Response::HTTP_CONFLICT);
        } catch (ViolacaoDeRegra $e) {
            return $this->telaDeMover($sessao, $o, $destino->id, $versao, $e->getMessage());
        } catch (ConflitoDeVersao) {
            $atual = $this->servicos->objetos->porId($o->id) ?? $o;

            return $this->telaDeMover($sessao, $atual, $destino->id, $atual->versao, 'Outra pessoa alterou este conteúdo depois que você abriu esta tela. Confira e confirme de novo.', Response::HTTP_CONFLICT);
        }
        // Defesa em profundidade: destino foi para a lixeira apesar da trava? Volta para o lugar de antes.
        if ($this->servicos->objetos->porId($destino->id)?->status === self::LIXEIRA && $paiAntigo !== null) {
            $this->desfazer(fn () => $this->servicos->objetos->mover($novo->id, $paiAntigo->id, self::MOTIVO . ': desfeito', $sessao->usuario->id));
            $atual = $this->servicos->objetos->porId($o->id) ?? $o;

            return $this->telaDeMover($sessao, $atual, $destino->id, $atual->versao, 'O destino foi para a lixeira enquanto você movia. Nada mudou.', Response::HTTP_CONFLICT);
        }
        $ok = $this->sincronizar($novo, $paiAntigo, $destino);

        return new RedirectResponse($this->link($novo->id) . '?salvo=' . ($ok ? 'movido' : 'aviso'), Response::HTTP_SEE_OTHER);
    }

    /**
     * Escolha do novo lugar navegando pela árvore, só com links e botões (sem JavaScript e sem digitar número): a tela mostra o
     * conteúdo onde se está (`$em`), os filhos dele para entrar e, em cada um, o botão de mover para dentro. Começa no pai atual.
     * Só mostra conteúdo que a pessoa pode ver, do mesmo site e fora da subárvore de quem está sendo movido; o POST confere tudo de novo.
     */
    private function telaDeMover(SessaoAtiva $sessao, Objeto $o, ?int $em, int $versao, ?string $erro = null, int $status = Response::HTTP_OK, int $inicio = 0): Response
    {
        if ($erro !== null && $status === Response::HTTP_OK) {
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }
        $aqui = $this->lugarNavegavel($sessao, $o, $em) ?? $this->lugarNavegavel($sessao, $o, $o->paiId) ?? $this->primeiroLugarVisivel($sessao, $o);
        $pai = $this->pai($o);
        $corpo = $this->migalhas($sessao, $o) . ($erro === null ? '' : '<div class="alert alert-danger" role="alert">' . Pagina::h($erro) . '</div>')
            . '<p>Hoje está dentro de <strong>' . ($pai === null ? '?' : Pagina::h($pai->titulo)) . '</strong>. '
            . 'Tudo o que está dentro dele vai junto. O endereço não muda.</p>';
        if ($aqui === null) {
            return Pagina::resposta('Mover: ' . $o->titulo, $corpo . '<div class="alert alert-warning" role="alert">Não há onde navegar a partir da sua permissão.</div>', $status, false, $this->barra($sessao));
        }

        $consulta = new Consulta($o->siteId, $aqui->id, 1, null, [new Filtro('status', '!=', self::LIXEIRA)], 'peso, titulo', $inicio, self::POR_PAGINA, false);
        $filhos = array_values(array_filter($this->servicos->motor->buscar($consulta), static fn (Objeto $f): bool => !str_starts_with($f->caminho, $o->caminho)));
        $total = $this->servicos->motor->contar($consulta);

        $corpo .= $this->trilhaDeDestino($sessao, $o, $aqui)
            . '<h2 class="h5 mt-3">Onde você está: ' . Pagina::h($aqui->titulo) . '</h2>' . $this->botaoMoverPara($sessao, $o, $aqui, $versao, 'Mover para dentro de "' . $aqui->titulo . '"', true);
        if ($filhos === []) {
            $corpo .= '<p class="text-body-secondary mt-3">Não há outro conteúdo dentro deste lugar.</p>';
        } else {
            $corpo .= '<div class="table-responsive mt-3"><table class="table table-sm align-middle"><caption class="visually-hidden">Conteúdo dentro de ' . Pagina::h($aqui->titulo) . '</caption>'
                . '<thead><tr><th scope="col">Título</th><th scope="col">Tipo</th><th scope="col">Mover para dentro</th></tr></thead><tbody>';
            foreach ($filhos as $f) {
                $corpo .= '<tr><td><a href="' . $this->linkDeDestino($o, $f->id) . '">' . Pagina::h($f->titulo) . '</a></td>'
                    . '<td>' . Pagina::h($this->nomeDaClasse($f->classeId)) . '</td><td>' . $this->botaoMoverPara($sessao, $o, $f, $versao, 'Mover para cá', false) . '</td></tr>';
            }
            $corpo .= '</tbody></table></div>';
        }
        if ($total > self::POR_PAGINA) {
            $base = $this->linkDeDestino($o, $aqui->id);
            $corpo .= '<p class="text-body-secondary">Mostrando ' . ($inicio + 1) . ' a ' . min($total, $inicio + self::POR_PAGINA) . ' de ' . $total . '.';
            if ($inicio > 0) {
                $corpo .= ' <a href="' . Pagina::h($base . '&inicio=' . max(0, $inicio - self::POR_PAGINA)) . '">Anteriores</a>';
            }
            if ($inicio + self::POR_PAGINA < $total) {
                $corpo .= ' <a href="' . Pagina::h($base . '&inicio=' . ($inicio + self::POR_PAGINA)) . '">Próximos</a>';
            }
            $corpo .= '</p>';
        }
        $corpo .= '<p class="mt-3"><a class="btn btn-outline-secondary" href="' . $this->link($o->id) . '">Cancelar</a></p>';

        return Pagina::resposta('Mover: ' . $o->titulo, $corpo, $status, false, $this->barra($sessao));
    }

    /** Endereço da tela de mover olhando para `$em` (já escapado para atributo). */
    private function linkDeDestino(Objeto $o, int $em): string
    {
        return Pagina::h($this->link($o->id) . '/mover?em=' . $em);
    }

    /**
     * Um lugar para navegar: existe, é do mesmo site, não está na lixeira, não é o próprio objeto nem está dentro dele, e a pessoa
     * pode vê-lo. Qualquer outro caso é null (a tela cai no pai atual).
     */
    private function lugarNavegavel(SessaoAtiva $sessao, Objeto $o, ?int $id): ?Objeto
    {
        $lugar = $id === null ? null : $this->servicos->objetos->porId($id);
        if ($lugar === null || $lugar->siteId !== $o->siteId || $lugar->status === self::LIXEIRA || str_starts_with($lugar->caminho, $o->caminho) || !$this->pode($sessao, Acao::Ver, $lugar)) {
            return null;
        }

        return $lugar;
    }

    /** O ancestral mais alto que a pessoa pode ver (quem vê um ancestral vê tudo abaixo dele). */
    private function primeiroLugarVisivel(SessaoAtiva $sessao, Objeto $o): ?Objeto
    {
        foreach (array_filter(explode('/', trim($o->caminho, '/')), static fn (string $p): bool => $p !== '') as $parte) {
            $lugar = $this->lugarNavegavel($sessao, $o, (int) $parte);
            if ($lugar !== null) {
                return $lugar;
            }
        }

        return null;
    }

    /** Ancestrais do lugar atual da navegação que a pessoa pode ver, como links da própria tela de mover. */
    private function trilhaDeDestino(SessaoAtiva $sessao, Objeto $o, Objeto $aqui): string
    {
        $partes = [];
        foreach (array_filter(explode('/', trim($aqui->caminho, '/')), static fn (string $p): bool => $p !== '') as $parte) {
            $a = (int) $parte === $aqui->id ? null : $this->lugarNavegavel($sessao, $o, (int) $parte);
            if ($a !== null) {
                $partes[] = '<li class="breadcrumb-item"><a href="' . $this->linkDeDestino($o, $a->id) . '">' . Pagina::h($a->titulo) . '</a></li>';
            }
        }
        $partes[] = '<li class="breadcrumb-item active" aria-current="page">' . Pagina::h($aqui->titulo) . '</li>';

        return '<nav aria-label="Navegação até o novo lugar"><ol class="breadcrumb">' . implode('', $partes) . '</ol></nav>';
    }

    /** Botão de mover para dentro de `$destino`, ou o motivo de não poder (permissão, tipo não aceito, já é o pai atual). */
    private function botaoMoverPara(SessaoAtiva $sessao, Objeto $o, Objeto $destino, int $versao, string $rotulo, bool $destaque): string
    {
        $classe = $this->classeDe($o);
        $motivo = match (true) {
            $classe === null => 'O tipo deste conteúdo não foi encontrado.',
            $destino->id === $o->paiId => 'É onde já está.',
            !$this->servicos->permissoes->podeCriarSob($sessao->usuario, $destino) => 'Você não pode criar conteúdo aqui.',
            !$this->aceita($destino, $classe) => 'O tipo deste lugar não aceita conteúdo do tipo ' . $this->nomeDaClasse($o->classeId) . '.',
            default => null,
        };
        if ($motivo !== null) {
            return '<span class="text-body-secondary small">' . Pagina::h($motivo) . '</span>';
        }

        return '<form method="post" action="' . $this->link($o->id) . '/mover" class="d-inline">' . $this->camposOcultos($sessao, $versao)
            . '<input type="hidden" name="destino" value="' . $destino->id . '">'
            . '<button type="submit" class="btn btn-sm ' . ($destaque ? 'btn-primary' : 'btn-outline-primary') . '">' . Pagina::h($rotulo) . '</button></form>';
    }

    // ---------------------------------------------------------------- lixeira

    private function confirmarApagar(SessaoAtiva $sessao, int $id): Response
    {
        $o = $this->servicos->objetos->porId($id);
        if ($o === null || !$this->pode($sessao, Acao::Apagar, $o)) {
            return $this->semAcesso($sessao);
        }
        if ($o->status === self::LIXEIRA) {
            return $this->naLixeira($sessao, $o);
        }
        if ($o->paiId === null) {
            return $this->ehRaiz($sessao, $o);
        }

        return $this->telaDeApagar($sessao, $o);
    }

    private function telaDeApagar(SessaoAtiva $sessao, Objeto $o, ?string $erro = null, int $status = Response::HTTP_OK): Response
    {
        $filhos = $this->filhosForaDaLixeira($o);
        $corpo = $this->migalhas($sessao, $o) . ($erro === null ? '' : '<div class="alert alert-danger" role="alert">' . Pagina::h($erro) . '</div>');
        if ($filhos > 0) {
            $corpo .= '<div class="alert alert-warning" role="alert">Este conteúdo tem ' . $filhos . ' ' . ($filhos === 1 ? 'conteúdo' : 'conteúdos') . ' dentro dele. '
                . 'Mova ou mande para a lixeira o que está dentro antes.</div><p><a class="btn btn-outline-secondary" href="' . $this->link($o->id) . '">Voltar</a></p>';
        } else {
            $corpo .= '<p>Mandar <strong>' . Pagina::h($o->titulo) . '</strong> para a lixeira? Ele sai do ar na hora e deixa de aparecer nas listas. '
                . 'Nada é apagado de vez: dá para tirá-lo da lixeira depois, com o histórico inteiro.</p>'
                . '<form method="post" action="' . $this->link($o->id) . '/apagar">' . $this->camposOcultos($sessao, $o->versao)
                . '<button type="submit" class="btn btn-danger">Mandar para a lixeira</button> <a class="btn btn-outline-secondary" href="' . $this->link($o->id) . '">Cancelar</a></form>';
        }

        return Pagina::resposta('Mandar para a lixeira: ' . $o->titulo, $corpo, $status, false, $this->barra($sessao));
    }

    private function apagar(SessaoAtiva $sessao, int $id, Request $r): Response
    {
        $campos = $r->request->all();
        if (($recusa = $this->recusarPedido($sessao, $r)) !== null) {
            return $recusa;
        }
        $o = $this->autorizado($sessao, $id, Acao::Apagar, 'objeto.apagar.negado');
        if ($o === null) {
            return $this->semAcesso($sessao);
        }
        if ($o->status === self::LIXEIRA) {
            return $this->naLixeira($sessao, $o);
        }
        if ($o->paiId === null) {
            return $this->ehRaiz($sessao, $o);
        }
        $versao = $this->numero($campos['versao'] ?? null);
        if ($versao === null || $versao < 1) {
            return $this->pedidoInvalido($sessao);
        }
        if ($this->filhosForaDaLixeira($o) > 0) {
            return $this->telaDeApagar($sessao, $o, null, Response::HTTP_CONFLICT);
        }
        try {
            $novo = $this->servicos->objetos->mandarParaLixeira($o->id, $versao, self::MOTIVO, $sessao->usuario->id);
        } catch (ConflitoDeArvore) {
            return $this->telaDeApagar($sessao, $o, null, Response::HTTP_CONFLICT);
        } catch (ConflitoDeVersao) {
            $atual = $this->servicos->objetos->porId($o->id) ?? $o;

            return $this->telaDeApagar($sessao, $atual, 'Outra pessoa alterou este conteúdo depois que você abriu esta tela. Confira e confirme de novo.', Response::HTTP_CONFLICT);
        }
        // Defesa em profundidade: apareceu filho fora da lixeira apesar da trava? Volta ao estado anterior.
        if ($this->servicos->objetos->filhosForaDaLixeira($novo->id) > 0) {
            $this->desfazer(fn () => $this->servicos->objetos->atualizar($novo->id, ['status' => $o->status], self::MOTIVO . ': desfeito', null, false, $sessao->usuario->id));

            return $this->telaDeApagar($sessao, $this->servicos->objetos->porId($o->id) ?? $o, 'Alguém pôs conteúdo dentro dele enquanto você confirmava. Nada foi para a lixeira.', Response::HTTP_CONFLICT);
        }
        $pai = $this->pai($novo);
        $ok = $this->sincronizar($novo, $pai);
        // Volta para a página-mãe se a pessoa pode vê-la (quem cuida só desta seção fica na própria página, agora na lixeira).
        $destino = $pai !== null && $this->pode($sessao, Acao::Ver, $pai) ? $pai : $novo;

        return new RedirectResponse($this->link($destino->id) . '?salvo=' . ($ok ? 'apagado' : 'aviso'), Response::HTTP_SEE_OTHER);
    }

    private function recuperar(SessaoAtiva $sessao, int $id, Request $r): Response
    {
        $campos = $r->request->all();
        if (($recusa = $this->recusarPedido($sessao, $r)) !== null) {
            return $recusa;
        }
        $o = $this->autorizado($sessao, $id, Acao::Apagar, 'objeto.recuperar.negado');
        if ($o === null) {
            return $this->semAcesso($sessao);
        }
        $versao = $this->numero($campos['versao'] ?? null);
        if ($versao === null || $versao < 1) {
            return $this->pedidoInvalido($sessao);
        }
        if ($o->status !== self::LIXEIRA) {
            return new RedirectResponse($this->link($o->id) . '?salvo=nada', Response::HTTP_SEE_OTHER);
        }
        $paiNaLixeira = fn (): Response => $this->recusa($sessao, 'Ainda na lixeira', 'O conteúdo onde este está também está na lixeira. Tire aquele primeiro.', $o);
        $pai = $this->pai($o);
        if ($pai !== null && $pai->status === self::LIXEIRA) {
            return $paiNaLixeira();
        }
        try {
            $novo = $this->servicos->objetos->tirarDaLixeira($o->id, $versao, self::MOTIVO, $sessao->usuario->id);
        } catch (ConflitoDeArvore) {
            return $paiNaLixeira();
        } catch (ConflitoDeVersao) {
            return $this->recusa($sessao, 'Alterado por outra pessoa', 'Outra pessoa alterou este conteúdo agora há pouco. Abra a página de novo e confira.', $o);
        }
        // Defesa em profundidade: o pai foi para a lixeira apesar da trava? Este volta para ela.
        if ($o->paiId !== null && $this->servicos->objetos->porId($o->paiId)?->status === self::LIXEIRA) {
            $this->desfazer(fn () => $this->servicos->objetos->atualizar($novo->id, ['status' => self::LIXEIRA], self::MOTIVO . ': desfeito', null, false, $sessao->usuario->id));

            return $paiNaLixeira();
        }

        return new RedirectResponse($this->link($novo->id) . '?salvo=recuperado', Response::HTTP_SEE_OTHER);
    }

    private function filhosForaDaLixeira(Objeto $o): int
    {
        return $this->servicos->objetos->filhosForaDaLixeira($o->id);
    }

    /**
     * Desfaz uma gravação que, conferida depois, violou a regra da lixeira (corrida que escapou da trava). A gravação de
     * reversão também fica no histórico e na auditoria; se até ela falhar, a próxima ação de alguém mostra o estado real.
     *
     * @param \Closure(): mixed $reverter
     */
    private function desfazer(\Closure $reverter): void
    {
        try {
            $reverter();
        } catch (\Throwable) {
            // nada mais a fazer aqui: a resposta já é 409 e o estado gravado continua visível na tela
        }
    }

    // ---------------------------------------------------------------- histórico

    private function versoes(SessaoAtiva $sessao, int $id): Response
    {
        $o = $this->servicos->objetos->porId($id);
        if ($o === null || !$this->pode($sessao, Acao::Ver, $o)) {
            return $this->semAcesso($sessao);
        }
        $todas = $this->servicos->objetos->versoes($o->id);
        $podeRestaurar = $o->status !== self::LIXEIRA && $this->pode($sessao, Acao::Editar, $o);
        $nomes = [];
        $corpo = $this->migalhas($sessao, $o)
            . '<p>Cada vez que o conteúdo é salvo, a versão anterior fica guardada. Restaurar traz de volta o título, a descrição, o endereço, '
            . 'o peso e os campos daquela versão, gravados como uma versão nova; o estado e as datas de publicação continuam como estão.</p>'
            . '<div class="table-responsive"><table class="table table-sm align-middle"><caption class="visually-hidden">Versões guardadas</caption>'
            . '<thead><tr><th scope="col">Versão</th><th scope="col">Quando</th><th scope="col">Quem</th><th scope="col">Título</th><th scope="col">Estado</th><th scope="col">Motivo</th><th scope="col"><span class="visually-hidden">Ação</span></th></tr></thead><tbody>';
        foreach (array_slice($todas, 0, self::MAX_VERSOES) as $v) {
            $quem = 'sistema';
            if ($v['usuario_id'] !== null) {
                $quem = $nomes[$v['usuario_id']] ??= $this->servicos->usuarios->porId($v['usuario_id'])->nome ?? ('pessoa nº ' . $v['usuario_id']);
            }
            $acao = $v['versao'] === $o->versao ? '<span class="badge text-bg-secondary">atual</span>' : '';
            if ($podeRestaurar && $v['versao'] !== $o->versao) {
                $acao = '<form method="post" action="' . $this->link($o->id) . '/restaurar">' . $this->camposOcultos($sessao, $o->versao)
                    . '<input type="hidden" name="alvo" value="' . $v['versao'] . '">'
                    . '<button type="submit" class="btn btn-sm btn-outline-primary">Restaurar a versão ' . $v['versao'] . '</button></form>';
            }
            $corpo .= '<tr><td>' . $v['versao'] . '</td><td>' . Pagina::h($this->dataLegivel($v['criado_em'])) . '</td><td>' . Pagina::h($quem) . '</td>'
                . '<td>' . Pagina::h($v['titulo']) . '</td><td>' . Pagina::h($v['status']) . '</td><td>' . Pagina::h($v['motivo']) . '</td><td>' . $acao . '</td></tr>';
        }
        $corpo .= '</tbody></table></div>';
        if (count($todas) > self::MAX_VERSOES) {
            $corpo .= '<p class="text-body-secondary">Mostrando as ' . self::MAX_VERSOES . ' versões mais novas de ' . count($todas) . '.</p>';
        }
        $corpo .= '<p><a class="btn btn-outline-secondary" href="' . $this->link($o->id) . '">Voltar</a></p>';

        return Pagina::resposta('Histórico: ' . $o->titulo, $corpo, Response::HTTP_OK, false, $this->barra($sessao));
    }

    private function restaurar(SessaoAtiva $sessao, int $id, Request $r): Response
    {
        $campos = $r->request->all();
        if (($recusa = $this->recusarPedido($sessao, $r)) !== null) {
            return $recusa;
        }
        $o = $this->autorizado($sessao, $id, Acao::Editar, 'objeto.restaurar.negado');
        if ($o === null) {
            return $this->semAcesso($sessao);
        }
        if ($o->status === self::LIXEIRA) {
            return $this->naLixeira($sessao, $o);
        }
        $versao = $this->numero($campos['versao'] ?? null);
        $alvo = $this->numero($campos['alvo'] ?? null);
        if ($versao === null || $versao < 1 || $alvo === null || $alvo < 1) {
            return $this->pedidoInvalido($sessao);
        }
        if ($alvo === $o->versao || !in_array($alvo, array_column($this->servicos->objetos->versoes($o->id), 'versao'), true)) {
            return $this->recusa($sessao, 'Versão indisponível', 'Essa versão não existe ou já é a atual.', $o, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $novo = $this->servicos->objetos->restaurar($o->id, $alvo, 'restaurada a versão ' . $alvo, $sessao->usuario->id, $versao, true);
        } catch (ConflitoDeVersao) {
            return $this->recusa($sessao, 'Alterado por outra pessoa', 'Outra pessoa alterou este conteúdo depois que você abriu o histórico. Abra o histórico de novo e confira antes de restaurar.', $o);
        } catch (\InvalidArgumentException) {
            return $this->recusa($sessao, 'Não foi possível restaurar', 'Essa versão não pode voltar como está: o endereço dela já é de outro conteúdo ou os campos do tipo mudaram desde então.', $o, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $ok = true;
        if ($novo->urlAmigavel !== $o->urlAmigavel) {
            $ok = $this->tirarDoAr($o);
        }
        $ok = $this->sincronizar($novo, $this->pai($novo)) && $ok;

        return new RedirectResponse($this->link($novo->id) . '?salvo=' . ($ok ? 'restaurado' : 'aviso'), Response::HTTP_SEE_OTHER);
    }

    // ---------------------------------------------------------------- apoio

    private function pode(SessaoAtiva $sessao, Acao $acao, Objeto $o): bool
    {
        return $this->servicos->permissoes->pode($sessao->usuario, $acao, $o);
    }

    /** Origem e CSRF, antes de qualquer outra coisa. Falhou: a resposta 403 (nada é lido nem gravado). */
    private function recusarPedido(SessaoAtiva $sessao, Request $r): ?Response
    {
        $token = $r->request->all()['_csrf'] ?? null;
        if (!$this->rede->origemConfere($r) || !is_string($token) || !$this->csrf->conferirSessao($sessao->idHash, $token)) {
            return Pagina::resposta('Pedido recusado', '<p>O pedido não pôde ser confirmado. Volte, recarregue a página e tente de novo.</p>', Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /** O objeto, se existe e a pessoa pode a ação; sem permissão registra `$negado` na auditoria (só ids, sem conteúdo). */
    private function autorizado(SessaoAtiva $sessao, int $id, Acao $acao, string $negado): ?Objeto
    {
        $o = $this->servicos->objetos->porId($id);
        if ($o !== null && !$this->pode($sessao, $acao, $o)) {
            $u = $sessao->usuario;
            $this->servicos->auditoria->registrar($negado, 'usuario:' . $u->id, $u->id, $o->id);

            return null;
        }

        return $o;
    }

    /** Regrava (se visível) ou tira do cache (se não) a página de cada objeto, com o estado atual do banco. false = alguma falhou. */
    private function sincronizar(?Objeto ...$objetos): bool
    {
        $vistos = [];
        $ok = true;
        $agora = gmdate('c');
        foreach ($objetos as $o) {
            if ($o === null || isset($vistos[$o->id])) {
                continue;
            }
            $vistos[$o->id] = true;
            $atual = $this->servicos->objetos->porId($o->id);
            $site = $atual === null ? null : $this->servicos->sites->porId($atual->siteId);
            if ($atual === null || $site === null) {
                continue;
            }
            try {
                if ($atual->visivel($agora)) {
                    $this->servicos->publicador->publicar($site, $atual);
                } else {
                    $this->servicos->publicador->despublicar($site, $atual);
                }
            } catch (\Throwable) {
                $ok = false; // a mudança já está salva; a próxima publicação do site recupera a página
            }
        }

        return $ok;
    }

    /** Tira do cache a página no endereço que o objeto tinha (o antigo passa a redirecionar pelo histórico de URLs). */
    private function tirarDoAr(Objeto $antigo): bool
    {
        $site = $this->servicos->sites->porId($antigo->siteId);
        if ($site === null) {
            return false;
        }
        try {
            $this->servicos->publicador->despublicar($site, $antigo);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private function pai(Objeto $o): ?Objeto
    {
        return $o->paiId === null ? null : $this->servicos->objetos->porId($o->paiId);
    }

    /** @return list<Classe> classes que o tipo do pai aceita como filhas (`aceita_filhos` e a lista `filhas`; vazia = qualquer) */
    private function classesAceitas(Objeto $pai): array
    {
        return array_values(array_filter($this->servicos->classes->todas(), fn (Classe $c): bool => $this->aceita($pai, $c)));
    }

    private function aceita(Objeto $pai, Classe $filha): bool
    {
        return $this->classeDe($pai)?->aceita($filha) ?? false;
    }

    /**
     * Propriedades que aparecem nos formulários (as arquivadas ficam guardadas, mas fora deles).
     *
     * @return list<array{nome: string, tipo: string, indexavel: bool, classe?: string, rotulo?: string, obrigatorio?: bool, arquivada?: bool}>
     */
    private function propriedadesAtivas(Classe $classe): array
    {
        return array_values(array_filter($classe->propriedades, static fn (array $p): bool => !($p['arquivada'] ?? false)));
    }

    private function classeDe(Objeto $o): ?Classe
    {
        return $this->servicos->classes->porId($o->classeId);
    }

    private function camposOcultos(SessaoAtiva $sessao, int $versao): string
    {
        return '<input type="hidden" name="_csrf" value="' . Pagina::h($this->csrf->daSessao($sessao->idHash)) . '">'
            . '<input type="hidden" name="versao" value="' . $versao . '">';
    }

    private function opcao(string $valor, string $rotulo, bool $escolhida): string
    {
        return '<option value="' . Pagina::h($valor) . '"' . ($escolhida ? ' selected' : '') . '>' . Pagina::h($rotulo) . '</option>';
    }

    private function linhas(string $texto): string
    {
        return str_replace(["\r\n", "\r"], "\n", $texto);
    }

    /** Bytes de controle (abaixo de 0x20 e 0x7F) nunca aparecem dentro de um caractere UTF-8 de vários bytes. */
    private function temControle(string $texto, bool $multilinha): bool
    {
        return preg_match($multilinha ? '/[\x00-\x08\x0B-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $texto) === 1;
    }

    /** Mesma resposta para "não existe" e "sem permissão": não revela quais ids existem. */
    private function semAcesso(SessaoAtiva $sessao): Response
    {
        return Pagina::resposta('Sem acesso', '<div class="alert alert-warning" role="alert">Você não tem acesso a este conteúdo, ou ele não existe.</div>', Response::HTTP_FORBIDDEN, false, $this->barra($sessao));
    }

    private function pedidoInvalido(SessaoAtiva $sessao): Response
    {
        return Pagina::resposta('Pedido inválido', '<p>O formulário chegou incompleto. Volte e tente de novo.</p>', Response::HTTP_BAD_REQUEST, false, $this->barra($sessao));
    }

    private function naLixeira(SessaoAtiva $sessao, Objeto $o): Response
    {
        return $this->recusa($sessao, 'Na lixeira', 'Este conteúdo está na lixeira. Tire-o da lixeira antes de alterá-lo ou de criar algo dentro dele.', $o);
    }

    private function ehRaiz(SessaoAtiva $sessao, Objeto $o): Response
    {
        return $this->recusa($sessao, 'Página inicial', 'A página inicial do site não pode ser movida nem mandada para a lixeira.', $o);
    }

    private function classeSumiu(SessaoAtiva $sessao): Response
    {
        return Pagina::resposta('Tipo indisponível', '<div class="alert alert-danger" role="alert">O tipo deste conteúdo não foi encontrado. Fale com um administrador.</div>', Response::HTTP_CONFLICT, false, $this->barra($sessao));
    }

    private function recusa(SessaoAtiva $sessao, string $titulo, string $mensagem, Objeto $o, int $status = Response::HTTP_CONFLICT): Response
    {
        return Pagina::resposta($titulo, $this->migalhas($sessao, $o) . '<div class="alert alert-warning" role="alert">' . Pagina::h($mensagem) . '</div>'
            . '<p><a class="btn btn-outline-secondary" href="' . $this->link($o->id) . '">Voltar</a></p>', $status, false, $this->barra($sessao));
    }

    /** Ancestrais que a pessoa pode ver, da raiz até o objeto. Quem vê um ancestral vê todos abaixo dele (herança pelo caminho). */
    private function migalhas(SessaoAtiva $sessao, Objeto $o): string
    {
        $ids = array_map('intval', array_filter(explode('/', trim($o->caminho, '/')), static fn (string $p): bool => $p !== ''));
        $ids = array_values(array_filter($ids, static fn (int $i): bool => $i !== $o->id));
        if ($ids === []) {
            return '';
        }
        $ancestrais = $this->servicos->motor->porIds($o->siteId, $ids, false);
        $partes = [];
        $liberado = false;
        foreach ($ids as $i) {
            $a = $ancestrais[$i] ?? null;
            if ($a === null) {
                continue;
            }
            $liberado = $liberado || $this->pode($sessao, Acao::Ver, $a);
            if ($liberado) {
                $partes[] = '<li class="breadcrumb-item"><a href="' . $this->link($a->id) . '">' . Pagina::h($a->titulo) . '</a></li>';
            }
        }
        if ($partes === []) {
            return '';
        }

        return '<nav aria-label="Caminho"><ol class="breadcrumb">' . implode('', $partes)
            . '<li class="breadcrumb-item"><a href="' . $this->link($o->id) . '" aria-current="page">' . Pagina::h($o->titulo) . '</a></li></ol></nav>';
    }

    private function barra(SessaoAtiva $sessao): string
    {
        return Pagina::barra($sessao->usuario->nome, $this->csrf->daSessao($sessao->idHash));
    }

    private function nomeDaClasse(int $classeId): string
    {
        return $this->classes[$classeId] ??= $this->servicos->classes->porId($classeId)->nome ?? '?';
    }

    private function link(int $id): string
    {
        return Painel::PREFIXO . '/objeto/' . $id;
    }

    /** Inteiro não negativo vindo da requisição (só dígitos, até 9), ou null. */
    private function numero(mixed $valor): ?int
    {
        return is_string($valor) && $valor !== '' && strlen($valor) <= 9 && ctype_digit($valor) ? (int) $valor : null;
    }
}
