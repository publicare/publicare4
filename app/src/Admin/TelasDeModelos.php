<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Conteudo\Modelos;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tela "Modelos" (ADR 0021): lista os modelos opcionais (evento, faq, destaque, bloco), mostra quais já estão ativos e ativa os
 * que faltam, com conteúdo de exemplo. Só o administrador global (como os tipos de conteúdo, que os modelos criam). Todo POST
 * confere origem e CSRF; ativar é idempotente e nunca sobrescreve o que a administração já ajustou.
 */
final class TelasDeModelos
{
    use AuxiliaresDeTela;

    private const DESCRICAO = 'Pacotes prontos de tipos de conteúdo, com exemplos e moldes na pele básica. Ativar não apaga nem substitui nada que você já ajustou.';

    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
    ) {
    }

    /** @return array{0: string, 1: null}|null */
    public static function rota(string $prefixo, string $caminho, string $metodo): ?array
    {
        return match (true) {
            $caminho === $prefixo . '/modelos' && $metodo === 'GET' => ['lista', null],
            $caminho === $prefixo . '/modelos/ativar' && $metodo === 'POST' => ['ativar', null],
            default => null,
        };
    }

    /** @param array{0: string, 1: null} $rota */
    public function tratar(SessaoAtiva $sessao, array $rota, Request $requisicao): Response
    {
        if ($rota[0] === 'ativar' && !$this->postConfere($sessao, $requisicao)) {
            return $this->recusado();
        }
        if (!$this->servicos->permissoes->podeModelarClasses($sessao->usuario)) {
            return Pagina::resposta('Sem acesso', '<div class="alert alert-warning" role="alert">Só administradores ativam modelos.</div>', Response::HTTP_FORBIDDEN, false, $this->barra($sessao));
        }

        return $rota[0] === 'lista' ? $this->lista($sessao, '') : $this->ativar($sessao, $requisicao);
    }

    private function ativar(SessaoAtiva $sessao, Request $requisicao): Response
    {
        $modelo = $requisicao->request->get('modelo');
        $siteId = $requisicao->request->get('site');
        if (!is_string($modelo) || !in_array($modelo, Modelos::NOMES, true) || !is_string($siteId) || !ctype_digit($siteId) || strlen($siteId) > 9) {
            return $this->incompleto($sessao);
        }
        $site = $this->servicos->sites->porId((int) $siteId);
        if ($site === null) {
            return $this->incompleto($sessao);
        }
        $r = (new Modelos($this->servicos->conexao(), $this->servicos->caminhos))->ativar($modelo, $site->id);
        $u = $sessao->usuario;
        $this->servicos->auditoria->registrar('modelo.ativar', 'usuario:' . $u->id, $u->id, null, ['modelo' => $modelo, 'site_id' => $site->id, 'classes' => count($r['classes']), 'exemplos' => $r['exemplos']]);
        $catalogo = Modelos::catalogo()[$modelo];
        $aviso = Pagina::alerta('success', 'Modelo "' . $catalogo['titulo'] . '" ativado no site ' . $site->nome . ': '
            . ($r['classes'] === [] ? 'os tipos de conteúdo já existiam' : count($r['classes']) . ' tipo(s) criado(s)')
            . ', ' . $r['exemplos'] . ' conteúdo(s) de exemplo, ' . count($r['moldes']) . ' molde(s) gravado(s). Republique o site para ver o resultado.');
        foreach ($r['avisos'] as $texto) {
            $aviso .= Pagina::alerta('warning', $texto);
        }

        return $this->lista($sessao, $aviso);
    }

    private function lista(SessaoAtiva $sessao, string $aviso): Response
    {
        $modelos = new Modelos($this->servicos->conexao(), $this->servicos->caminhos);
        $sites = $this->servicos->sites->todos();
        $corpo = $aviso . '<div class="table-responsive"><table class="table align-middle"><caption class="visually-hidden">Modelos opcionais</caption><thead><tr>'
            . '<th scope="col">Modelo</th><th scope="col">Tipos criados</th><th scope="col">Situação</th><th scope="col"><span class="visually-hidden">Ação</span></th></tr></thead><tbody>';
        foreach (Modelos::catalogo() as $m) {
            $ativo = $modelos->ativo($m['nome']);
            $corpo .= '<tr><td><strong>' . Pagina::h($m['titulo']) . '</strong><br><span class="text-body-secondary">' . Pagina::h($m['descricao']) . '</span></td>'
                . '<td>' . implode(', ', array_map(static fn (string $c): string => '<code>' . Pagina::h($c) . '</code>', $m['classes'])) . '</td>'
                . '<td>' . ($ativo ? '<span class="badge text-bg-success">Ativo</span>' : '<span class="badge text-bg-secondary">Inativo</span>') . '</td><td class="text-end">';
            if (!$ativo) {
                $corpo .= '<form method="post" action="' . Painel::PREFIXO . '/modelos/ativar" class="d-inline-flex gap-2 align-items-center">' . $this->campoCsrf($sessao)
                    . '<input type="hidden" name="modelo" value="' . Pagina::h($m['nome']) . '">';
                if (count($sites) > 1) {
                    $corpo .= '<label class="visually-hidden" for="site-' . Pagina::h($m['nome']) . '">Site</label><select class="form-select form-select-sm" id="site-' . Pagina::h($m['nome']) . '" name="site">';
                    foreach ($sites as $s) {
                        $corpo .= '<option value="' . $s->id . '">' . Pagina::h($s->nome) . '</option>';
                    }
                    $corpo .= '</select>';
                } else {
                    $corpo .= '<input type="hidden" name="site" value="' . ($sites[0]->id ?? 0) . '">';
                }
                $corpo .= '<button type="submit" class="btn btn-sm btn-primary">Ativar<span class="visually-hidden"> ' . Pagina::h($m['titulo']) . '</span></button></form>';
            }
            $corpo .= '</td></tr>';
        }
        $corpo .= '</tbody></table></div>';

        return Pagina::resposta('Modelos', $corpo, Response::HTTP_OK, false, $this->barra($sessao), [['Tipos de conteúdo', Painel::PREFIXO . '/classes'], ['Modelos', null]], self::DESCRICAO);
    }
}
