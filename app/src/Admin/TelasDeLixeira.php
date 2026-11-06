<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Acao;
use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Conteudo\ConflitoDeArvore;
use Pbl\Conteudo\ConflitoDeVersao;
use Pbl\Conteudo\Objeto;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lixeira por site ou seção (ADR 0026): lista o que foi mandado para a lixeira, tira de lá (a tela do objeto, que já existe),
 * apaga de vez um item e esvazia. Apagar de vez não desfaz: pede a senha (ou o modo elevado), mostra uma tela de confirmação com o
 * que vai embora, exige o direito de apagar sobre o item e deixa a auditoria. Itens dentro de outro item da lixeira saem junto com ele.
 */
final class TelasDeLixeira
{
    use AuxiliaresDeTela;
    use EscopoDeSecoes;

    public const LIMITE = 200;
    private const LIXEIRA = 'apagado';

    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
    ) {
    }

    /** @return array{0: string, 1: int|null}|null */
    public static function rota(string $prefixo, string $caminho, string $metodo): ?array
    {
        $base = $prefixo . '/lixeira';
        if ($caminho === $base) {
            return $metodo === 'GET' ? ['lista', null] : null;
        }
        if ($caminho === $base . '/esvaziar') {
            return $metodo === 'GET' ? ['confirmarEsvaziar', null] : ($metodo === 'POST' ? ['esvaziar', null] : null);
        }
        if (preg_match('#^' . preg_quote($base, '#') . '/apagar-de-vez/([1-9][0-9]{0,9})$#D', $caminho, $m) === 1) {
            return $metodo === 'GET' ? ['confirmarApagar', (int) $m[1]] : ($metodo === 'POST' ? ['apagar', (int) $m[1]] : null);
        }

        return null;
    }

    /** @param array{0: string, 1: int|null} $rota */
    public function tratar(SessaoAtiva $sessao, array $rota, Request $r): Response
    {
        return match ($rota[0]) {
            'lista' => $this->lista($sessao, $r),
            'confirmarEsvaziar' => $this->telaEsvaziar($sessao, $r),
            'esvaziar' => $this->esvaziar($sessao, $r),
            'confirmarApagar' => $this->telaApagar($sessao, (int) $rota[1], $r),
            default => $this->apagar($sessao, (int) $rota[1], $r),
        };
    }

    // ---------------------------------------------------------------- lista

    private function lista(SessaoAtiva $sessao, Request $r): Response
    {
        $pedida = $this->secaoPedida($sessao, $r);
        $itens = $this->itens($sessao, $r);
        $corpo = $this->seletorDeSecao($sessao, Painel::PREFIXO . '/lixeira', $pedida) . $this->avisoDeResultado($r);
        if ($itens === []) {
            $corpo .= Pagina::alerta('info', 'A lixeira está vazia' . ($pedida === null ? '.' : ' nesta seção.'));
        } else {
            $corpo .= '<div class="table-responsive"><table class="table table-sm align-middle"><caption class="visually-hidden">Conteúdo na lixeira</caption>'
                . '<thead><tr><th scope="col">Título</th><th scope="col">Tipo</th><th scope="col">Site</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead><tbody>';
            foreach ($itens as $o) {
                $corpo .= $this->linha($sessao, $o);
            }
            $corpo .= '</tbody></table></div>';
            $apagaveis = $this->apagaveis($sessao, $itens);
            if ($apagaveis !== []) {
                $corpo .= '<p><a class="btn btn-outline-danger" href="' . Pagina::h(Painel::PREFIXO . '/lixeira/esvaziar' . $this->consultaDeSecao($pedida)) . '">Esvaziar a lixeira' . ($pedida === null ? '' : ' desta seção') . '</a></p>';
            }
        }

        return Pagina::resposta('Lixeira', $corpo, Response::HTTP_OK, false, $this->barra($sessao), [], 'Conteúdo que foi mandado para a lixeira. Dá para <strong>tirar de lá</strong> com o histórico inteiro, ou <strong>apagar de vez</strong> (não desfaz).');
    }

    private function linha(SessaoAtiva $sessao, Objeto $o): string
    {
        $base = Painel::PREFIXO . '/objeto/' . $o->id;
        $pai = $o->paiId === null ? null : $this->servicos->objetos->porId($o->paiId);
        $paiNaLixeira = $pai !== null && $pai->status === self::LIXEIRA;
        $acoes = '';
        if ($this->servicos->permissoes->pode($sessao->usuario, Acao::Apagar, $o)) {
            if ($paiNaLixeira) {
                $acoes .= '<span class="small text-body-secondary">dentro de “' . Pagina::h($pai->titulo) . '”</span> ';
            } else {
                $acoes .= '<form method="post" action="' . $base . '/recuperar" class="d-inline">' . $this->campoCsrf($sessao)
                    . '<input type="hidden" name="versao" value="' . $o->versao . '"><button type="submit" class="btn btn-sm btn-outline-primary">Tirar da lixeira<span class="visually-hidden">: ' . Pagina::h($o->titulo) . '</span></button></form> ';
            }
            $acoes .= '<a class="btn btn-sm btn-outline-danger" href="' . Painel::PREFIXO . '/lixeira/apagar-de-vez/' . $o->id . '">Apagar de vez<span class="visually-hidden">: ' . Pagina::h($o->titulo) . '</span></a>';
        }
        $site = $this->servicos->sites->porId($o->siteId);

        return '<tr><td><a href="' . $base . '">' . Pagina::h($o->titulo) . '</a></td>'
            . '<td>' . Pagina::h($this->servicos->classes->porId($o->classeId)->nome ?? '?') . '</td>'
            . '<td>' . Pagina::h($site->nome ?? '—') . '</td>'
            . '<td class="text-nowrap">' . $acoes . '</td></tr>';
    }

    private function avisoDeResultado(Request $r): string
    {
        $ok = $r->query->get('ok');
        $n = max(0, (int) (is_string($r->query->get('n')) ? $r->query->get('n') : 0));
        if ($ok === 'apagado') {
            return Pagina::alerta('success', $n === 1 ? 'Apagado de vez: 1 conteúdo.' : 'Apagado de vez: ' . $n . ' conteúdos.');
        }
        if ($ok === 'esvaziada') {
            return Pagina::alerta('success', $n === 0 ? 'Não havia nada para apagar.' : 'Lixeira esvaziada: ' . $n . ($n === 1 ? ' conteúdo apagado de vez.' : ' conteúdos apagados de vez.'));
        }

        return '';
    }

    // ---------------------------------------------------------------- apagar de vez

    private function telaApagar(SessaoAtiva $sessao, int $id, Request $r, ?string $erro = null, int $status = Response::HTTP_OK): Response
    {
        $o = $this->alvo($sessao, $id);
        if ($o === null) {
            return $this->semAcesso($sessao);
        }
        $dentro = count($this->servicos->objetos->todosDaSubarvore($o->siteId, $o->caminho)) - 1;
        $corpo = ($erro === null ? '' : Pagina::alerta('danger', $erro))
            . '<p>Apagar <strong>' . Pagina::h($o->titulo) . '</strong> de vez' . ($dentro > 0 ? ', com os ' . $dentro . ' ' . ($dentro === 1 ? 'conteúdo' : 'conteúdos') . ' que estão dentro dele' : '') . '? '
            . 'As versões, os endereços antigos, as tags e os papéis dele somem e <strong>não há como desfazer</strong>. A auditoria guarda que foi apagado.</p>'
            . '<form method="post" action="' . Painel::PREFIXO . '/lixeira/apagar-de-vez/' . $o->id . '">' . $this->campoCsrf($sessao)
            . '<input type="hidden" name="versao" value="' . $o->versao . '">' . $this->campoSecaoOculto($this->secaoPedida($sessao, $r))
            . $this->campoSenha($sessao)
            . Pagina::acoes('Apagar de vez', [['Cancelar', Painel::PREFIXO . '/lixeira' . $this->consultaDeSecao($this->secaoPedida($sessao, $r))]], null, 'btn-danger') . '</form>';

        return Pagina::resposta('Apagar de vez: ' . $o->titulo, $corpo, $status, false, $this->barra($sessao));
    }

    private function apagar(SessaoAtiva $sessao, int $id, Request $r): Response
    {
        if (!$this->postConfere($sessao, $r)) {
            return $this->recusado();
        }
        $o = $this->alvo($sessao, $id);
        if ($o === null) {
            return $this->semAcesso($sessao);
        }
        $versao = $this->numero($this->texto($r, 'versao'));
        if ($versao === null) {
            return $this->incompleto($sessao);
        }
        $falha = $this->exigirReautenticacao($sessao, $r, 'lixeira');
        if ($falha !== null) {
            return $this->aplicarFalhaDeReautenticacao($this->telaApagar($sessao, $id, $r, $falha['mensagem']), $falha);
        }
        try {
            $n = $this->servicos->objetos->apagarDefinitivamente($o->id, $versao, $sessao->usuario->id);
        } catch (ConflitoDeVersao) {
            return $this->telaApagar($sessao, $id, $r, 'Outra pessoa alterou este conteúdo depois que você abriu esta tela. Confira e confirme de novo.', Response::HTTP_CONFLICT);
        } catch (ConflitoDeArvore) {
            return $this->telaApagar($sessao, $id, $r, 'Este conteúdo não pode ser apagado de vez agora: não está mais na lixeira, ou há algo fora dela dentro dele.', Response::HTTP_CONFLICT);
        }

        return new RedirectResponse(Painel::PREFIXO . '/lixeira' . $this->consultaDeSecao($this->secaoPedida($sessao, $r), ['ok' => 'apagado', 'n' => (string) $n]), Response::HTTP_SEE_OTHER);
    }

    // ---------------------------------------------------------------- esvaziar

    private function telaEsvaziar(SessaoAtiva $sessao, Request $r, ?string $erro = null, int $status = Response::HTTP_OK): Response
    {
        $pedida = $this->secaoPedida($sessao, $r);
        $apagaveis = $this->apagaveis($sessao, $this->itens($sessao, $r));
        $volta = Painel::PREFIXO . '/lixeira' . $this->consultaDeSecao($pedida);
        if ($apagaveis === []) {
            return Pagina::resposta('Esvaziar a lixeira', Pagina::alerta('info', 'Não há nada na lixeira que você possa apagar.') . Pagina::acoes('', [['Voltar', $volta]]), $status, false, $this->barra($sessao));
        }
        $n = count($apagaveis);
        $corpo = ($erro === null ? '' : Pagina::alerta('danger', $erro))
            . '<p>Apagar de vez ' . ($n === 1 ? '<strong>1 conteúdo</strong>' : '<strong>' . $n . ' conteúdos</strong>') . ' da lixeira' . ($pedida === null ? '' : ' de “' . Pagina::h($pedida->titulo) . '”') . '? '
            . 'Os que estão dentro deles saem junto. <strong>Não há como desfazer.</strong>' . ($n >= self::LIMITE ? ' Vão no máximo ' . self::LIMITE . ' por vez; repita se sobrar.' : '') . '</p><ul>';
        foreach (array_slice($apagaveis, 0, 10) as $o) {
            $corpo .= '<li>' . Pagina::h($o->titulo) . '</li>';
        }
        $corpo .= '</ul>' . ($n > 10 ? '<p class="text-body-secondary">…e mais ' . ($n - 10) . '.</p>' : '')
            . '<form method="post" action="' . Painel::PREFIXO . '/lixeira/esvaziar">' . $this->campoCsrf($sessao) . $this->campoSecaoOculto($pedida)
            . $this->campoSenha($sessao)
            . Pagina::acoes('Esvaziar a lixeira', [['Cancelar', $volta]], null, 'btn-danger') . '</form>';

        return Pagina::resposta('Esvaziar a lixeira', $corpo, $status, false, $this->barra($sessao));
    }

    private function esvaziar(SessaoAtiva $sessao, Request $r): Response
    {
        if (!$this->postConfere($sessao, $r)) {
            return $this->recusado();
        }
        $falha = $this->exigirReautenticacao($sessao, $r, 'lixeira');
        if ($falha !== null) {
            return $this->aplicarFalhaDeReautenticacao($this->telaEsvaziar($sessao, $r, $falha['mensagem']), $falha);
        }
        $apagados = 0;
        foreach ($this->apagaveis($sessao, $this->itens($sessao, $r)) as $o) {
            $atual = $this->servicos->objetos->porId($o->id);
            if ($atual === null) {
                continue; // saiu junto com um item de cima
            }
            try {
                $apagados += $this->servicos->objetos->apagarDefinitivamente($atual->id, $atual->versao, $sessao->usuario->id);
            } catch (ConflitoDeVersao | ConflitoDeArvore) {
                continue;
            }
        }
        $this->servicos->auditoria->registrar('lixeira.esvaziar', 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, $this->secaoPedida($sessao, $r)?->id, ['apagados' => $apagados]);

        return new RedirectResponse(Painel::PREFIXO . '/lixeira' . $this->consultaDeSecao($this->secaoPedida($sessao, $r), ['ok' => 'esvaziada', 'n' => (string) $apagados]), Response::HTTP_SEE_OTHER);
    }

    // ---------------------------------------------------------------- apoio

    /** O item da lixeira que a pessoa pode apagar de vez (existe, está na lixeira, não é a raiz do site, tem o direito Apagar). */
    private function alvo(SessaoAtiva $sessao, int $id): ?Objeto
    {
        $o = $this->servicos->objetos->porId($id);
        if ($o === null || $o->status !== self::LIXEIRA || $o->paiId === null || !$this->servicos->permissoes->pode($sessao->usuario, Acao::Apagar, $o)) {
            return null;
        }

        return $o;
    }

    /**
     * Itens da lixeira no recorte que a pessoa pode ver, os mais recentes primeiro.
     *
     * @return list<Objeto>
     */
    private function itens(SessaoAtiva $sessao, Request $r): array
    {
        $achados = [];
        foreach ($this->secoesDoEscopo($sessao, $r) as $secao) {
            foreach ($this->servicos->objetos->daLixeiraDaSubarvore($secao->siteId, $secao->caminho, self::LIMITE) as $o) {
                if ($this->servicos->permissoes->pode($sessao->usuario, Acao::Ver, $o)) {
                    $achados[$o->id] = $o;
                }
            }
        }
        uasort($achados, static fn (Objeto $a, Objeto $b): int => $b->id <=> $a->id);

        return array_values(array_slice($achados, 0, self::LIMITE, true));
    }

    /**
     * @param list<Objeto> $itens
     * @return list<Objeto>
     */
    private function apagaveis(SessaoAtiva $sessao, array $itens): array
    {
        return array_values(array_filter($itens, fn (Objeto $o): bool => $this->servicos->permissoes->pode($sessao->usuario, Acao::Apagar, $o)));
    }

    /** @param array<string, string> $mais */
    private function consultaDeSecao(?Objeto $secao, array $mais = []): string
    {
        $q = ($secao === null ? [] : ['secao' => (string) $secao->id]) + $mais;

        return $q === [] ? '' : '?' . http_build_query($q);
    }

    private function campoSecaoOculto(?Objeto $secao): string
    {
        return $secao === null ? '' : '<input type="hidden" name="secao" value="' . $secao->id . '">';
    }

    private function semAcesso(SessaoAtiva $sessao): Response
    {
        return Pagina::resposta('Sem acesso', Pagina::alerta('warning', 'Você não pode apagar este conteúdo de vez, ou ele não está na lixeira.') . Pagina::acoes('', [['Voltar à lixeira', Painel::PREFIXO . '/lixeira']]), Response::HTTP_FORBIDDEN, false, $this->barra($sessao));
    }
}
