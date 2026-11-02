<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Acao;
use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Conteudo\RedirecionamentoRepositorio;
use Pbl\Conteudo\Site;
use Pbl\Publicacao\GerenciadorDePeles;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telas por site (ADR 0012, fase 5e): redirecionamentos manuais e peles (moldes). Tudo exige administrar o site
 * (`Acao::Administrar` na seção raiz, que só o administrador global tem), conferido em toda rota, também no POST. Todo POST
 * confere origem e CSRF antes de qualquer outra coisa. Os redirecionamentos usam as validações do `RedirecionamentoRepositorio`;
 * os moldes passam pelo parser da linguagem e pelo `GerenciadorDePeles`, a única porta de escrita em disco. A auditoria guarda
 * só ids e nomes validados (site, regra, pele, molde), nunca o conteúdo.
 *
 * @phpstan-type Regra array{id: int, origem: string, destino: string, codigo: int, prefixo: bool}
 */
final class TelasDeSite
{
    private readonly GerenciadorDePeles $peles;

    private const AVISOS = [
        'encadeado' => 'O destino também é redirecionado por outra regra; o visitante passa por mais de um salto.',
        'sobrepoe' => 'Já existe um conteúdo com este endereço: a página publicada vem antes e esta regra não será usada enquanto ele existir.',
    ];

    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
    ) {
        $this->peles = $servicos->peles;
    }

    /**
     * Qual tela atende o caminho e o método, ou null (o `Painel` responde 404). Todos os trechos são validados por expressão
     * ancorada; o que vai para o disco ainda passa pelo `GerenciadorDePeles`.
     *
     * @return array{0: string, 1: int, 2: string, 3: int}|null tela, site, pele e regra
     */
    public static function rota(string $prefixo, string $caminho, string $metodo): ?array
    {
        $p = '#^' . preg_quote($prefixo, '#') . '/site/([1-9][0-9]{0,9})/(redirecionamentos|peles)(?:/([a-z0-9_-]{1,50}|[1-9][0-9]{0,9}))?(?:/([a-z]{1,20}))?$#D';
        if (preg_match($p, $caminho, $m) !== 1) {
            return null;
        }
        $site = (int) $m[1];
        $a = $m[3] ?? '';
        $b = $m[4] ?? '';
        $tela = $m[2] === 'redirecionamentos'
            ? match (true) {
                $a === '' && $b === '' => match ($metodo) { 'GET' => 'redirLista', 'POST' => 'redirCriar', default => null },
                ctype_digit($a) && $b === 'editar' => match ($metodo) { 'GET' => 'redirEditar', 'POST' => 'redirSalvar', default => null },
                ctype_digit($a) && $b === 'remover' => $metodo === 'POST' ? 'redirRemover' : null,
                default => null,
            }
            : match (true) {
                $a === '' && $b === '' => $metodo === 'GET' ? 'peleLista' : null,
                $a === 'ativar' && $b === '' => $metodo === 'POST' ? 'peleAtivar' : null,
                $a !== '' && $b === '' => $metodo === 'GET' ? 'peleArquivos' : null,
                $b === 'molde' => match ($metodo) { 'GET' => 'moldeEditar', 'POST' => 'moldeSalvar', default => null },
                $b === 'novo' => match ($metodo) { 'GET' => 'moldeNovo', 'POST' => 'moldeCriar', default => null },
                $b === 'apagar' => match ($metodo) { 'GET' => 'moldeApagar', 'POST' => 'moldeConfirmarApagar', default => null },
                default => null,
            };
        if ($tela === null) {
            return null;
        }
        $ehRegra = str_starts_with($tela, 'redir') && ctype_digit($a);

        return [$tela, $site, $ehRegra ? '' : $a, $ehRegra ? (int) $a : 0];
    }

    /** @param array{0: string, 1: int, 2: string, 3: int} $rota */
    public function tratar(SessaoAtiva $sessao, array $rota, Request $requisicao): Response
    {
        [$tela, $siteId, $pele, $regraId] = $rota;
        $post = $requisicao->getMethod() === 'POST';
        if ($post && !$this->postConfere($sessao, $requisicao)) {
            return Pagina::resposta('Pedido recusado', '<p>O pedido não pôde ser confirmado. Volte, recarregue a página e tente de novo.</p>', Response::HTTP_FORBIDDEN);
        }
        $u = $sessao->usuario;
        $site = $this->servicos->sites->porId($siteId);
        if (!$u->ativo || ($site === null && !$u->administrador) || ($site !== null && !$this->podeAdministrar($sessao, $site))) {
            if ($post) {
                $this->servicos->auditoria->registrar('site.negado', 'usuario:' . $u->id, $u->id, null, ['site_id' => $siteId]);
            }

            return Pagina::resposta('Sem acesso', '<div class="alert alert-warning" role="alert">Você não tem permissão para administrar este site.</div>'
                . '<p><a href="' . Painel::PREFIXO . '/">Voltar ao início</a></p>', Response::HTTP_FORBIDDEN, false, $this->barra($sessao));
        }
        if ($site === null) {
            return $this->naoEncontrado($sessao, 'Este site não existe.');
        }
        if (str_starts_with($tela, 'redir')) {
            return $this->redirecionamentos($sessao, $site, $tela, $regraId, $requisicao);
        }

        return $this->peleTelas($sessao, $site, $tela, $pele, $requisicao);
    }

    private function podeAdministrar(SessaoAtiva $sessao, Site $site): bool
    {
        $u = $sessao->usuario;
        $raiz = $site->objetoRaizId === null ? null : $this->servicos->objetos->porId($site->objetoRaizId);

        return $raiz === null ? $u->administrador : $this->servicos->permissoes->pode($u, Acao::Administrar, $raiz);
    }

    // ---------------------------------------------------------------- redirecionamentos

    private function redirecionamentos(SessaoAtiva $sessao, Site $site, string $tela, int $regraId, Request $req): Response
    {
        $repo = $this->servicos->redirecionamentos;
        $regra = $regraId > 0 ? $repo->porId($site->id, $regraId) : null;
        if ($regraId > 0 && $regra === null) {
            return $this->naoEncontrado($sessao, 'Esta regra não existe (talvez tenha sido removida).', $this->linkRedir($site));
        }

        return match ($tela) {
            'redirLista' => $this->listaDeRegras($sessao, $site, $this->avisoDeRegra($req)),
            'redirCriar', 'redirSalvar' => $this->gravarRegra($sessao, $site, $regra, $req),
            'redirEditar' => $this->formularioDeRegra($sessao, $site, $regra, $regra === null ? [] : $this->camposDe($regra), null),
            default => $this->removerRegra($sessao, $site, $regra, $req),
        };
    }

    private function listaDeRegras(SessaoAtiva $sessao, Site $site, string $aviso): Response
    {
        $regras = $this->servicos->redirecionamentos->doSite($site->id);
        $corpo = $aviso . '<p>Regras para endereços antigos que não correspondem a nenhum conteúdo atual. Valem <strong>depois</strong> da página publicada e do histórico de endereços.</p>';
        $corpo .= '<div class="table-responsive"><table class="table table-sm table-hover align-middle"><caption class="visually-hidden">Redirecionamentos de ' . Pagina::h($site->nome) . '</caption><thead><tr>'
            . '<th scope="col">Endereço antigo</th><th scope="col">Vai para</th><th scope="col">Código</th><th scope="col">Alcance</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead><tbody>';
        foreach ($regras as $r) {
            $corpo .= '<tr><td><code>' . Pagina::h($r['origem']) . '</code></td><td><code>' . Pagina::h($r['destino']) . '</code></td><td>' . $r['codigo'] . '</td>'
                . '<td>' . ($r['prefixo'] ? 'Tudo abaixo do endereço' : 'Só o endereço') . '</td><td class="text-end">'
                . '<a class="btn btn-sm btn-outline-secondary" href="' . $this->linkRedir($site) . '/' . $r['id'] . '/editar">Editar</a> '
                . '<form class="d-inline" method="post" action="' . $this->linkRedir($site) . '/' . $r['id'] . '/remover">' . $this->campoCsrf($sessao)
                . '<button type="submit" class="btn btn-sm btn-outline-danger">Remover</button></form></td></tr>';
        }
        $corpo .= $regras === [] ? '<tr><td colspan="5" class="text-body-secondary">Nenhuma regra ainda.</td></tr>' : '';
        $corpo .= '</tbody></table></div><h2 class="h5 mt-4">Nova regra</h2>' . $this->formRegra($sessao, $site, null, ['codigo' => '301'], null);

        return Pagina::resposta('Redirecionamentos de ' . $site->nome, $corpo, Response::HTTP_OK, false, $this->barra($sessao));
    }

    /**
     * @param Regra|null $regra
     * @param array<string, string> $campos
     */
    private function formularioDeRegra(SessaoAtiva $sessao, Site $site, ?array $regra, array $campos, ?string $erro, int $status = Response::HTTP_OK): Response
    {
        return Pagina::resposta('Editar redirecionamento', $this->formRegra($sessao, $site, $regra, $campos, $erro)
            . '<p class="mt-3"><a href="' . $this->linkRedir($site) . '">Voltar aos redirecionamentos</a></p>', $status, false, $this->barra($sessao));
    }

    /**
     * @param Regra|null $regra
     * @param array<string, string> $c
     */
    private function formRegra(SessaoAtiva $sessao, Site $site, ?array $regra, array $c, ?string $erro): string
    {
        $acao = $this->linkRedir($site) . ($regra === null ? '' : '/' . $regra['id'] . '/editar');
        $codigo = $c['codigo'] ?? '301';

        return ($erro === null ? '' : '<div class="alert alert-danger" role="alert">' . Pagina::h($erro) . '</div>')
            . '<form method="post" action="' . $acao . '">' . $this->campoCsrf($sessao)
            . '<div class="mb-3"><label for="r-origem" class="form-label">Endereço antigo (caminho, como <code>/antigo/pagina</code>)</label>'
            . '<input id="r-origem" class="form-control" name="origem" maxlength="300" required value="' . Pagina::h($c['origem'] ?? '') . '"></div>'
            . '<div class="mb-3"><label for="r-destino" class="form-label">Destino (caminho do site, como <code>/novo</code>, ou endereço <code>https://</code>)</label>'
            . '<input id="r-destino" class="form-control" name="destino" maxlength="500" required value="' . Pagina::h($c['destino'] ?? '') . '"></div>'
            . '<div class="mb-3"><label for="r-codigo" class="form-label">Tipo</label><select id="r-codigo" class="form-select" name="codigo">'
            . '<option value="301"' . ($codigo === '301' ? ' selected' : '') . '>301 — permanente</option>'
            . '<option value="302"' . ($codigo === '302' ? ' selected' : '') . '>302 — temporário</option></select></div>'
            . '<div class="form-check mb-3"><input id="r-prefixo" class="form-check-input" type="checkbox" name="prefixo" value="1"' . (isset($c['prefixo']) ? ' checked' : '') . '>'
            . '<label for="r-prefixo" class="form-check-label">Vale também para tudo abaixo do endereço (<code>/antigo/x</code> vai para <code>&lt;destino&gt;/x</code>)</label></div>'
            . '<button type="submit" class="btn btn-primary">' . ($regra === null ? 'Criar regra' : 'Salvar') . '</button></form>';
    }

    /** @param Regra|null $regra regra em edição, ou null para criar */
    private function gravarRegra(SessaoAtiva $sessao, Site $site, ?array $regra, Request $req): Response
    {
        $c = $this->textos($req, ['origem', 'destino', 'codigo']) ?? [];
        if ($c === []) {
            return $this->falhaDeRegra($sessao, $site, $regra, [], 'Os campos precisam ser texto.');
        }
        $c['origem'] = trim($c['origem']);
        $c['destino'] = trim($c['destino']);
        $prefixo = ($req->request->all()['prefixo'] ?? null) === '1';
        if ($prefixo) {
            $c['prefixo'] = '1';
        }
        if ($c['origem'] === '' || preg_match('/[\x00-\x1F\x7F\s]/', $c['origem'] . $c['destino']) === 1 || !in_array($c['codigo'], ['301', '302'], true)) {
            return $this->falhaDeRegra($sessao, $site, $regra, $c, 'Preencha o endereço antigo e o destino, sem espaços, e escolha 301 ou 302.');
        }
        $normal = RedirecionamentoRepositorio::normalizar(rawurldecode(RedirecionamentoRepositorio::normalizar($c['origem'])));
        $avisos = [];
        try {
            $outras = array_values(array_filter($this->servicos->redirecionamentos->doSite($site->id), static fn (array $r): bool => $regra === null || $r['id'] !== $regra['id']));
            $situacao = $this->analisarCadeia($outras, $normal, $c['destino'], $prefixo);
            if ($situacao === 'laco') {
                return $this->falhaDeRegra($sessao, $site, $regra, $c, 'Esta regra criaria um laço: seguindo os redirecionamentos o visitante voltaria ao ponto de partida.');
            }
            if ($situacao === 'encadeado') {
                $avisos[] = 'encadeado';
            }
            if (!$prefixo && $this->servicos->objetos->porUrl($site->id, ltrim($normal, '/')) !== null) {
                $avisos[] = 'sobrepoe';
            }
            if ($regra === null) {
                $id = $this->servicos->redirecionamentos->adicionar($site->id, $c['origem'], $c['destino'], (int) $c['codigo'], $prefixo);
                $this->servicos->auditoria->registrar('redirecionamento.criar', 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, null, ['site_id' => $site->id, 'regra_id' => $id]);
            } else {
                $this->servicos->redirecionamentos->atualizar($site->id, $regra['id'], $c['origem'], $c['destino'], (int) $c['codigo'], $prefixo);
                $this->servicos->auditoria->registrar('redirecionamento.atualizar', 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, null, ['site_id' => $site->id, 'regra_id' => $regra['id']]);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->falhaDeRegra($sessao, $site, $regra, $c, $e->getMessage());
        }

        return new RedirectResponse($this->linkRedir($site) . '?ok=' . ($regra === null ? 'criada' : 'salva') . ($avisos === [] ? '' : '&av=' . implode(',', $avisos)), Response::HTTP_SEE_OTHER);
    }

    /**
     * @param Regra|null $regra
     * @param array<string, string> $c
     */
    private function falhaDeRegra(SessaoAtiva $sessao, Site $site, ?array $regra, array $c, string $erro): Response
    {
        if ($regra === null) {
            return Pagina::resposta('Redirecionamentos de ' . $site->nome, $this->formRegra($sessao, $site, null, $c, $erro)
                . '<p class="mt-3"><a href="' . $this->linkRedir($site) . '">Voltar aos redirecionamentos</a></p>', Response::HTTP_UNPROCESSABLE_ENTITY, false, $this->barra($sessao));
        }

        return $this->formularioDeRegra($sessao, $site, $regra, $c, $erro, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Segue as regras (as outras do site mais a nova) a partir do endereço antigo: `laco` se voltar a um caminho já visto,
     * `encadeado` se passar por mais de um salto, `ok` caso contrário. Destino `https://` encerra a cadeia.
     *
     * @param list<Regra> $outras
     */
    private function analisarCadeia(array $outras, string $origem, string $destino, bool $prefixo): string
    {
        $regras = $outras;
        $regras[] = ['id' => 0, 'origem' => $origem, 'destino' => $destino, 'codigo' => 301, 'prefixo' => $prefixo];
        $visto = [$origem => true];
        $atual = $origem;
        for ($saltos = 0; $saltos < 10; $saltos++) {
            $proximo = $this->proximoSalto($regras, $atual);
            if ($proximo === null || !str_starts_with($proximo, '/')) {
                return $saltos > 1 ? 'encadeado' : 'ok';
            }
            $proximo = RedirecionamentoRepositorio::normalizar(explode('#', explode('?', $proximo)[0])[0]);
            if (isset($visto[$proximo])) {
                return 'laco';
            }
            $visto[$proximo] = true;
            $atual = $proximo;
        }

        return 'laco';
    }

    /** @param list<Regra> $regras */
    private function proximoSalto(array $regras, string $caminho): ?string
    {
        $melhor = null;
        foreach ($regras as $r) {
            if (!$r['prefixo'] && $r['origem'] === $caminho) {
                return $r['destino'];
            }
            $dentro = $r['prefixo'] && ($r['origem'] === '/' || $caminho === $r['origem'] || str_starts_with($caminho, $r['origem'] . '/'));
            if ($dentro && ($melhor === null || strlen($r['origem']) > strlen($melhor['origem']))) {
                $melhor = $r;
            }
        }
        if ($melhor === null) {
            return null;
        }
        $resto = $melhor['origem'] === '/' ? $caminho : substr($caminho, strlen($melhor['origem']));

        return rtrim($melhor['destino'], '/') . ($resto === '/' ? '' : $resto);
    }

    /** @param Regra|null $regra */
    private function removerRegra(SessaoAtiva $sessao, Site $site, ?array $regra, Request $req): Response
    {
        if ($regra === null) {
            return $this->naoEncontrado($sessao, 'Esta regra não existe (talvez tenha sido removida).', $this->linkRedir($site));
        }
        $this->servicos->redirecionamentos->remover($site->id, $regra['id']);
        $this->servicos->auditoria->registrar('redirecionamento.remover', 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, null, ['site_id' => $site->id, 'regra_id' => $regra['id']]);

        return new RedirectResponse($this->linkRedir($site) . '?ok=removida', Response::HTTP_SEE_OTHER);
    }

    /**
     * @param Regra $r
     * @return array<string, string>
     */
    private function camposDe(array $r): array
    {
        return ['origem' => $r['origem'], 'destino' => $r['destino'], 'codigo' => (string) $r['codigo']] + ($r['prefixo'] ? ['prefixo' => '1'] : []);
    }

    private function avisoDeRegra(Request $req): string
    {
        $q = $req->query->all();
        $ok = match ($q['ok'] ?? null) {
            'criada' => 'Regra criada.', 'salva' => 'Regra salva.', 'removida' => 'Regra removida.', default => '',
        };
        $html = $ok === '' ? '' : '<div class="alert alert-success" role="status">' . $ok . '</div>';
        $av = is_string($q['av'] ?? null) ? explode(',', $q['av']) : [];
        foreach (array_keys(self::AVISOS) as $chave) {
            if (in_array($chave, $av, true)) {
                $html .= '<div class="alert alert-warning" role="status">' . self::AVISOS[$chave] . '</div>';
            }
        }

        return $html;
    }

    // ---------------------------------------------------------------- peles

    private function peleTelas(SessaoAtiva $sessao, Site $site, string $tela, string $pele, Request $req): Response
    {
        if ($tela === 'peleLista') {
            return $this->listaDePeles($sessao, $site, $this->avisoDePele($req));
        }
        if ($tela === 'peleAtivar') {
            return $this->ativarPele($sessao, $site, $req);
        }
        if (!$this->peles->peleExiste($pele)) {
            return $this->naoEncontrado($sessao, 'Esta pele não existe.', $this->linkPeles($site));
        }

        return match ($tela) {
            'peleArquivos' => $this->arquivosDaPele($sessao, $site, $pele, $this->avisoDePele($req)),
            'moldeNovo' => $this->formularioDeMolde($sessao, $site, $pele, '', '', null, null, true),
            'moldeCriar' => $this->criarMolde($sessao, $site, $pele, $req),
            'moldeEditar' => $this->abrirMolde($sessao, $site, $pele, $req),
            'moldeSalvar' => $this->salvarMolde($sessao, $site, $pele, $req),
            'moldeApagar' => $this->telaDeApagarMolde($sessao, $site, $pele, $req),
            default => $this->apagarMolde($sessao, $site, $pele, $req),
        };
    }

    private function listaDePeles(SessaoAtiva $sessao, Site $site, string $aviso): Response
    {
        $corpo = $aviso . '<p>Peles são pastas em <code>dados/peles/</code> com os moldes da linguagem do PBL4. Uma pele pode ser usada por mais de um site: editar um molde muda todos os sites que usam essa pele.</p>'
            . '<div class="table-responsive"><table class="table table-sm table-hover align-middle"><caption class="visually-hidden">Peles</caption><thead><tr>'
            . '<th scope="col">Pele</th><th scope="col">Moldes</th><th scope="col">Situação</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead><tbody>';
        foreach ($this->peles->peles() as $p) {
            $ativa = $p === $site->pele;
            $corpo .= '<tr><td><code>' . Pagina::h($p) . '</code></td><td>' . count($this->peles->moldes($p)) . '</td><td>'
                . ($ativa ? '<span class="badge text-bg-success">Ativa neste site</span>' : '<span class="text-body-secondary">—</span>') . '</td><td class="text-end">'
                . '<a class="btn btn-sm btn-outline-secondary" href="' . $this->linkPeles($site) . '/' . $p . '">Ver arquivos</a> '
                . ($ativa ? '' : '<form class="d-inline" method="post" action="' . $this->linkPeles($site) . '/ativar">' . $this->campoCsrf($sessao)
                    . '<input type="hidden" name="pele" value="' . Pagina::h($p) . '"><button type="submit" class="btn btn-sm btn-outline-primary">Usar neste site</button></form>')
                . '</td></tr>';
        }
        $corpo .= '</tbody></table></div>';

        return Pagina::resposta('Peles de ' . $site->nome, $corpo, Response::HTTP_OK, false, $this->barra($sessao));
    }

    private function ativarPele(SessaoAtiva $sessao, Site $site, Request $req): Response
    {
        $c = $this->textos($req, ['pele']);
        $nova = $c['pele'] ?? '';
        if (!$this->peles->peleExiste($nova) || !in_array('padrao', $this->peles->moldes($nova), true)) {
            return $this->listaDePeles($sessao, $site, '<div class="alert alert-danger" role="alert">Pele inexistente, ou sem o molde <code>padrao</code>, que todo site precisa.</div>')
                ->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->servicos->sites->definirPele($site->id, $nova);
        $this->servicos->auditoria->registrar('pele.ativar', 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, null, ['site_id' => $site->id, 'pele' => $nova]);
        $falha = $this->republicar($site->id);

        return new RedirectResponse($this->linkPeles($site) . '?ok=ativada' . ($falha ? '&rep=falhou' : ''), Response::HTTP_SEE_OTHER);
    }

    private function arquivosDaPele(SessaoAtiva $sessao, Site $site, string $pele, string $aviso): Response
    {
        $base = $this->linkPeles($site) . '/' . $pele;
        $corpo = $aviso . '<p><a class="btn btn-primary" href="' . $base . '/novo">Novo molde</a></p>'
            . '<div class="table-responsive"><table class="table table-sm table-hover align-middle"><caption class="visually-hidden">Moldes da pele ' . Pagina::h($pele) . '</caption><thead><tr>'
            . '<th scope="col">Molde</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead><tbody>';
        foreach ($this->peles->moldes($pele) as $m) {
            $corpo .= '<tr><td><code>' . Pagina::h($m) . '.pbl</code></td><td class="text-end">'
                . '<a class="btn btn-sm btn-outline-secondary" href="' . $base . '/molde?nome=' . rawurlencode($m) . '">Editar</a> '
                . '<a class="btn btn-sm btn-outline-danger" href="' . $base . '/apagar?nome=' . rawurlencode($m) . '">Apagar</a></td></tr>';
        }
        $corpo .= '</tbody></table></div><p><a href="' . $this->linkPeles($site) . '">Voltar às peles</a></p>';

        return Pagina::resposta('Pele ' . $pele, $corpo, Response::HTTP_OK, false, $this->barra($sessao));
    }

    private function abrirMolde(SessaoAtiva $sessao, Site $site, string $pele, Request $req): Response
    {
        $nome = $req->query->all()['nome'] ?? null;
        $fonte = is_string($nome) ? $this->peles->ler($pele, $nome) : null;
        if (!is_string($nome) || $fonte === null) {
            return $this->naoEncontrado($sessao, 'Este molde não existe.', $this->linkPeles($site) . '/' . $pele);
        }

        return $this->formularioDeMolde($sessao, $site, $pele, $nome, $fonte, null, hash('sha256', $fonte), false);
    }

    private function salvarMolde(SessaoAtiva $sessao, Site $site, string $pele, Request $req): Response
    {
        $c = $this->textos($req, ['nome', 'conteudo', 'base']);
        $nome = $c['nome'] ?? '';
        $atual = $this->peles->ler($pele, $nome);
        if ($c === null || $atual === null) {
            return $this->naoEncontrado($sessao, 'Este molde não existe.', $this->linkPeles($site) . '/' . $pele);
        }
        $conteudo = $this->normalizar($c['conteudo']);
        if (!hash_equals(hash('sha256', $atual), $c['base'])) {
            return $this->formularioDeMolde($sessao, $site, $pele, $nome, $conteudo, ['mensagem' => 'Outra pessoa alterou este molde enquanto você editava. Nada foi salvo; o texto abaixo é o seu. Copie o que precisar e abra o molde de novo.', 'linha' => null], hash('sha256', $atual), false, Response::HTTP_CONFLICT);
        }
        if ($conteudo === $atual) {
            return new RedirectResponse($this->linkPeles($site) . '/' . $pele . '?ok=nada', Response::HTTP_SEE_OTHER);
        }

        return $this->validarEGravar($sessao, $site, $pele, $nome, $conteudo, hash('sha256', $atual), false, 'pele.molde.salvar', 'salvo');
    }

    private function criarMolde(SessaoAtiva $sessao, Site $site, string $pele, Request $req): Response
    {
        $c = $this->textos($req, ['nome', 'conteudo']);
        if ($c === null) {
            return $this->formularioDeMolde($sessao, $site, $pele, '', '', ['mensagem' => 'Os campos precisam ser texto.', 'linha' => null], null, true, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $nome = trim($c['nome']);
        $conteudo = $this->normalizar($c['conteudo']);
        if (!GerenciadorDePeles::nomeDeMoldeValido($nome)) {
            return $this->formularioDeMolde($sessao, $site, $pele, $nome, $conteudo, ['mensagem' => 'Nome inválido: use letras minúsculas sem acento, números e _, com / só para pastas (até 3 níveis). A extensão .pbl é automática.', 'linha' => null], null, true, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($this->peles->ler($pele, $nome) !== null) {
            return $this->formularioDeMolde($sessao, $site, $pele, $nome, $conteudo, ['mensagem' => 'Já existe um molde com este nome.', 'linha' => null], null, true, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (count($this->peles->moldes($pele)) >= GerenciadorDePeles::MAX_MOLDES) {
            return $this->formularioDeMolde($sessao, $site, $pele, $nome, $conteudo, ['mensagem' => 'Limite de moldes da pele atingido.', 'linha' => null], null, true, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->validarEGravar($sessao, $site, $pele, $nome, $conteudo, null, true, 'pele.molde.criar', 'criado');
    }

    private function validarEGravar(SessaoAtiva $sessao, Site $site, string $pele, string $nome, string $conteudo, ?string $base, bool $novo, string $acao, string $ok): Response
    {
        $erro = $this->peles->validar($pele, $conteudo);
        if ($erro !== null) {
            return $this->formularioDeMolde($sessao, $site, $pele, $nome, $conteudo, $erro, $base, $novo, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $this->peles->gravar($pele, $nome, $conteudo);
        } catch (\RuntimeException | \InvalidArgumentException) {
            return $this->formularioDeMolde($sessao, $site, $pele, $nome, $conteudo, ['mensagem' => 'Não foi possível gravar o molde no disco.', 'linha' => null], $base, $novo, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        $this->servicos->auditoria->registrar($acao, 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, null, ['site_id' => $site->id, 'pele' => $pele, 'molde' => $nome]);
        $falha = $this->republicarPele($pele);

        return new RedirectResponse($this->linkPeles($site) . '/' . $pele . '?ok=' . $ok . ($falha ? '&rep=falhou' : ''), Response::HTTP_SEE_OTHER);
    }

    private function telaDeApagarMolde(SessaoAtiva $sessao, Site $site, string $pele, Request $req): Response
    {
        $nome = $req->query->all()['nome'] ?? null;
        if (!is_string($nome) || $this->peles->ler($pele, $nome) === null) {
            return $this->naoEncontrado($sessao, 'Este molde não existe.', $this->linkPeles($site) . '/' . $pele);
        }
        $motivo = $this->impedimentoParaApagar($pele, $nome);
        $base = $this->linkPeles($site) . '/' . $pele;
        $corpo = $motivo !== null ? '<div class="alert alert-warning" role="alert">' . Pagina::h($motivo) . '</div>'
            : '<p>Apagar o molde <code>' . Pagina::h($nome) . '.pbl</code> da pele <code>' . Pagina::h($pele) . '</code>? Não há como desfazer.</p>'
            . '<form method="post" action="' . $base . '/apagar">' . $this->campoCsrf($sessao) . '<input type="hidden" name="nome" value="' . Pagina::h($nome) . '">'
            . '<button type="submit" class="btn btn-danger">Apagar</button></form>';

        return Pagina::resposta('Apagar molde', $corpo . '<p class="mt-3"><a href="' . $base . '">Voltar aos arquivos da pele</a></p>', $motivo === null ? Response::HTTP_OK : Response::HTTP_CONFLICT, false, $this->barra($sessao));
    }

    private function apagarMolde(SessaoAtiva $sessao, Site $site, string $pele, Request $req): Response
    {
        $nome = $this->textos($req, ['nome'])['nome'] ?? '';
        if ($this->peles->ler($pele, $nome) === null) {
            return $this->naoEncontrado($sessao, 'Este molde não existe.', $this->linkPeles($site) . '/' . $pele);
        }
        $motivo = $this->impedimentoParaApagar($pele, $nome);
        if ($motivo !== null) {
            return Pagina::resposta('Apagar molde', '<div class="alert alert-warning" role="alert">' . Pagina::h($motivo) . '</div>', Response::HTTP_CONFLICT, false, $this->barra($sessao));
        }
        $this->peles->apagar($pele, $nome);
        $this->servicos->auditoria->registrar('pele.molde.apagar', 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, null, ['site_id' => $site->id, 'pele' => $pele, 'molde' => $nome]);
        $falha = $this->republicarPele($pele);

        return new RedirectResponse($this->linkPeles($site) . '/' . $pele . '?ok=apagado' . ($falha ? '&rep=falhou' : ''), Response::HTTP_SEE_OTHER);
    }

    private function impedimentoParaApagar(string $pele, string $nome): ?string
    {
        if ($nome === 'padrao') {
            return 'O molde padrao é o que serve toda página sem molde próprio; não pode ser apagado.';
        }
        $usam = $this->peles->quemUsa($pele, $nome);

        return $usam === [] ? null : 'Este molde é citado por: ' . implode(', ', $usam) . '. Tire a referência antes de apagar.';
    }

    /**
     * @param array{mensagem: string, linha: int|null}|null $erro
     */
    private function formularioDeMolde(SessaoAtiva $sessao, Site $site, string $pele, string $nome, string $conteudo, ?array $erro, ?string $base, bool $novo, int $status = Response::HTTP_OK): Response
    {
        $url = $this->linkPeles($site) . '/' . $pele;
        $aviso = '';
        if ($erro !== null) {
            $aviso = '<div id="erro-molde" class="alert alert-danger" role="alert"><strong>O molde não foi salvo.</strong> ' . Pagina::h($erro['mensagem']);
            if ($erro['linha'] !== null) {
                $linhas = explode("\n", $conteudo);
                $trecho = $linhas[$erro['linha'] - 1] ?? '';
                $aviso .= ' <span>(linha ' . $erro['linha'] . ')</span>' . ($trecho === '' ? '' : '<pre class="mb-0 mt-2"><code>' . Pagina::h(mb_substr($trecho, 0, 200)) . '</code></pre>');
            }
            $aviso .= '</div>';
        }
        $corpo = $aviso . '<p>Pele <code>' . Pagina::h($pele) . '</code>. O molde é validado pela linguagem do PBL4 antes de salvar; nada é executado como PHP.</p>'
            . '<form method="post" action="' . $url . ($novo ? '/novo' : '/molde') . '">' . $this->campoCsrf($sessao)
            . ($novo
                ? '<div class="mb-3"><label for="m-nome" class="form-label">Nome do molde (sem <code>.pbl</code>; <code>/</code> só para pastas)</label><input id="m-nome" class="form-control" name="nome" maxlength="100" required value="' . Pagina::h($nome) . '"></div>'
                : '<input type="hidden" name="nome" value="' . Pagina::h($nome) . '"><input type="hidden" name="base" value="' . Pagina::h((string) $base) . '"><p>Molde <code>' . Pagina::h($nome) . '.pbl</code></p>')
            . '<div class="mb-3"><label for="m-conteudo" class="form-label">Conteúdo</label>'
            . '<textarea id="m-conteudo" class="form-control font-monospace" name="conteudo" rows="26" spellcheck="false" wrap="off"' . ($erro === null ? '' : ' aria-invalid="true" aria-describedby="erro-molde"') . '>' . Pagina::h($conteudo) . '</textarea></div>'
            . '<button type="submit" class="btn btn-primary">Validar e salvar</button> <a class="btn btn-link" href="' . $url . '">Cancelar</a></form>';

        return Pagina::resposta($novo ? 'Novo molde' : 'Editar molde', $corpo, $status, false, $this->barra($sessao));
    }

    private function avisoDePele(Request $req): string
    {
        $q = $req->query->all();
        $ok = match ($q['ok'] ?? null) {
            'salvo' => 'Molde salvo.', 'criado' => 'Molde criado.', 'apagado' => 'Molde apagado.', 'ativada' => 'Pele ativada neste site.', 'nada' => 'Nada mudou.', default => '',
        };
        $html = $ok === '' ? '' : '<div class="alert alert-success" role="status">' . $ok . '</div>';
        if (($q['rep'] ?? null) === 'falhou') {
            $html .= '<div class="alert alert-warning" role="status">A alteração foi salva, mas as páginas não puderam ser republicadas agora. Publique o site de novo (<code>bin/pbl publicar</code>).</div>';
        }

        return $html;
    }

    /** Republica os sites que usam a pele; true se algum falhou (a alteração já está salva). */
    private function republicarPele(string $pele): bool
    {
        $falhou = false;
        foreach ($this->servicos->sites->todos() as $s) {
            if ($s->pele === $pele) {
                $falhou = $this->republicar($s->id) || $falhou;
            }
        }

        return $falhou;
    }

    private function republicar(int $siteId): bool
    {
        $site = $this->servicos->sites->porId($siteId);
        try {
            if ($site !== null) {
                $this->servicos->publicador->publicarSite($site);
            }
        } catch (\Throwable) {
            return true;
        }

        return false;
    }

    // ---------------------------------------------------------------- comum

    /**
     * Campos do POST que precisam ser texto; null se algum faltar ou não for texto.
     *
     * @param list<string> $nomes
     * @return array<string, string>|null
     */
    private function textos(Request $req, array $nomes): ?array
    {
        $todos = $req->request->all();
        $saida = [];
        foreach ($nomes as $n) {
            $v = $todos[$n] ?? ($n === 'codigo' ? '301' : null);
            if (!is_string($v)) {
                return null;
            }
            $saida[$n] = $v;
        }

        return $saida;
    }

    private function normalizar(string $texto): string
    {
        return str_replace("\r", "\n", str_replace("\r\n", "\n", $texto));
    }

    private function postConfere(SessaoAtiva $sessao, Request $requisicao): bool
    {
        $token = $requisicao->request->all()['_csrf'] ?? null;

        return $this->rede->origemConfere($requisicao) && is_string($token) && $this->csrf->conferirSessao($sessao->idHash, $token);
    }

    private function naoEncontrado(SessaoAtiva $sessao, string $mensagem, string $voltar = Painel::PREFIXO . '/'): Response
    {
        return Pagina::resposta('Não encontrado', '<div class="alert alert-warning" role="alert">' . Pagina::h($mensagem) . '</div><p><a href="' . Pagina::h($voltar) . '">Voltar</a></p>', Response::HTTP_NOT_FOUND, false, $this->barra($sessao));
    }

    private function campoCsrf(SessaoAtiva $sessao): string
    {
        return '<input type="hidden" name="_csrf" value="' . Pagina::h($this->csrf->daSessao($sessao->idHash)) . '">';
    }

    private function barra(SessaoAtiva $sessao): string
    {
        return Pagina::barra($sessao->usuario->nome, $this->csrf->daSessao($sessao->idHash));
    }

    private function linkRedir(Site $site): string
    {
        return Painel::PREFIXO . '/site/' . $site->id . '/redirecionamentos';
    }

    private function linkPeles(Site $site): string
    {
        return Painel::PREFIXO . '/site/' . $site->id . '/peles';
    }
}
