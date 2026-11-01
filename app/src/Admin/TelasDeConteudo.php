<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Acao;
use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Conteudo\ConflitoDeVersao;
use Pbl\Conteudo\Objeto;
use Pbl\Consulta\Consulta;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telas de conteúdo da administração (ADR 0012): painel com os sites, um objeto com seus filhos (só leitura) e a edição de
 * título e descrição. Toda rota recebe a sessão já validada pelo `Painel` e decide com `Permissoes::pode`, com a ação fixa no
 * código; todo POST confere origem e CSRF antes de qualquer outra coisa; toda saída é escapada.
 */
final class TelasDeConteudo
{
    public const POR_PAGINA = 100;
    public const MAX_TITULO = 300;
    public const MAX_DESCRICAO = 2000;
    /** Motivo gravado na versão e na auditoria: fixo, nunca texto digitado. */
    public const MOTIVO = 'administracao';

    /** @var array<int, string> nomes de classe já lidos nesta requisição */
    private array $classes = [];

    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
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

    /** Um objeto, o caminho até ele e seus filhos (em qualquer estado), só leitura. */
    public function ver(SessaoAtiva $sessao, int $id, Request $requisicao): Response
    {
        $o = $this->servicos->objetos->porId($id);
        if ($o === null || !$this->servicos->permissoes->pode($sessao->usuario, Acao::Ver, $o)) {
            return $this->semAcesso($sessao);
        }
        $inicio = $this->numero($requisicao->query->all()['inicio'] ?? null) ?? 0;
        $consulta = new Consulta($o->siteId, $o->id, 1, null, [], 'peso, titulo', $inicio, self::POR_PAGINA, false);
        $filhos = $this->servicos->motor->buscar($consulta);
        $total = $this->servicos->motor->contar($consulta);
        $agora = gmdate('c');

        $aviso = match ($requisicao->query->all()['salvo'] ?? null) {
            'sim' => '<div class="alert alert-success" role="status">Alterações salvas.</div>',
            'nada' => '<div class="alert alert-secondary" role="status">Nada mudou.</div>',
            'aviso' => '<div class="alert alert-warning" role="status">Alterações salvas, mas a página não pôde ser republicada agora. Publique o site de novo (bin/pbl publicar).</div>',
            default => '',
        };

        $corpo = $this->migalhas($sessao, $o) . $aviso
            . '<table class="table table-sm"><caption class="visually-hidden">Dados deste conteúdo</caption><tbody>'
            . '<tr><th scope="row">Tipo</th><td>' . Pagina::h($this->nomeDaClasse($o->classeId)) . '</td></tr>'
            . '<tr><th scope="row">Estado</th><td>' . Pagina::h($o->status) . ($o->visivel($agora) ? ' (visível ao público)' : ' (fora do ar)') . '</td></tr>'
            . '<tr><th scope="row">Endereço</th><td><code>/' . Pagina::h($o->urlAmigavel) . '</code></td></tr>'
            . '<tr><th scope="row">Versão</th><td>' . $o->versao . '</td></tr>'
            . '<tr><th scope="row">Descrição</th><td>' . ($o->descricao === '' ? '<em>sem descrição</em>' : nl2br(Pagina::h($o->descricao))) . '</td></tr>'
            . '</tbody></table>';
        if ($this->servicos->permissoes->pode($sessao->usuario, Acao::Editar, $o)) {
            $corpo .= '<p><a class="btn btn-primary" href="' . $this->link($o->id) . '/editar">Editar título e descrição</a></p>';
        }

        $corpo .= '<h2 class="h5 mt-4">Conteúdo dentro desta página</h2>';
        if ($filhos === []) {
            $corpo .= '<p class="text-body-secondary">Nenhum.</p>';
        } else {
            $corpo .= '<div class="table-responsive"><table class="table table-sm table-hover align-middle"><thead><tr><th scope="col">Título</th><th scope="col">Tipo</th><th scope="col">Estado</th></tr></thead><tbody>';
            foreach ($filhos as $f) {
                $corpo .= '<tr><td><a href="' . $this->link($f->id) . '">' . Pagina::h($f->titulo) . '</a></td>'
                    . '<td>' . Pagina::h($this->nomeDaClasse($f->classeId)) . '</td>'
                    . '<td>' . Pagina::h($f->status) . ($f->visivel($agora) ? '' : ' (fora do ar)') . '</td></tr>';
            }
            $corpo .= '</tbody></table></div>';
            if ($total > self::POR_PAGINA) {
                $corpo .= '<p class="text-body-secondary">Mostrando ' . ($inicio + 1) . ' a ' . min($total, $inicio + count($filhos)) . ' de ' . $total . '.';
                if ($inicio > 0) {
                    $corpo .= ' <a href="' . $this->link($o->id) . '?inicio=' . max(0, $inicio - self::POR_PAGINA) . '">Anteriores</a>';
                }
                if ($inicio + self::POR_PAGINA < $total) {
                    $corpo .= ' <a href="' . $this->link($o->id) . '?inicio=' . ($inicio + self::POR_PAGINA) . '">Próximos</a>';
                }
                $corpo .= '</p>';
            }
        }

        return Pagina::resposta($o->titulo, $corpo, Response::HTTP_OK, false, $this->barra($sessao));
    }

    public function formulario(SessaoAtiva $sessao, int $id): Response
    {
        $o = $this->servicos->objetos->porId($id);
        if ($o === null || !$this->servicos->permissoes->pode($sessao->usuario, Acao::Editar, $o)) {
            return $this->semAcesso($sessao);
        }

        return $this->telaDeEdicao($sessao, $o, $o->titulo, $o->descricao, $o->versao);
    }

    public function salvar(SessaoAtiva $sessao, int $id, Request $requisicao): Response
    {
        $campos = $requisicao->request->all();
        $token = $campos['_csrf'] ?? null;
        if (!$this->rede->origemConfere($requisicao) || !is_string($token) || !$this->csrf->conferirSessao($sessao->idHash, $token)) {
            return Pagina::resposta('Pedido recusado', '<p>O pedido não pôde ser confirmado. Volte, recarregue a página e tente de novo.</p>', Response::HTTP_FORBIDDEN);
        }
        $u = $sessao->usuario;
        $o = $this->servicos->objetos->porId($id);
        if ($o === null || !$this->servicos->permissoes->pode($u, Acao::Editar, $o)) {
            if ($o !== null) {
                $this->servicos->auditoria->registrar('objeto.editar.negado', 'usuario:' . $u->id, $u->id, $o->id);
            }

            return $this->semAcesso($sessao);
        }
        $versao = $this->numero($campos['versao'] ?? null);
        $titulo = $campos['titulo'] ?? null;
        $descricao = $campos['descricao'] ?? null;
        if ($versao === null || $versao < 1 || !is_string($titulo) || !is_string($descricao)) {
            return Pagina::resposta('Pedido inválido', '<p>O formulário chegou incompleto. Volte e tente de novo.</p>', Response::HTTP_BAD_REQUEST);
        }
        $titulo = trim($titulo);
        $descricao = trim(str_replace(["\r\n", "\r"], "\n", $descricao));
        $erro = $this->erroDeValidacao($titulo, $descricao);
        if ($erro !== null) {
            return $this->telaDeEdicao($sessao, $o, $titulo, $descricao, $versao, $erro, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $mudancas = [];
        if ($titulo !== $o->titulo) {
            $mudancas['titulo'] = $titulo;
        }
        if ($descricao !== $o->descricao) {
            $mudancas['descricao'] = $descricao;
        }
        if ($mudancas === []) {
            return new RedirectResponse($this->link($o->id) . '?salvo=nada', Response::HTTP_SEE_OTHER);
        }
        try {
            $novo = $this->servicos->objetos->atualizar($o->id, $mudancas, self::MOTIVO, $versao, false, $u->id);
        } catch (ConflitoDeVersao) {
            $erro = 'Outra pessoa alterou este conteúdo depois que você abriu o formulário. O que está salvo agora aparece abaixo do formulário; '
                . 'o seu texto continua nos campos. Salve de novo só se quiser substituir a versão atual.';

            return $this->telaDeEdicao($sessao, $o, $titulo, $descricao, $o->versao, $erro, Response::HTTP_CONFLICT, true);
        }

        return new RedirectResponse($this->link($novo->id) . '?salvo=' . ($this->republicar($novo) ? 'sim' : 'aviso'), Response::HTTP_SEE_OTHER);
    }

    private function erroDeValidacao(string $titulo, string $descricao): ?string
    {
        if (!mb_check_encoding($titulo, 'UTF-8') || !mb_check_encoding($descricao, 'UTF-8')) {
            return 'O texto tem caracteres inválidos.';
        }
        if ($titulo === '') {
            return 'O título é obrigatório.';
        }
        if (mb_strlen($titulo) > self::MAX_TITULO) {
            return 'O título pode ter até ' . self::MAX_TITULO . ' caracteres.';
        }
        if (mb_strlen($descricao) > self::MAX_DESCRICAO) {
            return 'A descrição pode ter até ' . self::MAX_DESCRICAO . ' caracteres.';
        }
        // Bytes de controle (abaixo de 0x20 e 0x7F) nunca aparecem dentro de um caractere UTF-8 de vários bytes.
        if (preg_match('/[\x00-\x1F\x7F]/', $titulo) === 1 || preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $descricao) === 1) {
            return 'O texto tem caracteres de controle, que não são permitidos.';
        }

        return null;
    }

    /** Regrava a página do objeto e a do pai (listas de filhos mostram o título), se estiverem no ar. false = alguma falhou. */
    private function republicar(Objeto $o): bool
    {
        $site = $this->servicos->sites->porId($o->siteId);
        if ($site === null) {
            return false;
        }
        $agora = gmdate('c');
        $ok = true;
        foreach ([$o, $o->paiId === null ? null : $this->servicos->objetos->porId($o->paiId)] as $alvo) {
            if ($alvo === null || !$alvo->visivel($agora)) {
                continue;
            }
            try {
                $this->servicos->publicador->publicar($site, $alvo);
            } catch (\Throwable) {
                $ok = false; // a edição já está salva; a próxima publicação do site recupera a página
            }
        }

        return $ok;
    }

    private function telaDeEdicao(SessaoAtiva $sessao, Objeto $o, string $titulo, string $descricao, int $versao, ?string $erro = null, int $status = Response::HTTP_OK, bool $mostrarAtual = false): Response
    {
        $corpo = $this->migalhas($sessao, $o)
            . ($erro === null ? '' : '<div id="erro-edicao" class="alert alert-danger" role="alert">' . Pagina::h($erro) . '</div>')
            . '<form method="post" action="' . $this->link($o->id) . '/editar"' . ($erro === null ? '' : ' aria-describedby="erro-edicao"') . '>'
            . '<input type="hidden" name="_csrf" value="' . Pagina::h($this->csrf->daSessao($sessao->idHash)) . '">'
            . '<input type="hidden" name="versao" value="' . $versao . '">'
            . '<div class="mb-3"><label for="campo-titulo" class="form-label">Título</label>'
            . '<input id="campo-titulo" class="form-control" name="titulo" maxlength="' . self::MAX_TITULO . '" required value="' . Pagina::h($titulo) . '"></div>'
            . '<div class="mb-3"><label for="campo-descricao" class="form-label">Descrição</label>'
            . '<textarea id="campo-descricao" class="form-control" name="descricao" rows="5" maxlength="' . self::MAX_DESCRICAO . '" aria-describedby="ajuda-descricao">' . Pagina::h($descricao) . '</textarea>'
            . '<div id="ajuda-descricao" class="form-text">Opcional, até ' . self::MAX_DESCRICAO . ' caracteres.</div></div>'
            . '<button type="submit" class="btn btn-primary">Salvar</button> <a class="btn btn-outline-secondary" href="' . $this->link($o->id) . '">Cancelar</a></form>';
        if ($mostrarAtual) {
            $corpo .= '<h2 class="h5 mt-4">Salvo agora (versão ' . $o->versao . ')</h2><table class="table table-sm"><tbody>'
                . '<tr><th scope="row">Título</th><td>' . Pagina::h($o->titulo) . '</td></tr>'
                . '<tr><th scope="row">Descrição</th><td>' . nl2br(Pagina::h($o->descricao)) . '</td></tr></tbody></table>';
        }

        return Pagina::resposta('Editar: ' . $o->titulo, $corpo, $status, false, $this->barra($sessao));
    }

    /** Mesma resposta para "não existe" e "sem permissão": não revela quais ids existem. */
    private function semAcesso(SessaoAtiva $sessao): Response
    {
        return Pagina::resposta('Sem acesso', '<div class="alert alert-warning" role="alert">Você não tem acesso a este conteúdo, ou ele não existe.</div>', Response::HTTP_FORBIDDEN, false, $this->barra($sessao));
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
            $liberado = $liberado || $this->servicos->permissoes->pode($sessao->usuario, Acao::Ver, $a);
            if ($liberado) {
                $partes[] = '<li class="breadcrumb-item"><a href="' . $this->link($a->id) . '">' . Pagina::h($a->titulo) . '</a></li>';
            }
        }
        if ($partes === []) {
            return '';
        }

        return '<nav aria-label="Caminho"><ol class="breadcrumb">' . implode('', $partes)
            . '<li class="breadcrumb-item active" aria-current="page">' . Pagina::h($o->titulo) . '</li></ol></nav>';
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
