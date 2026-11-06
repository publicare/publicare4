<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Acao;
use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Conteudo\Objeto;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Objetos vencidos" (ADR 0026): conteúdo publicado cuja data de validade já passou, ou seja, que saiu do ar sozinho. Mostra só o que a
 * pessoa pode ver (o papel vale para a subárvore), de um site ou seção. Prorrogar e despublicar são a tela de estado do objeto
 * (`/objeto/{id}/estado`, que já edita estado e datas), oferecida só a quem pode publicar; nada aqui grava.
 */
final class TelasDeVencidos
{
    use AuxiliaresDeTela;
    use EscopoDeSecoes;

    public const LIMITE = 200;

    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
    ) {
    }

    /** @return array{0: string}|null */
    public static function rota(string $prefixo, string $caminho, string $metodo): ?array
    {
        return $caminho === $prefixo . '/vencidos' && $metodo === 'GET' ? ['lista'] : null;
    }

    /** @param array{0: string} $rota */
    public function tratar(SessaoAtiva $sessao, array $rota, Request $r): Response
    {
        $agora = gmdate('c');
        $pedida = $this->secaoPedida($sessao, $r);
        $achados = []; // id => Objeto
        $total = 0;
        foreach ($this->secoesDoEscopo($sessao, $r) as $secao) {
            $total += $this->servicos->objetos->contarVencidosDaSubarvore($secao->siteId, $secao->caminho, $agora);
            foreach ($this->servicos->objetos->vencidosDaSubarvore($secao->siteId, $secao->caminho, $agora, self::LIMITE) as $o) {
                if ($this->servicos->permissoes->pode($sessao->usuario, Acao::Ver, $o)) {
                    $achados[$o->id] = $o;
                }
            }
        }
        uasort($achados, static fn (Objeto $a, Objeto $b): int => [$a->dataValidade, $a->id] <=> [$b->dataValidade, $b->id]);
        $achados = array_slice($achados, 0, self::LIMITE, true);

        $corpo = $this->seletorDeSecao($sessao, Painel::PREFIXO . '/vencidos', $pedida);
        if ($achados === []) {
            $corpo .= Pagina::alerta('info', 'Nenhum conteúdo publicado está com a validade vencida' . ($pedida === null ? '.' : ' nesta seção.'));
        } else {
            $corpo .= '<div class="table-responsive"><table class="table table-sm align-middle"><caption class="visually-hidden">Conteúdo vencido</caption>'
                . '<thead><tr><th scope="col">Título</th><th scope="col">Tipo</th><th scope="col">Site</th><th scope="col">Venceu em</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead><tbody>';
            foreach ($achados as $o) {
                $corpo .= $this->linha($sessao, $o);
            }
            $corpo .= '</tbody></table></div>';
            if ($total > count($achados)) {
                $corpo .= '<p class="text-body-secondary">Mostrando os ' . count($achados) . ' que venceram há mais tempo, de ' . $total . '. Escolha uma seção para ver o restante.</p>';
            }
        }

        return Pagina::resposta('Objetos vencidos', $corpo, Response::HTTP_OK, false, $this->barra($sessao), [], 'Conteúdo publicado cuja <strong>data de validade já passou</strong>: saiu do ar sozinho e aguarda uma decisão (prorrogar a validade ou despublicar).');
    }

    private function linha(SessaoAtiva $sessao, Objeto $o): string
    {
        $base = Painel::PREFIXO . '/objeto/' . $o->id;
        $acoes = '<a class="btn btn-sm btn-outline-secondary" href="' . $base . '">Abrir<span class="visually-hidden">: ' . Pagina::h($o->titulo) . '</span></a>';
        if ($this->servicos->permissoes->pode($sessao->usuario, Acao::Editar, $o)) {
            $acoes .= ' <a class="btn btn-sm btn-outline-secondary" href="' . $base . '/editar">Editar<span class="visually-hidden">: ' . Pagina::h($o->titulo) . '</span></a>';
        }
        if ($this->servicos->permissoes->pode($sessao->usuario, Acao::Publicar, $o)) {
            $acoes .= ' <a class="btn btn-sm btn-outline-primary" href="' . $base . '/estado">Prorrogar ou despublicar<span class="visually-hidden">: ' . Pagina::h($o->titulo) . '</span></a>';
        }
        $site = $this->servicos->sites->porId($o->siteId);

        return '<tr><td><a href="' . $base . '">' . Pagina::h($o->titulo) . '</a></td>'
            . '<td>' . Pagina::h($this->servicos->classes->porId($o->classeId)->nome ?? '?') . '</td>'
            . '<td>' . Pagina::h($site->nome ?? '—') . '</td>'
            . '<td>' . Pagina::h($this->dataBrasilia($o->dataValidade)) . '</td><td class="text-nowrap">' . $acoes . '</td></tr>';
    }
}
