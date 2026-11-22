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
            $corpo .= '<ul class="pbl-lista-conteudo" aria-label="Conteúdo vencido">';
            foreach ($achados as $o) {
                $corpo .= $this->linha($sessao, $o);
            }
            $corpo .= '</ul>';
            if ($total > count($achados)) {
                $corpo .= '<p class="text-body-secondary">Mostrando os ' . count($achados) . ' que venceram há mais tempo, de ' . $total . '. Escolha uma seção para ver o restante.</p>';
            }
        }

        return Pagina::resposta('Conteúdos vencidos', $corpo, Response::HTTP_OK, false, $this->barra($sessao), [], 'Conteúdo publicado cuja <strong>data de validade já passou</strong>: saiu do ar sozinho e aguarda uma decisão (prorrogar a validade ou despublicar).');
    }

    private function linha(SessaoAtiva $sessao, Objeto $o): string
    {
        $base = Painel::PREFIXO . '/objeto/' . $o->id;
        $acoes = [];
        if ($this->servicos->permissoes->pode($sessao->usuario, Acao::Editar, $o)) {
            $acoes[] = AcaoDeLinha::link('Editar', 'editar', AcaoDeLinha::PRIMARIA, $base . '/editar');
        }
        if ($this->servicos->permissoes->pode($sessao->usuario, Acao::Publicar, $o)) {
            $acoes[] = AcaoDeLinha::link('Prorrogar ou despublicar', 'despublicar', AcaoDeLinha::ESTADO, $base . '/estado');
        }
        $acoes[] = AcaoDeLinha::link('Abrir', 'abrir', AcaoDeLinha::MENU, $base);
        $site = $this->servicos->sites->porId($o->siteId);
        $classe = $this->servicos->classes->porId($o->classeId);
        $datas = DadosDaLinha::datas((string) $o->dataValidade, 'America/Sao_Paulo');

        return LinhaDeConteudo::renderizar(new DadosDaLinha(
            $o->id, $o->titulo, $base, $classe, $classe->nome ?? '?', '<span class="badge text-bg-warning">vencido</span>',
            $datas[0] ?? '', $datas[1] ?? '', $datas[2] ?? '', extras: $site === null ? [] : [$site->nome], rotuloDaData: 'Venceu em',
        ), $acoes);
    }
}
