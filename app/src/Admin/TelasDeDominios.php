<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Conteudo\Dominio;
use Pbl\Conteudo\Site;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Domínios de um site (ADR 0012, fase 5d): listar, adicionar, definir o principal, tornar um domínio alias (`servir`: mesmo
 * conteúdo, com canônico) ou redirecionador (`redirecionar`: 301 para o principal) e remover. Exige a permissão de administrar o
 * site (`Permissoes::podeAdministrarSite`), conferida em toda rota, também no POST; sem permissão e site inexistente dão a mesma
 * 403. Todo POST confere origem e CSRF antes de qualquer outra coisa. A lógica fica no `DominioRepositorio` (o mesmo da linha de
 * comando): o site sempre tem um principal, que não se remove nem muda de modo (só se troca por outro). O domínio pelo qual a
 * pessoa está acessando agora não é removido, para não cortar o acesso no meio do trabalho.
 */
final class TelasDeDominios
{
    use AuxiliaresDeTela;

    private const MODOS = [Dominio::SERVIR => 'Alias (mostra o mesmo conteúdo)', Dominio::REDIRECIONAR => 'Redirecionador (301 para o principal)'];

    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
    ) {
    }

    /** @return array{0: string, 1: int}|null */
    public static function rota(string $prefixo, string $caminho, string $metodo): ?array
    {
        if (preg_match('#^' . preg_quote($prefixo, '#') . '/site/([1-9][0-9]{0,9})/dominios(?:/(adicionar|principal|modo|remover|inicio))?$#D', $caminho, $m) !== 1) {
            return null;
        }
        $acao = $m[2] ?? '';

        return match (true) {
            $acao === '' && $metodo === 'GET' => ['lista', (int) $m[1]],
            $acao !== '' && $metodo === 'POST' => [$acao, (int) $m[1]],
            default => null,
        };
    }

    /** @param array{0: string, 1: int} $rota */
    public function tratar(SessaoAtiva $sessao, array $rota, Request $requisicao): Response
    {
        [$tela, $siteId] = $rota;
        $post = $requisicao->getMethod() === 'POST';
        if ($post && !$this->postConfere($sessao, $requisicao)) {
            return $this->recusado();
        }
        $site = $this->servicos->sites->porId($siteId);
        if ($site === null || !$this->pode($sessao, $site)) {
            if ($post) {
                $u = $sessao->usuario;
                $this->servicos->auditoria->registrar('dominio.negado', 'usuario:' . $u->id, $u->id, null, ['site_id' => $siteId]);
            }

            return Pagina::resposta('Sem acesso', '<div class="alert alert-warning" role="alert">Você não tem permissão para administrar este site, ou ele não existe.</div>', Response::HTTP_FORBIDDEN, false, $this->barra($sessao));
        }
        if ($tela === 'lista') {
            return $this->pagina($sessao, $site, null, Response::HTTP_OK, $this->avisoDe($requisicao));
        }
        $host = $this->texto($requisicao, 'host');
        if ($host === null) {
            return $this->incompleto($sessao);
        }
        $host = strtolower(trim($host));
        $erro = $this->erroDeTexto($host, 253, 'O domínio');
        // Ações sensíveis (fase 5h): adicionar, trocar o principal e remover pedem a senha de novo (ou o modo elevado da sessão).
        // "modo" (alias/redirecionador) não troca o endereço nem derruba acesso, então não entra nessa exigência.
        if ($erro === null && in_array($tela, ['adicionar', 'principal', 'remover'], true)) {
            $falha = $this->exigirReautenticacao($sessao, $requisicao, 'dominio');
            if ($falha !== null) {
                return $this->aplicarFalhaDeReautenticacao($this->pagina($sessao, $site, $falha['mensagem'], Response::HTTP_OK), $falha);
            }
        }
        if ($erro === null) {
            try {
                return $this->aplicar($sessao, $site, $tela, $host, $requisicao);
            } catch (\InvalidArgumentException $e) {
                $erro = $this->mensagemDoRepositorio($e);
            }
        }

        return $this->pagina($sessao, $site, $erro, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** @throws \InvalidArgumentException mensagem em português do repositório ou desta tela */
    private function aplicar(SessaoAtiva $sessao, Site $site, string $tela, string $host, Request $requisicao): Response
    {
        $u = $sessao->usuario;
        $d = $this->servicos->dominios;
        $ator = 'usuario:' . $u->id;
        if ($tela === 'adicionar') {
            $modo = $this->texto($requisicao, 'modo') ?? '';
            if (!isset(self::MODOS[$modo])) {
                throw new \InvalidArgumentException('Escolha se o novo domínio é alias ou redirecionador');
            }
            $d->adicionar($site->id, $host, $modo);
            $this->servicos->auditoria->registrar('dominio.adicionar', $ator, $u->id, null, ['site_id' => $site->id, 'host' => $host, 'modo' => $modo]);

            return $this->volta($site, 'adicionado');
        }
        $atual = $d->porHost($host);
        if ($atual === null || $atual->siteId !== $site->id) {
            throw new \InvalidArgumentException('Esse domínio não pertence a este site');
        }
        switch ($tela) {
            case 'inicio':
                $alvo = trim($this->texto($requisicao, 'alvo') ?? '');
                $objetoId = null;
                if ($alvo !== '' && $alvo !== '-') {
                    $objeto = ctype_digit($alvo) ? $this->servicos->objetos->porId((int) $alvo) : $this->servicos->objetos->porUrl($site->id, trim($alvo, '/'));
                    if ($objeto === null || $objeto->siteId !== $site->id) {
                        throw new \InvalidArgumentException('Não achei esse conteúdo neste site. Use o endereço (ex.: faq) ou o número.');
                    }
                    $objetoId = $objeto->id;
                }
                $d->definirInicio($host, $objetoId);
                $this->servicos->auditoria->registrar('dominio.inicio', $ator, $u->id, $objetoId, ['site_id' => $site->id, 'host' => $host, 'objeto' => $objetoId]);

                return $this->volta($site, 'inicio');
            case 'principal':
                if ($atual->modo === Dominio::PRINCIPAL) {
                    throw new \InvalidArgumentException('Esse domínio já é o principal');
                }
                $antigo = $this->texto($requisicao, 'antigo') ?? Dominio::REDIRECIONAR;
                if (!isset(self::MODOS[$antigo])) {
                    throw new \InvalidArgumentException('Escolha o que o principal atual passa a ser');
                }
                $d->definirPrincipal($site->id, $host, $antigo);
                $this->servicos->enderecos->esquecer($site->id);
                try {
                    $this->servicos->publicador->hostMudou($site);
                } catch (\Throwable) {
                    // a troca já está salva; o site fica pendente de republicação (aviso no painel / bin/pbl publicar)
                }
                $this->servicos->auditoria->registrar('dominio.principal', $ator, $u->id, null, ['site_id' => $site->id, 'host' => $host, 'antigo' => $antigo]);

                return $this->volta($site, 'principal');
            case 'modo':
                $modo = $this->texto($requisicao, 'modo') ?? '';
                if (!isset(self::MODOS[$modo])) {
                    throw new \InvalidArgumentException('Escolha alias ou redirecionador');
                }
                $d->definirModo($host, $modo);
                $this->servicos->auditoria->registrar('dominio.modo', $ator, $u->id, null, ['site_id' => $site->id, 'host' => $host, 'modo' => $modo]);

                return $this->volta($site, 'modo');
            default:
                if ($host === strtolower($this->hostAtual($requisicao))) {
                    throw new \InvalidArgumentException('Você está neste endereço agora. Abra o painel por outro domínio para removê-lo.');
                }
                $d->remover($host);
                $this->servicos->auditoria->registrar('dominio.remover', $ator, $u->id, null, ['site_id' => $site->id, 'host' => $host]);

                return $this->volta($site, 'removido');
        }
    }

    private function pagina(SessaoAtiva $sessao, Site $site, ?string $erro, int $status, string $aviso = ''): Response
    {
        $csrf = $this->campoCsrf($sessao);
        $base = $this->link($site->id);
        $dominios = $this->servicos->dominios->doSite($site->id);
        $principal = null;
        $outros = ['servir' => [], 'redirecionar' => []];
        foreach ($dominios as $d) {
            if ($d->modo === Dominio::PRINCIPAL) {
                $principal ??= $d;
            } else {
                $outros[$d->modo === Dominio::SERVIR ? 'servir' : 'redirecionar'][] = $d;
            }
        }
        $tabela = function (array $lista, string $legenda) use ($sessao, $csrf, $base): string {
            $linhas = '';
            foreach ($lista as $d) {
                $linhas .= '<tr><th scope="row"><code>' . Pagina::h($d->host) . '</code></th><td>' . ($d->modo === Dominio::SERVIR ? 'Alias' : 'Redirecionador') . '</td><td>' . $this->acoesDe($sessao, $csrf, $base, $d) . '</td></tr>';
            }

            return '<div class="table-responsive"><table class="table table-sm align-middle"><caption class="visually-hidden">' . Pagina::h($legenda) . '</caption><thead><tr><th scope="col">Domínio</th><th scope="col">Tipo</th><th scope="col">Ações</th></tr></thead><tbody>' . $linhas . '</tbody></table></div>';
        };
        $corpo = $aviso . $this->erro($erro)
            . Pagina::cabecalho('Site <strong>' . Pagina::h($site->nome) . '</strong> · ' . count($dominios) . ' ' . (count($dominios) === 1 ? 'domínio' : 'domínios') . '.', '<a class="btn btn-primary" href="#adicionar-dominio">Adicionar domínio</a>',
                ['<a href="' . Painel::PREFIXO . '/site/' . $site->id . '/configuracoes">Configurações do site</a>'])
            . ($principal === null
                ? Pagina::cartaoDeEstado('atencao', 'Nenhum domínio principal ainda', 'Adicione um domínio abaixo e torne-o o principal.')
                : Pagina::cartaoDeEstado('ok', 'Endereço principal', '<code>' . Pagina::h($principal->host) . '</code> <span class="pbl-selo">Principal</span> — o endereço canônico do site.'))
            . '<h2 class="h5" id="titulo-dominios">Domínios</h2>'
            . ($outros['servir'] === [] ? Pagina::vazio('Nenhum alias', 'Um alias abre o mesmo site por outro nome. Adicione um abaixo, se precisar.') : $tabela($outros['servir'], 'Aliases do site'))
            . ($outros['redirecionar'] === [] ? '' : '<div class="pbl-recolhiveis">' . Pagina::recolhivel('redirecionamentos', 'Redirecionamentos (' . count($outros['redirecionar']) . ')', $tabela($outros['redirecionar'], 'Redirecionamentos do site')) . '</div>');
        $linhasInicio = '';
        foreach ($dominios as $d) {
            $objeto = $d->inicioObjetoId === null ? null : $this->servicos->objetos->porId($d->inicioObjetoId);
            $atual = $objeto === null ? '<span class="text-body-secondary">Página inicial do site</span>' : Pagina::h($objeto->titulo) . ' <code>/' . Pagina::h($objeto->urlAmigavel) . '</code>';
            $linhasInicio .= '<tr><th scope="row"><code>' . Pagina::h($d->host) . '</code></th><td>' . $atual . '</td><td>'
                . '<form method="post" action="' . $base . '/inicio" class="d-flex flex-wrap gap-1 align-items-end">' . $csrf . '<input type="hidden" name="host" value="' . Pagina::h($d->host) . '">'
                . '<label class="visually-hidden" for="inicio-' . md5($d->host) . '">Conteúdo que abre em / em ' . Pagina::h($d->host) . '</label>'
                . '<input id="inicio-' . md5($d->host) . '" class="form-control form-control-sm w-auto" name="alvo" maxlength="300" placeholder="endereço ou número" value="' . ($objeto === null ? '' : Pagina::h($objeto->urlAmigavel)) . '">'
                . '<button type="submit" class="btn btn-sm btn-outline-primary">Salvar</button></form></td></tr>';
        }
        $corpo .= '<section class="mt-4" id="inicio-por-dominio" aria-labelledby="titulo-inicio"><h2 class="h5" id="titulo-inicio">O que cada domínio abre</h2>'
            . '<p class="text-body-secondary">Por padrão todo domínio abre a página inicial do site. Aqui um domínio pode abrir direto um conteúdo (ex.: <code>faq.exemplo.org</code> abrindo <code>faq</code>). O resto do site continua igual. Deixe em branco para voltar ao padrão.</p>'
            . '<div class="table-responsive"><table class="table table-sm align-middle"><caption class="visually-hidden">Conteúdo que abre em / em cada domínio</caption><thead><tr><th scope="col">Domínio</th><th scope="col">Abre em /</th><th scope="col">Trocar</th></tr></thead><tbody>' . $linhasInicio . '</tbody></table></div></section>';
        $modos = '';
        foreach (self::MODOS as $valor => $rotulo) {
            $modos .= '<option value="' . $valor . '">' . Pagina::h($rotulo) . '</option>';
        }
        $corpo .= '<section class="mt-4" id="adicionar-dominio"><h2 class="h5">Adicionar domínio</h2><form method="post" action="' . $base . '/adicionar" class="row g-2 align-items-end">' . $csrf
            . '<div class="col-sm-5">' . Pagina::rotulo('campo-host', 'Domínio', Pagina::ajuda('ajuda-host', 'Domínio', 'Só o nome, sem <code>https://</code>. DNS e certificado apontam para esta hospedagem.')) . '<input id="campo-host" class="form-control" name="host" maxlength="253" required placeholder="www.exemplo.org" aria-describedby="ajuda-host"></div>'
            . '<div class="col-sm-5"><label for="campo-modo" class="form-label">Tipo</label><select id="campo-modo" class="form-select" name="modo">' . $modos . '</select></div>'
            . '<div class="col-sm-2">' . $this->campoSenha($sessao, 'campo-senha-adicionar') . '<button type="submit" class="btn btn-primary">Adicionar</button></div>'
            . '</form></section>';

        return Pagina::resposta('Domínios: ' . $site->nome, $corpo, $status, false, $this->barra($sessao), [['Site', Painel::PREFIXO . '/site/' . $site->id . '/configuracoes'], ['Domínios', null]], '', '<em>Principal</em>: endereço oficial. <em>Alias</em>: mesmo conteúdo. <em>Redirecionador</em>: leva ao principal (301).');
    }

    private function acoesDe(SessaoAtiva $sessao, string $csrf, string $base, Dominio $d): string
    {
        $h = '<input type="hidden" name="host" value="' . Pagina::h($d->host) . '">';
        $outro = $d->modo === Dominio::SERVIR ? Dominio::REDIRECIONAR : Dominio::SERVIR;

        return '<div class="d-flex flex-wrap gap-1 align-items-end">'
            . '<form method="post" action="' . $base . '/principal" class="d-flex gap-1 align-items-end">' . $csrf . $h . '<input type="hidden" name="antigo" value="' . Dominio::REDIRECIONAR . '">'
            . $this->campoSenha($sessao, 'campo-senha-principal-' . md5($d->host)) . '' . Pagina::botaoIcone('principal', 'Tornar principal', 'btn-outline-primary', ['item' => $d->host]) . '</form>'
            . '<form method="post" action="' . $base . '/modo">' . $csrf . $h . '<input type="hidden" name="modo" value="' . $outro . '">'
            . Pagina::botaoIcone('alternar', $outro === Dominio::SERVIR ? 'Tornar alias' : 'Tornar redirecionador', 'btn-outline-secondary', ['item' => $d->host]) . '</form>'
            . '<form method="post" action="' . $base . '/remover" class="d-flex gap-1 align-items-end">' . $csrf . $h
            . $this->campoSenha($sessao, 'campo-senha-remover-' . md5($d->host)) . '' . Pagina::botaoIcone('excluir', 'Remover', 'btn-danger bg-gradient', ['item' => $d->host]) . '</form></div>';
    }

    private function pode(SessaoAtiva $sessao, Site $site): bool
    {
        $raiz = $site->objetoRaizId === null ? null : $this->servicos->objetos->porId($site->objetoRaizId);

        return $this->servicos->permissoes->podeAdministrarSite($sessao->usuario, $raiz);
    }

    private function hostAtual(Request $requisicao): string
    {
        try {
            return $requisicao->getHost();
        } catch (\Throwable) {
            return '';
        }
    }

    private function volta(Site $site, string $ok): Response
    {
        return new RedirectResponse($this->link($site->id) . '?ok=' . $ok, Response::HTTP_SEE_OTHER);
    }

    private function avisoDe(Request $requisicao): string
    {
        $texto = match ($requisicao->query->all()['ok'] ?? null) {
            'adicionado' => 'Domínio adicionado.',
            'principal' => 'Domínio principal trocado.',
            'modo' => 'Tipo do domínio alterado.',
            'removido' => 'Domínio removido.',
            'inicio' => 'Conteúdo inicial do domínio salvo.',
            default => null,
        };

        return $texto === null ? '' : '<div class="alert alert-success" role="status">' . $texto . '</div>';
    }

    private function link(int $id): string
    {
        return Painel::PREFIXO . '/site/' . $id . '/dominios';
    }
}
