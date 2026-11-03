<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Acao;
use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Arquivos\ErroDeUpload;
use Pbl\Arquivos\ServicoDeUpload;
use Pbl\Arquivos\TamanhoLegivel;
use Pbl\Arquivos\TiposDeArquivo;
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
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
    public const MAX_ROTULO_MENU = 60;
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
        'mover' => ['GET', 'POST'], 'apagar' => ['GET', 'POST'], 'recuperar' => ['POST'], 'versoes' => ['GET'], 'restaurar' => ['POST'], 'envios' => ['GET'],
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
            'envios' => $this->envios($sessao, $id, $r),
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
            $corpo .= Pagina::alerta('warning', 'Este conteúdo está na lixeira: fora do ar e sem edição. ' . ($this->pode($sessao, Acao::Apagar, $o)
                ? '<form method="post" action="' . $this->link($o->id) . '/recuperar" class="d-inline">' . $this->camposOcultos($sessao, $o->versao)
                    . '<button type="submit" class="btn btn-sm btn-outline-dark">Tirar da lixeira</button></form>' : ''), '', true);
        }
        $corpo .= $this->selos($o, $agora) . $this->botoes($sessao, $o) . TelasDeRevisao::bloco($this->servicos, $this->csrf, $sessao, $o) . $this->tabelaDeDados($sessao, $o, $agora);

        $corpo .= '<h2 class="h5 mt-4">Conteúdo dentro desta página</h2>' . $this->filtrosDaLista($o, $classes, $ordem, $classeId, $estado);
        if ($filhos === []) {
            $corpo .= '<p class="text-body-secondary">Nenhum.</p>';
        } else {
            $cartoes = '';
            $linhas = '';
            foreach ($filhos as $f) {
                $quando = $f->visivel($agora) ? $f->status : $this->estadoLegivel($f, $agora, true);
                $tipo = $this->nomeDaClasse($f->classeId);
                $editar = $f->status !== self::LIXEIRA && $this->pode($sessao, Acao::Editar, $f)
                    ? '<a class="btn btn-sm btn-outline-primary" href="' . $this->link($f->id) . '/editar">Editar</a>' : '';
                $cartoes .= '<li class="list-group-item pbl-filho"><div class="pbl-filho-corpo"><a class="pbl-filho-titulo" href="' . $this->link($f->id) . '">' . Pagina::h($f->titulo) . '</a>'
                    . '<div class="pbl-filho-meta"><span class="badge text-bg-light border">' . Pagina::h($tipo) . '</span> <span class="badge ' . $this->classeDoEstado($f, $agora) . '">' . Pagina::h($quando) . '</span>'
                    . ' <span class="text-body-secondary small">peso ' . $f->peso . '</span></div></div>' . $editar . '</li>';
                $linhas .= '<tr><td><a href="' . $this->link($f->id) . '">' . Pagina::h($f->titulo) . '</a></td>'
                    . '<td>' . Pagina::h($tipo) . '</td>'
                    . '<td>' . Pagina::h($quando) . '</td>'
                    . '<td>' . $f->peso . '</td><td class="text-end">' . $editar . '</td></tr>';
            }
            $corpo .= '<ul class="list-group pbl-filhos d-md-none mb-3">' . $cartoes . '</ul>'
                . '<div class="table-responsive d-none d-md-block"><table class="table table-sm table-hover align-middle"><caption class="visually-hidden">Conteúdo dentro desta página</caption>'
                . '<thead><tr><th scope="col">Título</th><th scope="col">Tipo</th><th scope="col">Estado</th><th scope="col">Peso</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead><tbody>' . $linhas . '</tbody></table></div>';
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

    /**
     * Envios recebidos por um formulário (ADR 0013): os conteúdos do tipo de envio dentro da seção de destino, fora da lixeira, do
     * mais novo para o mais antigo. Cada envio abre na tela de conteúdo de sempre; "aprovar" é publicar (ou mover) por lá. Quem pode
     * ver o formulário e a seção de destino vê a lista; sem isso, a resposta é a mesma de conteúdo inexistente.
     */
    private function envios(SessaoAtiva $sessao, int $id, Request $r): Response
    {
        $o = $this->servicos->objetos->porId($id);
        if ($o === null || !$this->pode($sessao, Acao::Ver, $o) || $this->nomeDaClasse($o->classeId) !== \Pbl\Formularios\FormularioRepositorio::CLASSE) {
            return $this->semAcesso($sessao);
        }
        $destinoId = $o->dados['destino'] ?? null;
        $destino = is_int($destinoId) ? $this->servicos->objetos->porId($destinoId) : null;
        $tipoNome = $o->dados['classe_envio'] ?? null;
        $tipo = is_string($tipoNome) ? $this->servicos->classes->porNome(trim($tipoNome)) : null;
        if ($destino === null || $destino->siteId !== $o->siteId || $tipo === null || !$this->pode($sessao, Acao::Ver, $destino)) {
            return Pagina::resposta('Envios recebidos', $this->migalhas($sessao, $o)
                . Pagina::alerta('warning', 'Este formulário não tem uma seção de destino e um tipo de envio válidos. Confira a configuração dele.'), Response::HTTP_OK, false, $this->barra($sessao));
        }
        $inicio = $this->numero($r->query->all()['inicio'] ?? null) ?? 0;
        $consulta = new Consulta($o->siteId, $destino->id, 1, [$tipo->id], [new Filtro('status', '!=', self::LIXEIRA)], '-id', $inicio, self::POR_PAGINA, false);
        $envios = $this->servicos->motor->buscar($consulta);
        $total = $this->servicos->motor->contar($consulta);

        $corpo = $this->migalhas($sessao, $o)
            . '<p>' . $total . ' envio(s) do tipo <strong>' . Pagina::h($tipo->nome) . '</strong> em <a href="' . $this->link($destino->id) . '">' . Pagina::h($destino->titulo) . '</a>. '
            . 'Todo envio chega <strong>privado</strong> (fora do ar). Para aprovar, publique-o; publicar mostra ao público todos os campos dele.</p>';
        if ($envios === []) {
            $corpo .= '<p class="text-body-secondary">Nenhum envio ainda.</p>';
        } else {
            $corpo .= '<div class="table-responsive"><table class="table table-sm table-hover align-middle"><caption class="visually-hidden">Envios recebidos</caption>'
                . '<thead><tr><th scope="col">Envio</th><th scope="col">Estado</th><th scope="col">Ações</th></tr></thead><tbody>';
            foreach ($envios as $e) {
                $acoes = '<a href="' . $this->link($e->id) . '">Ver</a>';
                if ($this->pode($sessao, Acao::Publicar, $e)) {
                    $acoes .= ' · <a href="' . $this->link($e->id) . '/estado">Publicar ou recusar</a>';
                }
                $corpo .= '<tr><td>' . Pagina::h($e->titulo) . '</td><td>' . Pagina::h($e->status) . '</td><td>' . $acoes . '</td></tr>';
            }
            $corpo .= '</tbody></table></div>';
            if ($total > $inicio + self::POR_PAGINA) {
                $corpo .= '<p><a href="' . $this->link($o->id) . '/envios?inicio=' . ($inicio + self::POR_PAGINA) . '">Próximos</a></p>';
            }
            if ($inicio > 0) {
                $corpo .= '<p><a href="' . $this->link($o->id) . '/envios?inicio=' . max(0, $inicio - self::POR_PAGINA) . '">Anteriores</a></p>';
            }
        }

        return Pagina::resposta('Envios recebidos: ' . $o->titulo, $corpo, Response::HTTP_OK, false, $this->barra($sessao));
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
            'enviado' => ['success', 'Enviado para revisão. Ele continua fora do ar até alguém da revisão aprovar.'],
            'reenviado' => ['success', 'Reenviado para revisão.'],
            'retirado' => ['info', 'Envio retirado: o conteúdo voltou a ser privado.'],
            'aprovado' => ['success', 'Aprovado e publicado.'],
            'devolvido' => ['info', 'Devolvido ao autor com o motivo informado.'],
            default => null,
        };

        return $texto === null ? '' : Pagina::alerta($texto[0], $texto[1]);
    }

    /** Selos do topo da tela: tipo, estado e versão (o título é o `<h1>` da página). */
    private function selos(Objeto $o, string $agora): string
    {
        return '<p class="pbl-selos"><span class="badge text-bg-light border">' . Pagina::h($this->nomeDaClasse($o->classeId)) . '</span> '
            . '<span class="badge ' . $this->classeDoEstado($o, $agora) . '">' . Pagina::h($o->status === self::LIXEIRA ? 'na lixeira' : ($o->visivel($agora) ? $o->status . ', no ar' : $o->status . ', fora do ar')) . '</span> '
            . '<span class="badge text-bg-light border">versão ' . $o->versao . '</span>'
            . (($v = $this->vigencia($o, $agora)) === '' ? '' : ' <span class="badge text-bg-warning">' . Pagina::h($v) . '</span>') . '</p>';
    }

    private function classeDoEstado(Objeto $o, string $agora): string
    {
        return match (true) {
            $o->status === self::LIXEIRA => 'text-bg-danger',
            $o->visivel($agora) => 'text-bg-success',
            $o->status === 'publicado' => 'text-bg-warning',
            default => 'text-bg-secondary',
        };
    }

    /** Metadados em grade compacta (rótulo pequeno em cima, valor embaixo) e os campos de conteúdo, cada um num cartão. */
    private function tabelaDeDados(SessaoAtiva $sessao, Objeto $o, string $agora): string
    {
        $item = static fn (string $rotulo, string $valor, string $classe = ''): string => '<div' . ($classe === '' ? '' : ' class="' . $classe . '"') . '><dt>' . $rotulo . '</dt><dd>' . $valor . '</dd></div>';
        $html = '<dl class="pbl-meta">'
            . $item('Número', (string) $o->id)
            . $item('Peso', (string) $o->peso)
            . $item('Estado', Pagina::h($this->estadoLegivel($o, $agora)), 'pbl-largo');
        if ($o->dataPublicacao !== null) {
            $html .= $item('Publicação a partir de', Pagina::h($this->dataLegivel($o->dataPublicacao)));
        }
        if ($o->dataValidade !== null) {
            $html .= $item('Fora do ar a partir de', Pagina::h($this->dataLegivel($o->dataValidade)));
        }
        $html .= $item('Endereço', '<code class="pbl-endereco">/' . Pagina::h($o->urlAmigavel) . '</code>', 'pbl-largo') . '</dl>';

        $html .= '<section class="pbl-card pbl-conteudo"><h2 class="pbl-card-titulo">Descrição</h2>'
            . ($o->descricao === '' ? '<p class="mb-0"><em>sem descrição</em></p>' : '<p class="mb-0">' . nl2br(Pagina::h($o->descricao)) . '</p>') . '</section>';
        foreach ($this->classeDe($o)->propriedades ?? [] as $p) {
            $valor = $o->dados[$p['nome']] ?? null;
            $html .= '<section class="pbl-card pbl-conteudo"><h2 class="pbl-card-titulo">' . Pagina::h(Classe::rotuloDe($p)) . '</h2>' . $this->valorEmCartao($sessao, $o, $p, $valor) . '</section>';
        }

        return $html;
    }

    /**
     * Valor de uma propriedade no cartão da tela do conteúdo. Campo `html`: o corpo aparece já renderizado (limpo de novo por
     * HtmlSeguro, mesmo vindo do banco), com altura limitada, e o código fica num "Ver HTML" recolhido (texto escapado, nunca
     * interpretado). Os demais tipos seguem `valorLegivel`.
     *
     * @param array{nome: string, tipo: string, indexavel: bool, classe?: string} $p
     */
    private function valorEmCartao(SessaoAtiva $sessao, Objeto $o, array $p, string|int|float|null $valor): string
    {
        if ($p['tipo'] !== 'html' || $valor === null || $valor === '') {
            return '<div class="pbl-valor">' . $this->valorLegivel($sessao, $o, $p, $valor) . '</div>';
        }
        $texto = (string) $valor;
        try {
            $limpo = HtmlSeguro::limpar($texto);
        } catch (ViolacaoDeRegra) {
            $limpo = '<p><em>Conteúdo grande demais para mostrar aqui.</em></p>';
        }
        $codigo = mb_strlen($texto) > 20000 ? mb_substr($texto, 0, 20000) . '…' : $texto;

        return '<div class="pbl-corpo" ' . Pagina::MARCA_PREVIA . '1">' . $limpo . '</div>'
            . '<details class="pbl-ver-html"><summary>Ver HTML</summary><pre class="pbl-realce mb-0"><code class="text-break">' . Pagina::h($codigo) . '</code></pre></details>';
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
        if (in_array($p['tipo'], ['arquivo', 'imagem'], true)) {
            return $this->arquivoLegivel((int) $valor);
        }
        $texto = (string) $valor;
        $curto = mb_strlen($texto) > 300 ? mb_substr($texto, 0, 300) . '…' : $texto;

        // HTML guardado aparece como texto (nunca interpretado na administração).
        return $p['tipo'] === 'html' ? '<code class="text-break">' . Pagina::h($curto) . '</code>' : nl2br(Pagina::h($curto));
    }

    /**
     * Mostra o arquivo guardado numa propriedade `arquivo`/`imagem`: nome, tamanho e um link para `ArquivoController` (que
     * só entrega de verdade se o objeto dono estiver publicado e vigente; aqui, na administração, o link pode dar 404 se o
     * conteúdo ainda não foi publicado — a tela avisa disso).
     */
    private function arquivoLegivel(int $id): string
    {
        $arquivo = $this->servicos->arquivos->porId($id);
        if ($arquivo === null) {
            return '<em>arquivo nº ' . $id . ' (não encontrado)</em>';
        }
        $link = Painel::PREFIXO . '/arquivo/' . $arquivo->id;
        $html = '<a href="' . Pagina::h($link) . '">' . Pagina::h($arquivo->nome) . '</a> (' . TamanhoLegivel::formatar($arquivo->tamanho) . ')';
        if ($arquivo->ehImagem()) {
            $html .= '<br><img src="' . Pagina::h($link . '/miniatura/150') . '" alt="" width="150" loading="lazy">';
        }

        return $html . '<div class="form-text">O link só funciona se este conteúdo estiver publicado e vigente.</div>';
    }

    /**
     * Ações que a pessoa pode fazer aqui (esconder é só conforto: cada rota confere de novo). "Editar" fica sempre à vista; as demais
     * ficam em linha no desktop e num dropdown "Mais ações" (Bootstrap) no celular.
     */
    private function botoes(SessaoAtiva $sessao, Objeto $o): string
    {
        $outras = [];
        $naLixeira = $o->status === self::LIXEIRA;
        $editar = !$naLixeira && $this->pode($sessao, Acao::Editar, $o) ? '<a class="btn btn-primary" href="' . $this->link($o->id) . '/editar">Editar</a>' : '';
        if (!$naLixeira && $this->servicos->permissoes->podeCriarSob($sessao->usuario, $o) && $this->classesAceitas($o) !== []) {
            $outras[] = ['Criar conteúdo aqui dentro', $this->link($o->id) . '/novo', false];
        }
        if (!$naLixeira && $this->pode($sessao, Acao::Publicar, $o)) {
            $outras[] = ['Publicar ou tirar do ar', $this->link($o->id) . '/estado', false];
        }
        if ($this->nomeDaClasse($o->classeId) === \Pbl\Formularios\FormularioRepositorio::CLASSE) {
            $outras[] = ['Envios recebidos', $this->link($o->id) . '/envios', false];
        }
        $outras[] = ['Histórico de versões', $this->link($o->id) . '/versoes', false];
        if (!$naLixeira && $o->paiId !== null && $this->pode($sessao, Acao::Apagar, $o)) {
            $outras[] = ['Mover', $this->link($o->id) . '/mover', false];
            $outras[] = ['Mandar para a lixeira', $this->link($o->id) . '/apagar', true];
        }

        $desktop = '';
        $menu = '';
        foreach ($outras as [$rotulo, $href, $perigo]) {
            $desktop .= '<a class="btn ' . ($perigo ? 'btn-outline-danger' : 'btn-outline-secondary') . '" href="' . Pagina::h($href) . '">' . Pagina::h($rotulo) . '</a>';
            $menu .= '<li><a class="dropdown-item' . ($perigo ? ' text-danger' : '') . '" href="' . Pagina::h($href) . '">' . Pagina::h($rotulo) . '</a></li>';
        }

        return '<div class="pbl-barra-acoes">' . $editar
            . '<div class="dropdown d-md-none"><button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Mais ações</button>'
            . '<ul class="dropdown-menu dropdown-menu-end">' . $menu . '</ul></div>'
            . '<div class="d-none d-md-flex flex-wrap gap-2">' . $desktop . '</div></div>';
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

        $filtrando = $ordem !== 'peso' || $classeId !== null || $estado !== '';

        return '<p class="mb-2"><button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filtros-lista" aria-expanded="' . ($filtrando ? 'true' : 'false') . '" aria-controls="filtros-lista">Filtrar e ordenar' . ($filtrando ? ' (ativo)' : '') . '</button></p>'
            . '<div id="filtros-lista" class="collapse' . ($filtrando ? ' show' : '') . '"><form method="get" action="' . $this->link($o->id) . '" class="pbl-filtros mb-3" aria-label="Ordem e filtros da lista">'
            . '<div><label for="campo-ordem" class="form-label">Ordem</label><select id="campo-ordem" name="ordem" class="form-select form-select-sm">' . $opcoesOrdem . '</select></div>'
            . '<div><label for="campo-classe" class="form-label">Tipo</label><select id="campo-classe" name="classe" class="form-select form-select-sm">' . $opcoesClasse . '</select></div>'
            . '<div><label for="campo-estado" class="form-label">Estado</label><select id="campo-estado" name="estado" class="form-select form-select-sm">' . $opcoesEstado . '</select></div>'
            . '<div class="pbl-filtros-aplicar"><button type="submit" class="btn btn-sm btn-primary">Aplicar</button></div></form></div>';
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
        $lido = $this->lerFormulario($sessao, $o->siteId, $classe, $campos, $o, $r);
        if ($versao === null || $versao < 1 || $lido === null) {
            return $this->pedidoInvalido($sessao);
        }
        [$valores, $digitado, $erros] = $lido;
        // Estado e datas de vigência só valem para quem pode publicar (mesma regra da tela "Publicar ou tirar do ar") e se o formulário os trouxe.
        $vigente = [];
        if (isset($campos['estado']) && $this->pode($sessao, Acao::Publicar, $o)) {
            $ee = $this->lerEstadoEDatas($campos);
            if ($ee === null) {
                return $this->pedidoInvalido($sessao);
            }
            [$estado, $inicio, $fim, $dataInicio, $dataFim, $errosDatas] = $ee;
            $digitado['estado'] = $estado;
            $digitado['data_publicacao'] = $inicio;
            $digitado['data_validade'] = $fim;
            $erros += $errosDatas;
            $vigente = $this->mudancasDeEstado($o, $estado, $dataInicio, $dataFim);
        }
        if ($erros !== []) {
            return $this->telaDeEdicao($sessao, $o, $classe, $digitado, $versao, $erros, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        // Na edição $pendentes vem sempre vazio (o arquivo é enviado na hora, ver lerFormulario); só a criação adia.

        $mudancas = [];
        foreach (['titulo' => $o->titulo, 'descricao' => $o->descricao, 'url_amigavel' => $o->urlAmigavel, 'peso' => $o->peso, 'no_menu' => $o->noMenu, 'rotulo_menu' => $o->rotuloMenu] as $campo => $atual) {
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
        $mudancas += $vigente;
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
        $agendado = $vigente !== [] && $novo->status === 'publicado' && $novo->dataPublicacao !== null && $novo->dataPublicacao > gmdate('c');

        return new RedirectResponse($this->link($novo->id) . '?salvo=' . (!$ok ? 'aviso' : ($agendado ? 'agendado' : 'sim')), Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, string> $digitado
     * @param array<string, string> $erros campo => mensagem (`_` = erro geral)
     */
    private function telaDeEdicao(SessaoAtiva $sessao, Objeto $o, Classe $classe, array $digitado, int $versao, array $erros = [], int $status = Response::HTTP_OK, bool $mostrarAtual = false): Response
    {
        $corpo = $this->migalhas($sessao, $o) . $this->resumoDeErros($erros)
            . '<form method="post" action="' . $this->link($o->id) . '/editar" enctype="multipart/form-data" novalidate>' . $this->camposOcultos($sessao, $versao)
            . $this->camposDoFormulario($classe, $digitado, $erros, $o->paiId === null, false, $this->pode($sessao, Acao::Publicar, $o) ? $this->vigencia($o, gmdate('c')) : null)
            . Pagina::acoes('Salvar', [['Cancelar', $this->link($o->id)]], null, 'btn-primary', 'pbl-acoes-fixas') . '</form>';
        if ($mostrarAtual) {
            $corpo .= '<h2 class="h5 mt-4">Salvo agora (versão ' . $o->versao . ')</h2><div class="table-responsive"><table class="table table-sm"><caption class="visually-hidden">Valores salvos agora</caption><tbody>'
                . '<tr><th scope="row">Título</th><td>' . Pagina::h($o->titulo) . '</td></tr>'
                . '<tr><th scope="row">Descrição</th><td>' . nl2br(Pagina::h($o->descricao)) . '</td></tr>'
                . '<tr><th scope="row">Endereço</th><td><code>/' . Pagina::h($o->urlAmigavel) . '</code></td></tr>'
                . '<tr><th scope="row">Peso</th><td>' . $o->peso . '</td></tr>';
            foreach ($classe->propriedades as $p) {
                $corpo .= '<tr><th scope="row">' . Pagina::h(Classe::rotuloDe($p)) . '</th><td>' . $this->valorLegivel($sessao, $o, $p, $o->dados[$p['nome']] ?? null) . '</td></tr>';
            }
            $corpo .= '</tbody></table></div>';
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
        $vazio = ['titulo' => '', 'descricao' => '', 'url' => '', 'peso' => '0', 'no_menu' => '0', 'rotulo_menu' => ''];

        return $this->telaDeCriacao($sessao, $pai, $classe, $vazio);
    }

    private function escolherClasse(SessaoAtiva $sessao, Objeto $pai): Response
    {
        $classes = $this->classesAceitas($pai);
        if ($classes === []) {
            return Pagina::resposta('Criar conteúdo', $this->migalhas($sessao, $pai) . Pagina::alerta('info', 'O tipo de ' . $pai->titulo . ' não aceita conteúdo dentro dele (ou ainda não há tipos cadastrados).')
                . Pagina::acoes('', [['Voltar', $this->link($pai->id)]]), Response::HTTP_OK, false, $this->barra($sessao));
        }
        $opcoes = '';
        foreach ($classes as $c) {
            $opcoes .= $this->opcao((string) $c->id, $c->nome, false);
        }
        $corpo = $this->migalhas($sessao, $pai)
            . '<p>O novo conteúdo vai ficar dentro de <strong>' . Pagina::h($pai->titulo) . '</strong>. Primeiro escolha o tipo: os campos do formulário dependem dele.</p>'
            . '<form method="get" action="' . $this->link($pai->id) . '/novo">'
            . '<div class="mb-3"><label for="campo-tipo" class="form-label">Tipo de conteúdo</label><select id="campo-tipo" name="classe" class="form-select" required>' . $opcoes . '</select></div>'
            . Pagina::acoes('Continuar', [['Cancelar', $this->link($pai->id)]]) . '</form>';

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
        $lido = $classe === null ? null : $this->lerFormulario($sessao, $pai->siteId, $classe, $campos, null, $r);
        if ($classe === null || $lido === null) {
            return $this->pedidoInvalido($sessao);
        }
        if (!$this->aceita($pai, $classe)) {
            return $this->recusa($sessao, 'Tipo não aceito', 'O tipo de ' . $pai->titulo . ' não aceita conteúdo do tipo ' . $classe->nome . ' dentro dele.', $pai, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        [$valores, $digitado, $erros, $pendentes] = $lido;
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
        $aposInserir = $pendentes === [] ? null : $this->enviarArquivosPendentes($pendentes, $classe, $pai->siteId, $u->id);
        try {
            // Atômico: o objeto só existe se os arquivos pendentes também foram gravados (ADR 0014 §4). `$aposInserir`
            // roda dentro da mesma transação de `criarDentro`/`criar`; se algum upload falhar (`ErroDeUpload`), o insert
            // inteiro é desfeito e nada fica pela metade.
            $novo = $this->servicos->objetos->criarDentro($pai->siteId, $classe, $pai->id, $valores['titulo'], $url, $dados, 'privado', [
                'peso' => $valores['peso'], 'descricao' => $valores['descricao'], 'no_menu' => $valores['no_menu'], 'rotulo_menu' => $valores['rotulo_menu'], 'criado_por' => $u->id,
            ], $aposInserir);
        } catch (ConflitoDeArvore) {
            return $this->naLixeira($sessao, $pai);
        // phpstan não enxerga o que um Closure genérico (`$aposInserir`, construído em `enviarArquivosPendentes`) lança: o
        // catch é real (ver o teste de upload inválido na criação), só opaco para a análise estática através do tipo `Closure`.
        } catch (ErroDeUpload $e) { // @phpstan-ignore catch.neverThrown
            $erroArquivo = $e->propriedade !== '' ? ['dados.' . $e->propriedade => $e->getMessage()] : ['_' => $e->getMessage()];

            return $this->telaDeCriacao($sessao, $pai, $classe, $digitado, $erroArquivo, Response::HTTP_UNPROCESSABLE_ENTITY);
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
            . '<form method="post" action="' . $this->link($pai->id) . '/novo" enctype="multipart/form-data" novalidate>'
            . '<input type="hidden" name="_csrf" value="' . Pagina::h($this->csrf->daSessao($sessao->idHash)) . '">'
            . '<input type="hidden" name="classe" value="' . $classe->id . '">'
            . $this->camposDoFormulario($classe, $digitado, $erros, false, true)
            . Pagina::acoes('Criar', [['Cancelar', $this->link($pai->id)]], null, 'btn-primary', 'pbl-acoes-fixas') . '</form>';

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
     * `$r` só é usado para os arquivos enviados (`dados[nome]`, `multipart/form-data`) numa propriedade `arquivo`/`imagem`.
     * Na edição (`$atual` existe), o arquivo é enviado na hora (o dono já existe) e o id resultante já entra em `dados`. Na
     * criação (`$atual` null), o arquivo ainda não tem dono: fica em `$pendentes` (4º item do retorno) para `criar()` enviar
     * só depois que o objeto existir, dentro da mesma transação (ADR 0014 §4) — por isso aqui só valida se o campo é
     * obrigatório e nada foi enviado; o conteúdo do arquivo em si só é conferido depois.
     *
     * @param array<string, mixed> $campos
     * @return array{0: array{titulo: string, descricao: string, url_amigavel?: string, peso: int, no_menu: bool, rotulo_menu: string, dados: array<string, string|int|float|null>}, 1: array<string, string>, 2: array<string, string>, 3: array<string, UploadedFile>}|null
     */
    private function lerFormulario(SessaoAtiva $sessao, int $siteId, Classe $classe, array $campos, ?Objeto $atual, ?Request $r = null): ?array
    {
        // Na edição, campo que nem veio no pedido mantém o valor atual (campo vazio, que o navegador sempre envia, remove o valor).
        $titulo = $campos['titulo'] ?? null;
        $descricao = $campos['descricao'] ?? null;
        $url = $campos['url'] ?? ($atual === null ? '' : $atual->urlAmigavel);
        $peso = $campos['peso'] ?? ($atual === null ? '0' : (string) $atual->peso);
        $noMenu = $campos['no_menu'] ?? ($atual?->noMenu ? '1' : '0');
        $rotulo = $campos['rotulo_menu'] ?? ($atual === null ? '' : $atual->rotuloMenu);
        $dadosBrutos = $campos['dados'] ?? [];
        if (!is_string($titulo) || !is_string($descricao) || !is_string($url) || !is_string($peso) || !is_string($noMenu) || !is_string($rotulo) || !is_array($dadosBrutos)) {
            return null;
        }
        $titulo = trim($titulo);
        $descricao = trim($this->linhas($descricao));
        $url = trim($url);
        $peso = trim($peso);
        $rotulo = trim($rotulo);
        $digitado = ['titulo' => $titulo, 'descricao' => $descricao, 'url' => $url, 'peso' => $peso, 'no_menu' => $noMenu === '1' ? '1' : '0', 'rotulo_menu' => $rotulo];
        $erros = [];
        $valores = ['titulo' => $titulo, 'descricao' => $descricao, 'peso' => 0, 'no_menu' => $noMenu === '1', 'rotulo_menu' => $rotulo, 'dados' => []];
        if (!mb_check_encoding($rotulo, 'UTF-8') || $this->temControle($rotulo, false)) {
            $erros['rotulo_menu'] = 'O texto do menu tem caracteres que não são permitidos.';
        } elseif (mb_strlen($rotulo) > self::MAX_ROTULO_MENU) {
            $erros['rotulo_menu'] = 'O texto do menu pode ter até ' . self::MAX_ROTULO_MENU . ' caracteres.';
        }

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

        $removerBrutos = $campos['dados_remover'] ?? [];
        $arquivosBrutos = $r?->files->all()['dados'] ?? [];
        /** @var array<string, UploadedFile> $pendentes */
        $pendentes = [];
        // Campo arquivado não aparece no formulário e não é tocado: o valor guardado continua como está.
        foreach ($this->propriedadesAtivas($classe) as $p) {
            $nome = $p['nome'];
            $guardado = $atual === null || !isset($atual->dados[$nome]) ? null : (string) $atual->dados[$nome];
            if (in_array($p['tipo'], ['arquivo', 'imagem'], true)) {
                $digitado['dados.' . $nome] = $guardado ?? '';
                $enviado = is_array($arquivosBrutos) ? ($arquivosBrutos[$nome] ?? null) : null;
                $enviado = $enviado instanceof UploadedFile ? $enviado : null;
                $removido = is_array($removerBrutos) && ($removerBrutos[$nome] ?? null) === '1';
                if ($atual === null) {
                    // Criando: o arquivo ainda não tem dono, então só fica pendente (ver `criar()`); aqui só confere se um
                    // campo obrigatório ficou mesmo sem nada enviado.
                    if ($enviado !== null) {
                        $pendentes[$nome] = $enviado;
                    } elseif ($p['obrigatorio'] ?? false) {
                        $erros['dados.' . $nome] = 'Este campo é obrigatório.';
                    }
                    continue;
                }
                [$valor, $erro] = $this->lerPropriedadeDeArquivo($p, $atual, $enviado, $removido, $guardado, $sessao->usuario->id);
                if ($erro === null && $valor === null && ($p['obrigatorio'] ?? false)) {
                    $erro = 'Este campo é obrigatório.';
                }
                if ($erro !== null) {
                    $erros['dados.' . $nome] = $erro;
                } else {
                    $valores['dados'][$nome] = $valor;
                }
                continue;
            }
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

        return [$valores, $digitado, $erros, $pendentes];
    }

    /**
     * O `$aposInserir` de `ObjetoRepositorio::criar`: envia, dentro da mesma transação, cada arquivo que ficou pendente na
     * criação (ADR 0014 §4) — agora que o objeto novo já tem um id para ser o dono. Uma falha de upload vira `ErroDeUpload`
     * com a propriedade marcada, para `criar()` mostrar o erro no campo certo; a exceção desfaz a transação inteira.
     *
     * @param array<string, UploadedFile> $pendentes
     * @return \Closure(int): array<string, int>
     */
    private function enviarArquivosPendentes(array $pendentes, Classe $classe, int $siteId, int $usuarioId): \Closure
    {
        $definidas = [];
        foreach ($this->propriedadesAtivas($classe) as $p) {
            $definidas[$p['nome']] = $p;
        }

        return function (int $novoId) use ($pendentes, $definidas, $siteId, $usuarioId): array {
            $extra = [];
            foreach ($pendentes as $nome => $enviado) {
                $p = $definidas[$nome] ?? null;
                if ($p === null) {
                    continue; // campo removido da classe entre o formulário ser montado e o envio: ignora, não trava a criação
                }
                try {
                    $arquivo = $this->servicos->uploads()->enviar($enviado, $p, $siteId, $novoId, $usuarioId);
                } catch (ErroDeUpload $e) {
                    throw new ErroDeUpload($e->getMessage(), $nome, $e);
                }
                $extra[$nome] = $arquivo->id;
            }

            return $extra;
        };
    }

    /**
     * Lê uma propriedade `arquivo`/`imagem` na edição (o objeto já existe, então o arquivo já tem dono na hora: diferente da
     * criação, que adia o envio, ver `lerFormulario`): arquivo novo enviado substitui o atual (`ServicoDeUpload` valida
     * tipo, tamanho e conteúdo); "remover" marcado tira o valor; sem nenhum dos dois, o valor guardado continua (o arquivo
     * físico só é removido pelo `bin/pbl limpar-uploads`, nunca na requisição).
     *
     * @param array{nome: string, tipo: string, tipos?: list<string>, tamanho_max_mb?: int} $p
     * @return array{0: int|null, 1: string|null} [id do arquivo, erro]
     */
    private function lerPropriedadeDeArquivo(array $p, Objeto $atual, ?UploadedFile $enviado, bool $removido, ?string $guardado, int $usuarioId): array
    {
        if ($enviado !== null) {
            try {
                $arquivo = $this->servicos->uploads()->enviar($enviado, $p, $atual->siteId, $atual->id, $usuarioId);

                return [$arquivo->id, null];
            } catch (ErroDeUpload $e) {
                return [null, $e->getMessage()];
            }
        }
        if ($removido) {
            return [null, null];
        }

        return [$guardado !== null && ctype_digit($guardado) ? (int) $guardado : null, null];
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
        $d = ['titulo' => $o->titulo, 'descricao' => $o->descricao, 'url' => $o->urlAmigavel, 'peso' => (string) $o->peso, 'no_menu' => $o->noMenu ? '1' : '0', 'rotulo_menu' => $o->rotuloMenu,
            'estado' => $o->status, 'data_publicacao' => $this->dataParaCampo($o->dataPublicacao), 'data_validade' => $this->dataParaCampo($o->dataValidade)];
        foreach ($classe->propriedades as $p) {
            $d['dados.' . $p['nome']] = isset($o->dados[$p['nome']]) ? (string) $o->dados[$p['nome']] : '';
        }

        return $d;
    }

    /**
     * Formulário de conteúdo em seções: "Identificação", um cartão por campo do tipo, "Publicação" (estado e datas só para quem pode
     * publicar na edição; endereço, peso) e o menu num accordion fechado. `$vigencia` = null esconde estado e datas (criação ou sem
     * permissão de publicar); texto (pode ser '') mostra e usa como aviso de agendamento.
     *
     * @param array<string, string> $digitado
     * @param array<string, string> $erros
     */
    private function camposDoFormulario(Classe $classe, array $digitado, array $erros, bool $raiz, bool $criando = false, ?string $vigencia = null): string
    {
        $identificacao = $this->campo('titulo', 'Título', '<input id="campo-titulo" class="form-control' . $this->invalido($erros, 'titulo') . '" name="titulo" maxlength="' . self::MAX_TITULO . '" required value="' . Pagina::h($digitado['titulo'] ?? '') . '"' . $this->descrito($erros, 'titulo') . '>', $erros)
            . $this->campo('descricao', 'Descrição', '<textarea id="campo-descricao" class="form-control' . $this->invalido($erros, 'descricao') . '" name="descricao" rows="3" maxlength="' . self::MAX_DESCRICAO . '"' . $this->descrito($erros, 'descricao', true) . '>' . Pagina::h($digitado['descricao'] ?? '') . '</textarea>', $erros, 'Opcional, até ' . self::MAX_DESCRICAO . ' caracteres.');
        if (!$raiz) {
            $identificacao .= $this->campo('url', 'Endereço (URL amigável)', '<div class="input-group"><span class="input-group-text">/</span><input id="campo-url" class="form-control' . $this->invalido($erros, 'url') . '" name="url" maxlength="' . Caminhos::URL_TAMANHO_MAXIMO . '" value="' . Pagina::h($digitado['url'] ?? '') . '"' . $this->descrito($erros, 'url', true) . '></div>', $erros,
                ($criando ? 'Opcional: se vazio, vem do título.' : 'Se mudar, o endereço antigo leva ao novo.') . $this->ajudaDetalhada('Letras minúsculas sem acento, números, hífen e "/".'));
        }
        $html = Pagina::card('Identificação', $identificacao);

        foreach ($this->propriedadesAtivas($classe) as $p) {
            $html .= $this->campoDePropriedade($p, $digitado['dados.' . $p['nome']] ?? '', $erros, $criando);
        }

        $ajudaData = 'Horário de Brasília. ';
        $publicacao = '';
        if ($vigencia !== null) {
            $opcoes = '';
            foreach (self::ESTADOS as $chave => $rotulo) {
                $opcoes .= $this->opcao($chave, $rotulo, $chave === ($digitado['estado'] ?? 'privado'));
            }
            $publicacao .= ($vigencia === '' ? '' : '<p class="pbl-vigencia"><span class="badge text-bg-warning">' . Pagina::h($vigencia) . '</span></p>')
                . $this->campo('estado', 'Estado', '<select id="campo-estado" name="estado" class="form-select' . $this->invalido($erros, 'estado') . '"' . $this->descrito($erros, 'estado') . '>' . $opcoes . '</select>', $erros)
                . '<div class="pbl-grade">'
                . $this->campo('data_publicacao', 'Publicar a partir de', '<input id="campo-data_publicacao" type="datetime-local" class="form-control' . $this->invalido($erros, 'data_publicacao') . '" name="data_publicacao" value="' . Pagina::h($digitado['data_publicacao'] ?? '') . '"' . $this->descrito($erros, 'data_publicacao', true) . '>', $erros, $ajudaData . 'Vazio = assim que publicado.')
                . $this->campo('data_validade', 'Tirar do ar a partir de', '<input id="campo-data_validade" type="datetime-local" class="form-control' . $this->invalido($erros, 'data_validade') . '" name="data_validade" value="' . Pagina::h($digitado['data_validade'] ?? '') . '"' . $this->descrito($erros, 'data_validade', true) . '>', $erros, $ajudaData . 'Vazio = sem prazo.')
                . '</div>';
        }
        $publicacao .= $this->campo('peso', 'Peso', '<input id="campo-peso" class="form-control campo-ordem' . $this->invalido($erros, 'peso') . '" name="peso" type="number" step="1" min="-999999" max="999999" value="' . Pagina::h($digitado['peso'] ?? '0') . '"' . $this->descrito($erros, 'peso', true) . '>', $erros, 'Menor aparece primeiro nas listas e no menu.');
        $html .= Pagina::card('Publicação', $publicacao);

        if ($criando || !$raiz) {
            $menu = $this->campo('no_menu', 'Aparece no menu do site', '<select id="campo-no-menu" class="form-select" name="no_menu">' . $this->opcao('1', 'Sim', ($digitado['no_menu'] ?? '0') === '1') . $this->opcao('0', 'Não', ($digitado['no_menu'] ?? '0') !== '1') . '</select>', $erros, 'Vale para conteúdo direto na página inicial ou dentro de um item do menu.')
                . $this->campo('rotulo_menu', 'Texto no menu', '<input id="campo-rotulo-menu" class="form-control' . $this->invalido($erros, 'rotulo_menu') . '" name="rotulo_menu" maxlength="' . self::MAX_ROTULO_MENU . '" value="' . Pagina::h($digitado['rotulo_menu'] ?? '') . '"' . $this->descrito($erros, 'rotulo_menu', true) . '>', $erros, 'Opcional: vazio usa o título.');
            $abrir = isset($erros['no_menu']) || isset($erros['rotulo_menu']);
            $html .= '<div class="accordion mb-3" id="acc-secundarias"><div class="accordion-item"><h2 class="accordion-header">'
                . '<button class="accordion-button' . ($abrir ? '' : ' collapsed') . '" type="button" data-bs-toggle="collapse" data-bs-target="#sec-menu" aria-expanded="' . ($abrir ? 'true' : 'false') . '" aria-controls="sec-menu">Menu do site</button></h2>'
                . '<div id="sec-menu" class="accordion-collapse collapse' . ($abrir ? ' show' : '') . '"><div class="accordion-body">' . $menu . '</div></div></div></div>';
        }

        return $html;
    }

    /**
     * @param array{nome: string, tipo: string, indexavel: bool, classe?: string, rotulo?: string, obrigatorio?: bool, arquivada?: bool, tipos?: list<string>, tamanho_max_mb?: int} $p
     * @param array<string, string> $erros
     */
    private function campoDePropriedade(array $p, string $valor, array $erros, bool $criando = false): string
    {
        $chave = 'dados.' . $p['nome'];
        // O nome de propriedade já é [a-z][a-z0-9_]* (ClasseRepositorio), mas é dado do banco: escapado mesmo assim.
        $id = 'campo-dados-' . $p['nome'];
        $classe = $this->invalido($erros, $chave);
        if (in_array($p['tipo'], ['arquivo', 'imagem'], true)) {
            return $this->campoDeArquivo($p, $valor, $erros, $criando);
        }
        $comum = ' id="' . Pagina::h($id) . '" name="' . Pagina::h('dados[' . $p['nome'] . ']') . '"' . $this->descrito($erros, $chave, true)
            . (($p['obrigatorio'] ?? false) ? ' required' : '');
        [$controle, $ajuda] = match ($p['tipo']) {
            'numero' => ['<input class="form-control' . $classe . '"' . $comum . ' inputmode="decimal" value="' . Pagina::h($valor) . '">', 'Número (use vírgula ou ponto para decimais).'],
            'data' => ['<input class="form-control' . $classe . '"' . $comum . ' maxlength="25" value="' . Pagina::h($valor) . '">', 'Data no formato aaaa-mm-dd, com hora opcional (2026-12-31 18:30).'],
            'booleano' => ['<select class="form-select' . $classe . '"' . $comum . '>' . $this->opcao('', '(sem valor)', $valor === '') . $this->opcao('1', 'Sim', $valor === '1') . $this->opcao('0', 'Não', $valor === '0') . '</select>', 'Sim ou não.'],
            'ref' => ['<input class="form-control' . $classe . '"' . $comum . ' type="number" min="1" step="1" value="' . Pagina::h($valor) . '">', 'Número de outro conteúdo deste site' . (isset($p['classe']) ? ' (do tipo ' . Pagina::h($p['classe']) . ')' : '') . '.' . $this->ajudaDetalhada('O número aparece na página de cada conteúdo.')],
            'html' => ['<textarea class="form-control font-monospace' . $classe . '"' . $comum . ' rows="10" maxlength="' . self::MAX_HTML . '" ' . Pagina::MARCA_EDITOR . 'html" data-pbl-editor-base="' . Pagina::h(Pagina::TINYMCE) . '">' . Pagina::h($valor) . '</textarea>', 'Use os botões acima para formatar. Até ' . self::MAX_HTML . ' caracteres.' . $this->ajudaDetalhada('Dá para colar do Word, do Google Docs ou de uma página. Títulos, negrito, listas, links, tabelas e imagens por endereço funcionam; o botão de código-fonte mostra o HTML. Ao salvar, o HTML é limpo: ficam só marcações comuns; scripts, formulários, eventos e estilos além de alinhamento e tamanho do texto são removidos. Sem JavaScript, o campo aceita HTML direto.')],
            default => ['<textarea class="form-control' . $classe . '"' . $comum . ' rows="3" maxlength="' . self::MAX_TEXTO . '">' . Pagina::h($valor) . '</textarea>', 'Texto, até ' . self::MAX_TEXTO . ' caracteres.'],
        };

        return '<div class="pbl-card pbl-campo-tipo">' . $this->campo($chave, Classe::rotuloDe($p) . (($p['obrigatorio'] ?? false) ? ' (obrigatório)' : ''), $controle, $erros, $ajuda, $id) . '</div>';
    }

    /** Detalhe de ajuda recolhido atrás de um "?" (sem JavaScript: `<details>`); `$texto` já escapado. */
    private function ajudaDetalhada(string $texto): string
    {
        return ' <details class="pbl-ajuda"><summary aria-label="Mais ajuda">?</summary><span>' . $texto . '</span></details>';
    }

    /**
     * Campo de uma propriedade `arquivo`/`imagem`: `<input type="file">` e, na edição, o arquivo já guardado (se houver) com
     * uma caixa para removê-lo. Na criação (`$criando`) o arquivo enviado fica pendente até o objeto existir (ADR 0014 §4:
     * criação e upload são atômicos, dentro da mesma transação) — por isso não há "arquivo atual" nem "remover" para
     * mostrar, mas o campo de envio é o mesmo.
     *
     * @param array{nome: string, tipo: string, rotulo?: string, obrigatorio?: bool, tipos?: list<string>, tamanho_max_mb?: int} $p
     * @param array<string, string> $erros
     */
    private function campoDeArquivo(array $p, string $valor, array $erros, bool $criando): string
    {
        $chave = 'dados.' . $p['nome'];
        $id = 'campo-dados-' . $p['nome'];
        $rotulo = Classe::rotuloDe($p) . (($p['obrigatorio'] ?? false) ? ' (obrigatório)' : '');
        $extensoes = TiposDeArquivo::aceitasPor($p);
        $limiteMb = intdiv(TiposDeArquivo::limiteBytes($p), 1024 * 1024);
        $controle = '';
        if (!$criando) {
            $atual = $valor !== '' && ctype_digit($valor) ? $this->arquivoLegivel((int) $valor) : '<em>nenhum arquivo enviado</em>';
            $controle .= '<div class="mb-2">' . $atual . '</div>';
        }
        $controle .= '<input class="form-control' . $this->invalido($erros, $chave) . '" id="' . Pagina::h($id) . '" type="file" name="' . Pagina::h('dados[' . $p['nome'] . ']') . '" accept="' . Pagina::h(implode(',', array_map(static fn (string $e): string => '.' . $e, $extensoes))) . '"' . $this->descrito($erros, $chave, true) . '>';
        if (!$criando && $valor !== '') {
            $controle .= '<div class="form-check mt-1"><input class="form-check-input" type="checkbox" value="1" id="' . $id . '-remover" name="' . Pagina::h('dados_remover[' . $p['nome'] . ']') . '"><label class="form-check-label" for="' . $id . '-remover">Remover o arquivo atual</label></div>';
        }
        $ajuda = 'Tipos aceitos: ' . implode(', ', $extensoes) . '. Até ' . $limiteMb . ' MB.'
            . ($criando ? '' : ' Deixe em branco para manter o arquivo atual.');

        return '<div class="pbl-card pbl-campo-tipo">' . $this->campo($chave, $rotulo, $controle, $erros, $ajuda, $id) . '</div>';
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
            return Pagina::alerta('danger', $erros['_']);
        }

        return Pagina::alerta('danger', 'Confira ' . (count($erros) === 1 ? 'o campo marcado' : 'os ' . count($erros) . ' campos marcados') . ' abaixo.');
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
        $lido = $this->lerEstadoEDatas($campos);
        if ($versao === null || $versao < 1 || $lido === null) {
            return $this->pedidoInvalido($sessao);
        }
        [$estado, $inicio, $fim, $dataInicio, $dataFim, $erros] = $lido;
        if ($erros !== []) {
            return $this->telaDeEstado($sessao, $o, $estado, $inicio, $fim, $versao, $erros, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $mudancas = $this->mudancasDeEstado($o, $estado, $dataInicio, $dataFim);
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

    /**
     * Lê e valida estado e datas de vigência (tela "Publicar ou tirar do ar" e card "Publicação" da edição). `null` = pedido malformado.
     * Datas: texto do campo `datetime-local` em Brasília → ISO em UTC (`dataDoCampo`); a saída do ar precisa ser depois da publicação.
     *
     * @param array<string, mixed> $campos
     * @return array{0: string, 1: string, 2: string, 3: ?string, 4: ?string, 5: array<string, string>}|null estado, início e fim digitados, início e fim gravados, erros
     */
    private function lerEstadoEDatas(array $campos): ?array
    {
        $estado = $campos['estado'] ?? null;
        $inicio = $campos['data_publicacao'] ?? '';
        $fim = $campos['data_validade'] ?? '';
        if (!is_string($estado) || !is_string($inicio) || !is_string($fim)) {
            return null;
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

        return [$estado, $inicio, $fim, $dataInicio === false ? null : $dataInicio, $dataFim === false ? null : $dataFim, $erros];
    }

    /** @return array{status?: string, data_publicacao?: string|null, data_validade?: string|null} só o que mudou em relação ao objeto atual */
    private function mudancasDeEstado(Objeto $o, string $estado, ?string $dataInicio, ?string $dataFim): array
    {
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

        return $mudancas;
    }

    /** Aviso de vigência em palavras ("agendado para…", "sai do ar em…", "vencido em…"); '' quando não há o que dizer. */
    private function vigencia(Objeto $o, string $agora): string
    {
        if ($o->status !== 'publicado') {
            return '';
        }
        if ($o->dataPublicacao !== null && $o->dataPublicacao > $agora) {
            return 'agendado para ' . $this->dataLegivel($o->dataPublicacao);
        }
        if ($o->dataValidade !== null) {
            return ($o->dataValidade < $agora ? 'vencido em ' : 'expira em ') . $this->dataLegivel($o->dataValidade);
        }

        return '';
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
            . Pagina::acoes('Salvar', [['Cancelar', $this->link($o->id)]], null, 'btn-primary', 'pbl-acoes-fixas') . '</form>';

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
        $corpo = $this->migalhas($sessao, $o) . ($erro === null ? '' : Pagina::alerta('danger', $erro))
            . '<p>Hoje está dentro de <strong>' . ($pai === null ? '?' : Pagina::h($pai->titulo)) . '</strong>. '
            . 'Tudo o que está dentro dele vai junto. O endereço não muda.</p>';
        if ($aqui === null) {
            return Pagina::resposta('Mover: ' . $o->titulo, $corpo . Pagina::alerta('warning', 'Não há onde navegar a partir da sua permissão.'), $status, false, $this->barra($sessao));
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
        $corpo = $this->migalhas($sessao, $o) . ($erro === null ? '' : Pagina::alerta('danger', $erro));
        if ($filhos > 0) {
            $corpo .= Pagina::alerta('warning', 'Este conteúdo tem ' . $filhos . ' ' . ($filhos === 1 ? 'conteúdo' : 'conteúdos') . ' dentro dele. Mova ou mande para a lixeira o que está dentro antes.')
                . Pagina::acoes('', [['Voltar', $this->link($o->id)]]);
        } else {
            $corpo .= '<p>Mandar <strong>' . Pagina::h($o->titulo) . '</strong> para a lixeira? Ele sai do ar na hora e deixa de aparecer nas listas. '
                . 'Nada é apagado de vez: dá para tirá-lo da lixeira depois, com o histórico inteiro.</p>'
                . '<form method="post" action="' . $this->link($o->id) . '/apagar">' . $this->camposOcultos($sessao, $o->versao)
                . Pagina::acoes('Mandar para a lixeira', [['Cancelar', $this->link($o->id)]], null, 'btn-danger') . '</form>';
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
        $corpo .= Pagina::acoes('', [['Voltar', $this->link($o->id)]]);

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
        // Enquanto o envio espera revisão, quem não revisa não edita (retira o envio para mexer): o revisor aprova o que viu.
        if ($acao === Acao::Editar && $o->status === 'submetido' && !$this->servicos->permissoes->pode($sessao->usuario, Acao::Publicar, $o)) {
            return false;
        }

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
     * @return list<array{nome: string, tipo: string, indexavel: bool, classe?: string, rotulo?: string, obrigatorio?: bool, arquivada?: bool, tipos?: list<string>, tamanho_max_mb?: int}>
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
        return Pagina::resposta('Sem acesso', Pagina::alerta('warning', 'Você não tem acesso a este conteúdo, ou ele não existe.'), Response::HTTP_FORBIDDEN, false, $this->barra($sessao));
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
        return Pagina::resposta('Tipo indisponível', Pagina::alerta('danger', 'O tipo deste conteúdo não foi encontrado. Fale com um administrador.'), Response::HTTP_CONFLICT, false, $this->barra($sessao));
    }

    private function recusa(SessaoAtiva $sessao, string $titulo, string $mensagem, Objeto $o, int $status = Response::HTTP_CONFLICT): Response
    {
        return Pagina::resposta($titulo, $this->migalhas($sessao, $o) . Pagina::alerta('warning', $mensagem)
            . Pagina::acoes('', [['Voltar', $this->link($o->id)]]), $status, false, $this->barra($sessao));
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
        return Pagina::barra($sessao->usuario->nome, $this->csrf->daSessao($sessao->idHash), $sessao->usuario->administrador);
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
