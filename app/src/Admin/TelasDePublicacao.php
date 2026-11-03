<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Conteudo\Site;
use Pbl\Publicacao\FilaDeRepublicacao;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Republicar" com barra de progresso (ADR 0023): a tela de publicação de um site e o endpoint de lotes que o `pbl-admin.js` chama
 * por `fetch`, um lote de {@see FilaDeRepublicacao::LOTE_PAINEL} páginas por vez. Sem JavaScript o mesmo botão (POST comum) trata
 * lotes até acabar ou até o limite de tempo da requisição, e a tela oferece "Continuar". A fila é a mesma de `bin/pbl publicar` e
 * do cron: retomável e com trava contra duas republicações do mesmo site. Quem chega aqui já passou pelo CSRF e pela conferência
 * de permissão de `TelasDeSite::tratar`.
 */
final class TelasDePublicacao
{
    use AuxiliaresDeTela;

    /** Tempo (s) que o POST sem JavaScript gasta em lotes antes de devolver a tela com "Continuar". */
    private const TEMPO_SEM_JS = 20.0;

    /** @param (\Closure(): float)|null $microtime só para os testes */
    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
        private readonly ?\Closure $microtime = null,
    ) {
    }

    public static function link(Site $site): string
    {
        return Painel::PREFIXO . '/site/' . $site->id . '/publicacao';
    }

    public static function avisoDaConsulta(Request $requisicao): string
    {
        return ($requisicao->query->all()['ok'] ?? null) === 'republicado' ? Pagina::alerta('success', 'Site republicado.') : '';
    }

    public function exibir(SessaoAtiva $sessao, Site $site, string $aviso = '', int $status = Response::HTTP_OK): Response
    {
        $fila = $this->servicos->republicacao;
        $estado = $fila->estado($site->id);
        $andando = $estado !== null && $estado['status'] === FilaDeRepublicacao::ANDANDO;
        $total = $this->servicos->objetos->contarDoSite($site->id);
        $corpo = $aviso . TelasDeSite::avisosDeMenu($this->servicos, $site, self::link($site))
            . ($andando ? Pagina::alerta('info', 'Há uma republicação em andamento (' . $estado['feitas'] . ' de ' . $estado['total'] . ' páginas). Clique em "Continuar" para retomar de onde parou.') : '')
            . '<p>O site tem ' . $total . ' objeto(s). Republicar refaz o menu e todas as páginas publicadas, em lotes de ' . FilaDeRepublicacao::LOTE_PAINEL
            . ' páginas, com o andamento na tela. Se a conexão cair, é só voltar e continuar.</p>'
            . '<form method="post" action="' . self::link($site) . '" data-pbl-republicar="' . self::link($site) . '/lote">' . $this->campoCsrf($sessao)
            . '<button type="submit" class="btn btn-primary" data-pbl-iniciar>' . ($andando ? 'Continuar' : 'Republicar agora') . '</button></form>'
            . '<div class="mt-3" data-pbl-republicar-progresso hidden aria-live="polite">'
            . '<div class="progress" role="progressbar" aria-label="Andamento da republicação" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="progress-bar" data-pbl-barra></div></div>'
            . '<p class="mt-2 mb-0" data-pbl-texto></p></div>'
            . ($estado === null || $andando ? '' : $this->resumo($estado));

        return Pagina::resposta('Publicação de ' . $site->nome, $corpo, $status, false, $this->barra($sessao), [[$site->nome, Painel::PREFIXO . '/'], ['Publicação', null]]);
    }

    /** POST do botão sem JavaScript: inicia (ou retoma) e trata lotes até acabar ou acabar o tempo. */
    public function republicar(SessaoAtiva $sessao, Site $site): Response
    {
        $fila = $this->servicos->republicacao;
        try {
            $fila->iniciar($site, 'painel');
            $limite = $this->agora() + self::TEMPO_SEM_JS;
            do {
                $r = $fila->processar($site, FilaDeRepublicacao::LOTE_PAINEL, max(1.0, $limite - $this->agora()));
                if ($r['terminou_agora'] && $r['estado'] !== null) {
                    $this->auditar($sessao, $site, $r['estado']);
                }
                $estado = $r['estado'];
            } while ($estado !== null && $estado['status'] === FilaDeRepublicacao::ANDANDO && !$r['ocupado'] && $this->agora() < $limite);
        } catch (\Throwable) {
            return $this->exibir($sessao, $site, Pagina::alerta('danger', 'Não foi possível republicar agora. Tente de novo ou use "bin/pbl publicar".'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        if ($estado === null) {
            return $this->exibir($sessao, $site);
        }
        if ($estado['status'] === FilaDeRepublicacao::CONCLUIDO) {
            return new RedirectResponse(self::link($site) . '?ok=republicado', Response::HTTP_SEE_OTHER);
        }
        if ($estado['status'] === FilaDeRepublicacao::FALHOU) {
            return $this->exibir($sessao, $site, Pagina::alerta('danger', 'A republicação parou em ' . $estado['feitas'] . ' de ' . $estado['total'] . ' páginas: ' . $estado['erro']));
        }

        return $this->exibir($sessao, $site, Pagina::alerta('info', $estado['feitas'] . ' de ' . $estado['total'] . ' páginas tratadas; ainda há mais. Clique em "Continuar".'));
    }

    /** Endpoint do JavaScript: trata UM lote e devolve o andamento em JSON (`acao` = `iniciar` ou `continuar`). */
    public function lote(SessaoAtiva $sessao, Site $site, Request $requisicao): Response
    {
        $fila = $this->servicos->republicacao;
        try {
            if ($this->texto($requisicao, 'acao') === 'iniciar') {
                $fila->iniciar($site, 'painel');
            }
            $r = $fila->processar($site, FilaDeRepublicacao::LOTE_PAINEL, 8.0);
        } catch (\Throwable) {
            return $this->json(['erro' => 'Não foi possível republicar agora. Tente de novo.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        $estado = $r['estado'];
        if ($estado === null) {
            return $this->json(['erro' => 'Nenhuma republicação em andamento.'], Response::HTTP_CONFLICT);
        }
        if ($r['terminou_agora']) {
            $this->auditar($sessao, $site, $estado);
        }

        return $this->json([
            'status' => $estado['status'], 'ocupado' => $r['ocupado'], 'feitas' => $estado['feitas'], 'total' => $estado['total'],
            'publicadas' => $estado['publicadas'], 'falhas' => $estado['falhas'], 'segundos' => $this->servicos->republicacao->duracao($estado),
            'erro' => $estado['status'] === FilaDeRepublicacao::FALHOU ? $estado['erro'] : '',
        ]);
    }

    /** @param array<string, mixed> $dados */
    private function json(array $dados, int $status = Response::HTTP_OK): Response
    {
        $r = new JsonResponse($dados, $status);
        $r->headers->set('Cache-Control', 'no-store');

        return $r;
    }

    /** @param array<string, mixed> $estado */
    private function auditar(SessaoAtiva $sessao, Site $site, array $estado): void
    {
        $this->servicos->auditoria->registrar('site.republicar', 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, null, ['site_id' => $site->id, 'objetos' => $estado['feitas'], 'falhas' => $estado['falhas']]);
    }

    /** Resumo da última republicação concluída ou interrompida (para quem volta à tela depois). */
    private function resumo(array $e): string
    {
        $dur = $this->servicos->republicacao->duracao($e);
        if ($e['status'] === FilaDeRepublicacao::FALHOU) {
            return Pagina::alerta('danger', 'A última republicação parou em ' . $e['feitas'] . ' de ' . $e['total'] . ' páginas: ' . $e['erro']);
        }

        return '<p class="form-text mt-3">Última republicação (' . Pagina::h($e['origem']) . '): ' . $e['publicadas'] . ' página(s) publicada(s)'
            . ($e['falhas'] > 0 ? ', ' . $e['falhas'] . ' com falha' : '') . ' em ' . $dur . ' s.</p>';
    }

    private function agora(): float
    {
        return $this->microtime === null ? microtime(true) : ($this->microtime)();
    }
}
