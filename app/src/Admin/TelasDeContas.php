<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\Perfil;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Acesso\Usuario;
use Pbl\Acesso\UsuarioRepositorio;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contas da equipe (ADR 0012, fase 5d): listar, criar, editar nome e e-mail, marca de administrador, desativar e reativar,
 * redefinir senha e papéis por seção. Só o administrador global (`Permissoes::podeAdministrarContas`), conferido em toda rota,
 * também no POST; quem não pode e conta inexistente recebem a mesma resposta 403. Todo POST confere origem e CSRF antes de
 * qualquer outra coisa. Toda a lógica de conta fica no `UsuarioRepositorio` (o mesmo que a linha de comando usa).
 *
 * Regras: ninguém desativa nem rebaixa a si mesmo, a última conta de administrador ativa não é desativada nem rebaixada, e trocar
 * a senha (o repositório encerra as sessões da conta) de si mesmo leva de volta ao login. A auditoria nunca guarda senha, e-mail
 * nem nome: só ids, o perfil e os nomes dos campos.
 */
final class TelasDeContas
{
    use AuxiliaresDeTela;

    private const MAX_LOGIN = 64;
    private const MAX_NOME = 200;
    private const MAX_EMAIL = 255;

    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
    ) {
    }

    /**
     * Qual tela atende o caminho e o método, ou null (o `Painel` responde 404). O id é validado por expressão ancorada.
     *
     * @return array{0: string, 1: int|null}|null
     */
    public static function rota(string $prefixo, string $caminho, string $metodo): ?array
    {
        if ($caminho === $prefixo . '/contas') {
            return $metodo === 'GET' ? ['lista', null] : null;
        }
        if ($caminho === $prefixo . '/contas/nova') {
            return match ($metodo) {
                'GET' => ['nova', null],
                'POST' => ['criar', null],
                default => null,
            };
        }
        if (preg_match('#^' . preg_quote($prefixo, '#') . '/conta/([1-9][0-9]{0,9})(?:/(editar|senha|desativar|reativar|conceder|revogar))?$#D', $caminho, $m) !== 1) {
            return null;
        }
        $id = (int) $m[1];
        $acao = $m[2] ?? '';

        return match (true) {
            $acao === '' && $metodo === 'GET' => ['ver', $id],
            $acao !== '' && $metodo === 'POST' => [$acao, $id],
            default => null,
        };
    }

    /** @param array{0: string, 1: int|null} $rota */
    public function tratar(SessaoAtiva $sessao, array $rota, Request $requisicao): Response
    {
        [$tela, $id] = $rota;
        $post = $requisicao->getMethod() === 'POST';
        if ($post && !$this->postConfere($sessao, $requisicao)) {
            return $this->recusado();
        }
        $u = $sessao->usuario;
        if (!$this->servicos->permissoes->podeAdministrarContas($u)) {
            if ($post) {
                $this->servicos->auditoria->registrar('conta.negado', 'usuario:' . $u->id, $u->id, null, $id === null ? [] : ['conta_id' => $id]);
            }

            return Pagina::resposta('Sem acesso', '<div class="alert alert-warning" role="alert">Só administradores podem ver e alterar contas.</div>', Response::HTTP_FORBIDDEN, false, $this->barra($sessao));
        }
        if ($tela === 'lista') {
            return $this->lista($sessao, $requisicao);
        }
        if ($tela === 'nova') {
            return $this->formularioNovo($sessao, [], null);
        }
        if ($tela === 'criar') {
            return $this->criar($sessao, $requisicao);
        }
        $alvo = $id === null ? null : $this->servicos->usuarios->porId($id);
        if ($alvo === null) {
            return Pagina::resposta('Não encontrado', '<div class="alert alert-warning" role="alert">Esta conta não existe.</div><p><a href="' . $this->linkLista() . '">Voltar às contas</a></p>', Response::HTTP_NOT_FOUND, false, $this->barra($sessao));
        }

        return match ($tela) {
            'ver' => $this->pagina($sessao, $alvo, null, Response::HTTP_OK, [], $this->avisoDe($requisicao)),
            'editar' => $this->editar($sessao, $alvo, $requisicao),
            'senha' => $this->senha($sessao, $alvo, $requisicao),
            'desativar', 'reativar' => $this->situacao($sessao, $alvo, $tela === 'reativar', $requisicao),
            'conceder' => $this->conceder($sessao, $alvo, $requisicao),
            default => $this->revogar($sessao, $alvo, $requisicao),
        };
    }

    // ---------------------------------------------------------------- lista e criação

    private const SITUACOES_DA_LISTA = ['ativas' => true, 'desativadas' => false];

    private function lista(SessaoAtiva $sessao, Request $requisicao): Response
    {
        $q = $requisicao->query->all();
        $busca = is_string($q['q'] ?? null) ? mb_substr(trim($q['q']), 0, 200) : '';
        $situacao = is_string($q['situacao'] ?? null) ? $q['situacao'] : '';
        $ativo = self::SITUACOES_DA_LISTA[$situacao] ?? null;
        $pagina = max(1, $this->numero($q['pagina'] ?? null) ?? 1);
        $r = $this->servicos->usuarios->listar($busca, $ativo, $pagina, UsuarioRepositorio::CONTAS_POR_PAGINA);
        $totalPaginas = max(1, (int) ceil($r['total'] / UsuarioRepositorio::CONTAS_POR_PAGINA));
        $pagina = min($pagina, $totalPaginas);

        $corpo = $this->avisoDe($requisicao)
            . '<p>Contas da equipe desta instalação. Cada conta pode ser administradora (vê e faz tudo) ou receber um papel — leitor, autor ou editor — em cada seção.</p>'
            . '<p><a class="btn btn-primary" href="' . Painel::PREFIXO . '/contas/nova">Nova conta</a></p>'
            . $this->formularioDeBusca($busca, $situacao)
            . '<div class="table-responsive"><table class="table table-sm table-hover align-middle"><caption class="visually-hidden">Contas</caption><thead><tr>'
            . '<th scope="col">Login</th><th scope="col">Nome</th><th scope="col">E-mail</th><th scope="col">Tipo</th><th scope="col">Situação</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead><tbody>';
        foreach ($r['itens'] as $c) {
            $corpo .= '<tr><th scope="row"><code>' . Pagina::h($c->login) . '</code>' . ($c->id === $sessao->usuario->id ? ' <span class="badge text-bg-info">você</span>' : '') . '</th>'
                . '<td>' . Pagina::h($c->nome) . '</td><td>' . Pagina::h($c->email) . '</td>'
                . '<td>' . ($c->administrador ? 'Administrador' : 'Equipe') . '</td>'
                . '<td>' . ($c->ativo ? 'Ativa' : '<span class="badge text-bg-secondary">Desativada</span>') . '</td>'
                . '<td class="text-end"><a class="btn btn-sm btn-outline-primary" href="' . $this->link($c->id) . '">Abrir<span class="visually-hidden"> ' . Pagina::h($c->login) . '</span></a></td></tr>';
        }
        if ($r['itens'] === []) {
            $corpo .= '<tr><td colspan="6" class="text-body-secondary">Nenhuma conta encontrada.</td></tr>';
        }
        $corpo .= '</tbody></table></div>' . $this->paginacao($pagina, $totalPaginas, $busca, $situacao);

        return Pagina::resposta('Contas', $corpo, Response::HTTP_OK, false, $this->barra($sessao), [['Contas', null]]);
    }

    /** Busca e filtro (GET, sem CSRF: não altera nada) em cima da lista de contas. */
    private function formularioDeBusca(string $busca, string $situacao): string
    {
        $opcoes = '<option value=""' . ($situacao === '' ? ' selected' : '') . '>Todas</option>';
        foreach (array_keys(self::SITUACOES_DA_LISTA) as $chave) {
            $opcoes .= '<option value="' . $chave . '"' . ($situacao === $chave ? ' selected' : '') . '>' . ucfirst($chave) . '</option>';
        }

        return '<form method="get" action="' . $this->linkLista() . '" class="row g-2 align-items-end mb-3">'
            . '<div class="col-sm-6"><label for="campo-busca" class="form-label">Buscar por login, nome ou e-mail</label>'
            . '<input id="campo-busca" class="form-control" type="search" name="q" maxlength="200" value="' . Pagina::h($busca) . '"></div>'
            . '<div class="col-sm-3"><label for="campo-situacao" class="form-label">Situação</label><select id="campo-situacao" class="form-select" name="situacao">' . $opcoes . '</select></div>'
            . '<div class="col-sm-3"><button type="submit" class="btn btn-outline-primary">Filtrar</button></div></form>';
    }

    private function paginacao(int $pagina, int $totalPaginas, string $busca, string $situacao): string
    {
        if ($totalPaginas <= 1) {
            return '';
        }
        $link = static fn (int $p): string => Painel::PREFIXO . '/contas?pagina=' . $p
            . ($busca === '' ? '' : '&q=' . rawurlencode($busca)) . ($situacao === '' ? '' : '&situacao=' . rawurlencode($situacao));
        $html = '<nav aria-label="Páginas de contas"><ul class="pagination">'
            . '<li class="page-item' . ($pagina <= 1 ? ' disabled' : '') . '"><a class="page-link" href="' . Pagina::h($link(max(1, $pagina - 1))) . '">Anterior</a></li>'
            . '<li class="page-item disabled"><span class="page-link">Página ' . $pagina . ' de ' . $totalPaginas . '</span></li>'
            . '<li class="page-item' . ($pagina >= $totalPaginas ? ' disabled' : '') . '"><a class="page-link" href="' . Pagina::h($link(min($totalPaginas, $pagina + 1))) . '">Próxima</a></li></ul></nav>';

        return $html;
    }

    /** @param array<string, string> $v valores digitados (a senha nunca volta para o formulário) */
    private function formularioNovo(SessaoAtiva $sessao, array $v, ?string $erro, int $status = Response::HTTP_OK): Response
    {
        $valor = static fn (string $k): string => Pagina::h($v[$k] ?? '');
        $corpo = $this->erro($erro)
            . '<form method="post" action="' . Painel::PREFIXO . '/contas/nova"' . ($erro === null ? '' : ' aria-describedby="erro-formulario"') . '>' . $this->campoCsrf($sessao)
            . '<div class="mb-3"><label for="campo-login" class="form-label">Login</label><input id="campo-login" class="form-control" name="login" maxlength="' . self::MAX_LOGIN . '" required autocomplete="off" value="' . $valor('login') . '" aria-describedby="ajuda-login">'
            . '<div id="ajuda-login" class="form-text">3 a 64 letras minúsculas, números, ponto, hífen ou sublinhado. Não muda depois.</div></div>'
            . '<div class="mb-3"><label for="campo-nome" class="form-label">Nome</label><input id="campo-nome" class="form-control" name="nome" maxlength="' . self::MAX_NOME . '" required value="' . $valor('nome') . '"></div>'
            . '<div class="mb-3"><label for="campo-email" class="form-label">E-mail</label><input id="campo-email" class="form-control" name="email" type="email" maxlength="' . self::MAX_EMAIL . '" required value="' . $valor('email') . '"></div>'
            . $this->camposDeSenha('senha inicial')
            . '<div class="form-check mb-3"><input id="campo-admin" class="form-check-input" type="checkbox" name="administrador" value="1"' . (($v['administrador'] ?? '') === '1' ? ' checked' : '') . '>'
            . '<label for="campo-admin" class="form-check-label">Administrador (vê e altera tudo, em todos os sites)</label></div>'
            . $this->campoSenha($sessao, 'campo-senha-confirmar', 'senha_atual')
            . '<div class="pbl-acoes"><button type="submit" class="btn btn-primary">Criar conta</button><a class="btn btn-outline-secondary" href="' . $this->linkLista() . '">Cancelar</a></div></form>';

        return Pagina::resposta('Nova conta', $corpo, $status, false, $this->barra($sessao), [['Contas', $this->linkLista()], ['Nova conta', null]]);
    }

    private function criar(SessaoAtiva $sessao, Request $requisicao): Response
    {
        $login = $this->texto($requisicao, 'login');
        $nome = $this->texto($requisicao, 'nome');
        $email = $this->texto($requisicao, 'email');
        $senha = $this->texto($requisicao, 'senha');
        $repita = $this->texto($requisicao, 'senha2');
        if ($login === null || $nome === null || $email === null || $senha === null || $repita === null) {
            return $this->incompleto($sessao);
        }
        $admin = $this->texto($requisicao, 'administrador') === '1';
        $v = ['login' => $login, 'nome' => $nome, 'email' => $email, 'administrador' => $admin ? '1' : ''];
        $falha = $this->exigirReautenticacao($sessao, $requisicao, 'conta', false, 'senha_atual');
        if ($falha !== null) {
            return $this->aplicarFalhaDeReautenticacao($this->formularioNovo($sessao, $v, $falha['mensagem']), $falha);
        }
        $erro = $this->erroDeTexto($login, self::MAX_LOGIN, 'O login') ?? $this->erroDeTexto($nome, self::MAX_NOME, 'O nome') ?? $this->erroDeTexto($email, self::MAX_EMAIL, 'O e-mail')
            ?? ($senha !== $repita ? 'As duas senhas não são iguais.' : null);
        $u = $sessao->usuario;
        if ($erro === null) {
            try {
                $nova = $this->servicos->usuarios->criar($login, $email, $nome, $senha, $admin, 'usuario:' . $u->id);

                return new RedirectResponse($this->link($nova->id) . '?ok=criada', Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException $e) {
                $erro = $this->mensagemDoRepositorio($e);
            }
        }

        return $this->formularioNovo($sessao, $v, $erro, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    // ---------------------------------------------------------------- uma conta

    /**
     * @param array<string, string> $v valores digitados nos dados da conta (vazio = os da conta)
     */
    private function pagina(SessaoAtiva $sessao, Usuario $c, ?string $erro, int $status, array $v, string $aviso = ''): Response
    {
        $eu = $c->id === $sessao->usuario->id;
        $nome = Pagina::h($v['nome'] ?? $c->nome);
        $email = Pagina::h($v['email'] ?? $c->email);
        $admin = array_key_exists('administrador', $v) ? $v['administrador'] === '1' : $c->administrador;
        $base = $this->link($c->id);
        $csrf = $this->campoCsrf($sessao);
        $corpo = $aviso . $this->erro($erro)
            . '<p>Login: <code>' . Pagina::h($c->login) . '</code> · ' . ($c->ativo ? 'conta ativa' : '<span class="badge text-bg-secondary">desativada</span>') . ($eu ? ' · <strong>esta é a sua conta</strong>' : '') . '</p>'
            . '<section class="mb-4"><h2 class="h5">Dados</h2><form method="post" action="' . $base . '/editar">' . $csrf
            . '<div class="mb-3"><label for="campo-nome" class="form-label">Nome</label><input id="campo-nome" class="form-control" name="nome" maxlength="' . self::MAX_NOME . '" required value="' . $nome . '"></div>'
            . '<div class="mb-3"><label for="campo-email" class="form-label">E-mail</label><input id="campo-email" class="form-control" name="email" type="email" maxlength="' . self::MAX_EMAIL . '" required value="' . $email . '"></div>'
            . ($eu
                ? '<p class="form-text">Você é administrador e não pode tirar a própria marca de administrador (peça a outro administrador).</p>'
                : '<div class="form-check mb-3"><input id="campo-admin" class="form-check-input" type="checkbox" name="administrador" value="1"' . ($admin ? ' checked' : '') . '>'
                    . '<label for="campo-admin" class="form-check-label">Administrador (vê e altera tudo, em todos os sites)</label></div>')
            . $this->campoSenha($sessao, 'campo-senha-editar')
            . '<div class="pbl-acoes"><button type="submit" class="btn btn-primary">Salvar dados</button></div></form></section>'
            . '<section class="mb-4"><h2 class="h5">Redefinir senha</h2>' . ($eu ? '<p class="form-text">Ao trocar a sua senha você sai e precisa entrar de novo.</p>' : '<p class="form-text">A conta sai de todas as sessões abertas e entra com a senha nova.</p>')
            . '<form method="post" action="' . $base . '/senha">' . $csrf . $this->camposDeSenha('nova senha')
            . $this->campoSenha($sessao, 'campo-senha-atual-redefinir', 'senha_atual')
            . '<div class="pbl-acoes"><button type="submit" class="btn btn-warning">Trocar senha</button></div></form></section>'
            . '<section class="mb-4"><h2 class="h5">Situação</h2>' . $this->blocoDeSituacao($sessao, $csrf, $c, $eu, $base) . '</section>'
            . '<section class="mb-4"><h2 class="h5">Papéis por seção</h2>' . $this->blocoDeAcessos($sessao, $csrf, $c, $base) . '</section>'
            . '<p><a href="' . $this->linkLista() . '">Voltar às contas</a></p>';

        return Pagina::resposta('Conta: ' . $c->login, $corpo, $status, false, $this->barra($sessao), [['Contas', $this->linkLista()], [$c->login, null]]);
    }

    private function blocoDeSituacao(SessaoAtiva $sessao, string $csrf, Usuario $c, bool $eu, string $base): string
    {
        if (!$c->ativo) {
            return '<p>Esta conta está desativada: não consegue entrar.</p><form method="post" action="' . $base . '/reativar">' . $csrf
                . $this->campoSenha($sessao, 'campo-senha-reativar') . '<button type="submit" class="btn btn-outline-success">Reativar conta</button></form>';
        }
        if ($eu) {
            return '<p class="form-text">Você não pode desativar a própria conta.</p>';
        }

        return '<p>Desativar impede de entrar e derruba as sessões abertas; os papéis e o histórico ficam guardados.</p>'
            . '<form method="post" action="' . $base . '/desativar">' . $csrf . $this->campoSenha($sessao, 'campo-senha-desativar')
            . '<button type="submit" class="btn btn-outline-danger">Desativar conta</button></form>';
    }

    private function blocoDeAcessos(SessaoAtiva $sessao, string $csrf, Usuario $c, string $base): string
    {
        $corpo = $c->administrador ? '<p class="form-text">Administradores já podem tudo; os papéis abaixo só valem se a marca de administrador sair.</p>' : '';
        $acessos = $this->servicos->usuarios->acessosDe($c->id);
        if ($acessos === []) {
            $corpo .= '<p>Nenhum papel concedido.</p>';
        } else {
            $corpo .= '<div class="table-responsive"><table class="table table-sm align-middle"><caption class="visually-hidden">Papéis</caption><thead><tr><th scope="col">Seção</th><th scope="col">Site</th><th scope="col">Papel</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead><tbody>';
            foreach ($acessos as $a) {
                $o = $this->servicos->objetos->porId($a['objeto_id']);
                $site = $o === null ? null : $this->servicos->sites->porId($o->siteId);
                $corpo .= '<tr><td>' . ($o === null ? '<span class="text-body-secondary">(removida)</span>' : '<a href="' . Painel::PREFIXO . '/objeto/' . $o->id . '">' . Pagina::h($o->titulo) . '</a>') . ' <span class="text-body-secondary">#' . $a['objeto_id'] . '</span></td>'
                    . '<td>' . ($site === null ? '—' : Pagina::h($site->nome)) . '</td><td>' . Pagina::h($a['perfil']->value) . '</td>'
                    . '<td class="text-end"><form method="post" action="' . $base . '/revogar" class="d-flex gap-1 align-items-end justify-content-end">' . $csrf . '<input type="hidden" name="objeto" value="' . $a['objeto_id'] . '">'
                    . $this->campoSenha($sessao, 'campo-senha-revogar-' . $a['objeto_id'])
                    . '<button type="submit" class="btn btn-sm btn-outline-danger">Revogar<span class="visually-hidden"> o papel na seção ' . $a['objeto_id'] . '</span></button></form></td></tr>';
            }
            $corpo .= '</tbody></table></div>';
        }
        $opcoes = '';
        foreach (Perfil::cases() as $p) {
            $opcoes .= '<option value="' . $p->value . '">' . ucfirst($p->value) . '</option>';
        }

        return $corpo . '<form method="post" action="' . $base . '/conceder" class="row g-2 align-items-end">' . $csrf
            . '<div class="col-sm-4"><label for="campo-objeto" class="form-label">Número da seção</label><input id="campo-objeto" class="form-control" name="objeto" inputmode="numeric" maxlength="10" required aria-describedby="ajuda-objeto"></div>'
            . '<div class="col-sm-4"><label for="campo-perfil" class="form-label">Papel</label><select id="campo-perfil" class="form-select" name="perfil">' . $opcoes . '</select></div>'
            . '<div class="col-sm-4">' . $this->campoSenha($sessao, 'campo-senha-conceder') . '<button type="submit" class="btn btn-primary">Conceder</button></div>'
            . '<div id="ajuda-objeto" class="form-text">O número aparece na página da seção na administração. Vale para ela e tudo abaixo; substitui o papel que a conta já tinha nela. Leitor vê; autor cria e edita o que criou; editor também publica e apaga.</div></form>';
    }

    private function camposDeSenha(string $rotulo): string
    {
        return '<div class="mb-3"><label for="campo-senha" class="form-label">' . ucfirst($rotulo) . '</label><input id="campo-senha" class="form-control" name="senha" type="password" autocomplete="new-password" maxlength="1024" required aria-describedby="ajuda-senha">'
            . '<div id="ajuda-senha" class="form-text">No mínimo 10 caracteres, nada óbvio (como "senha123456"). Tamanho vale mais que símbolos.</div></div>'
            . '<div class="mb-3"><label for="campo-senha2" class="form-label">Repita a ' . $rotulo . '</label><input id="campo-senha2" class="form-control" name="senha2" type="password" autocomplete="new-password" maxlength="1024" required></div>';
    }

    private function editar(SessaoAtiva $sessao, Usuario $c, Request $requisicao): Response
    {
        $nome = $this->texto($requisicao, 'nome');
        $email = $this->texto($requisicao, 'email');
        if ($nome === null || $email === null) {
            return $this->incompleto($sessao);
        }
        $eu = $c->id === $sessao->usuario->id;
        $admin = $eu ? $c->administrador : $this->texto($requisicao, 'administrador') === '1';
        $v = ['nome' => $nome, 'email' => $email, 'administrador' => $admin ? '1' : ''];
        $falha = $this->exigirReautenticacao($sessao, $requisicao, 'conta');
        if ($falha !== null) {
            return $this->aplicarFalhaDeReautenticacao($this->pagina($sessao, $c, $falha['mensagem'], Response::HTTP_OK, $v), $falha);
        }
        $erro = $this->erroDeTexto($nome, self::MAX_NOME, 'O nome') ?? $this->erroDeTexto($email, self::MAX_EMAIL, 'O e-mail');
        if ($erro === null && $c->administrador && !$admin && $this->servicos->usuarios->ehUltimoAdministradorAtivo($c->id)) {
            $erro = 'Esta é a última conta de administrador ativa; ela não pode deixar de ser administradora.';
        }
        if ($erro === null) {
            try {
                $this->servicos->usuarios->atualizar($c->id, $nome, $email, 'usuario:' . $sessao->usuario->id);
                $this->servicos->usuarios->definirAdministrador($c->id, $admin, 'usuario:' . $sessao->usuario->id);
            } catch (\InvalidArgumentException $e) {
                $erro = $this->mensagemDoRepositorio($e);
            }
        }
        if ($erro !== null) {
            return $this->pagina($sessao, $c, $erro, Response::HTTP_UNPROCESSABLE_ENTITY, $v);
        }

        return new RedirectResponse($this->link($c->id) . '?ok=salva', Response::HTTP_SEE_OTHER);
    }

    private function senha(SessaoAtiva $sessao, Usuario $c, Request $requisicao): Response
    {
        $senha = $this->texto($requisicao, 'senha');
        $repita = $this->texto($requisicao, 'senha2');
        if ($senha === null || $repita === null) {
            return $this->incompleto($sessao);
        }
        $falha = $this->exigirReautenticacao($sessao, $requisicao, 'conta', false, 'senha_atual');
        if ($falha !== null) {
            return $this->aplicarFalhaDeReautenticacao($this->pagina($sessao, $c, $falha['mensagem'], Response::HTTP_OK, []), $falha);
        }
        $erro = $senha !== $repita ? 'As duas senhas não são iguais.' : null;
        if ($erro === null) {
            try {
                $this->servicos->usuarios->definirSenha($c->id, $senha, 'usuario:' . $sessao->usuario->id);
            } catch (\InvalidArgumentException $e) {
                $erro = $this->mensagemDoRepositorio($e);
            }
        }
        if ($erro !== null) {
            return $this->pagina($sessao, $c, $erro, Response::HTTP_UNPROCESSABLE_ENTITY, []);
        }

        // Trocar a senha encerra as sessões da conta (inclusive esta, se for a própria): sem sessão, o login é o próximo passo.
        return new RedirectResponse($c->id === $sessao->usuario->id ? Painel::PREFIXO . '/entrar' : $this->link($c->id) . '?ok=senha', Response::HTTP_SEE_OTHER);
    }

    private function situacao(SessaoAtiva $sessao, Usuario $c, bool $ativar, Request $requisicao): Response
    {
        $falha = $this->exigirReautenticacao($sessao, $requisicao, 'conta');
        if ($falha !== null) {
            return $this->aplicarFalhaDeReautenticacao($this->pagina($sessao, $c, $falha['mensagem'], Response::HTTP_OK, []), $falha);
        }
        if (!$ativar) {
            $erro = match (true) {
                $c->id === $sessao->usuario->id => 'Você não pode desativar a própria conta.',
                $this->servicos->usuarios->ehUltimoAdministradorAtivo($c->id) => 'Esta é a última conta de administrador ativa; ela não pode ser desativada.',
                default => null,
            };
            if ($erro !== null) {
                return $this->pagina($sessao, $c, $erro, Response::HTTP_UNPROCESSABLE_ENTITY, []);
            }
        }
        if ($c->ativo !== $ativar) {
            $this->servicos->usuarios->definirAtivo($c->id, $ativar, 'usuario:' . $sessao->usuario->id);
        }

        return new RedirectResponse($this->link($c->id) . '?ok=' . ($ativar ? 'reativada' : 'desativada'), Response::HTTP_SEE_OTHER);
    }

    private function conceder(SessaoAtiva $sessao, Usuario $c, Request $requisicao): Response
    {
        $objeto = $this->numero($this->texto($requisicao, 'objeto'));
        $perfil = Perfil::tryFrom($this->texto($requisicao, 'perfil') ?? '');
        if ($objeto === null) {
            return $this->pagina($sessao, $c, 'Informe o número da seção (só dígitos).', Response::HTTP_UNPROCESSABLE_ENTITY, []);
        }
        if ($perfil === null) {
            return $this->pagina($sessao, $c, 'Escolha um papel: leitor, autor ou editor.', Response::HTTP_UNPROCESSABLE_ENTITY, []);
        }
        $falha = $this->exigirReautenticacao($sessao, $requisicao, 'conta');
        if ($falha !== null) {
            return $this->aplicarFalhaDeReautenticacao($this->pagina($sessao, $c, $falha['mensagem'], Response::HTTP_OK, []), $falha);
        }
        try {
            $this->servicos->usuarios->conceder($c->id, $objeto, $perfil, 'usuario:' . $sessao->usuario->id);
        } catch (\InvalidArgumentException) {
            return $this->pagina($sessao, $c, 'Essa seção não existe.', Response::HTTP_UNPROCESSABLE_ENTITY, []);
        }

        return new RedirectResponse($this->link($c->id) . '?ok=concedido', Response::HTTP_SEE_OTHER);
    }

    private function revogar(SessaoAtiva $sessao, Usuario $c, Request $requisicao): Response
    {
        $objeto = $this->numero($this->texto($requisicao, 'objeto'));
        if ($objeto === null) {
            return $this->incompleto($sessao);
        }
        $falha = $this->exigirReautenticacao($sessao, $requisicao, 'conta');
        if ($falha !== null) {
            return $this->aplicarFalhaDeReautenticacao($this->pagina($sessao, $c, $falha['mensagem'], Response::HTTP_OK, []), $falha);
        }
        try {
            $this->servicos->usuarios->revogar($c->id, $objeto, 'usuario:' . $sessao->usuario->id);
        } catch (\InvalidArgumentException) {
            return $this->pagina($sessao, $c, 'Essa conta não tem papel nessa seção (talvez já tenha sido revogado).', Response::HTTP_UNPROCESSABLE_ENTITY, []);
        }

        return new RedirectResponse($this->link($c->id) . '?ok=revogado', Response::HTTP_SEE_OTHER);
    }

    // ---------------------------------------------------------------- utilidades

    private function avisoDe(Request $requisicao): string
    {
        $texto = match ($requisicao->query->all()['ok'] ?? null) {
            'criada' => 'Conta criada.',
            'salva' => 'Dados salvos.',
            'senha' => 'Senha trocada; a conta saiu das sessões abertas.',
            'desativada' => 'Conta desativada.',
            'reativada' => 'Conta reativada.',
            'concedido' => 'Papel concedido.',
            'revogado' => 'Papel revogado.',
            default => null,
        };

        return $texto === null ? '' : '<div class="alert alert-success" role="status">' . $texto . '</div>';
    }

    private function link(int $id): string
    {
        return Painel::PREFIXO . '/conta/' . $id;
    }

    private function linkLista(): string
    {
        return Painel::PREFIXO . '/contas';
    }
}
