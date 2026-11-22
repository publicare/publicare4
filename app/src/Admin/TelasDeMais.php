<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Mais": índice das telas administrativas que saíram do menu principal (ADR 0031): Contas, Tipos de conteúdo, Peles, Atualização,
 * Cópias de segurança e Tarefas agendadas. Quem não é administrador vê só "Tipos de conteúdo" (leitura, como antes no menu); as telas
 * de destino continuam conferindo a própria permissão por rota.
 * Uma lista de linhas com nome e frase, não uma grade de cartões.
 */
final class TelasDeMais
{
    public function __construct(
        private readonly Servicos $servicos,
        private readonly Csrf $csrf,
    ) {
    }

    public function exibir(SessaoAtiva $sessao): Response
    {
        $barra = Pagina::barra($sessao->usuario->nome, $this->csrf->daSessao($sessao->idHash), $sessao->usuario->administrador);
        $tipos = [Painel::PREFIXO . '/classes', 'Tipos de conteúdo', 'Os campos de cada tipo: página, notícia, evento e os que você criar.'];
        if (!$sessao->usuario->administrador) {
            return $this->resposta([$tipos], $barra);
        }
        $itens = [
            [Painel::PREFIXO . '/contas', 'Contas', 'Quem entra no painel e o que cada pessoa pode fazer.'],
            $tipos,
        ];
        foreach ($this->servicos->sites->todos() as $site) {
            $itens[] = [Painel::PREFIXO . '/site/' . $site->id . '/peles', 'Peles e modelos de página' . (count($this->servicos->sites->todos()) > 1 ? ': ' . $site->nome : ''), 'A aparência do site e os modelos de página de cada pele.'];
        }
        $itens[] = [Painel::PREFIXO . '/atualizacao', 'Atualização', 'Ver a versão instalada e atualizar o PBL4.'];
        $itens[] = [Painel::PREFIXO . '/copias', 'Cópias de segurança', 'Gerar, baixar e restaurar cópias do site.'];
        $itens[] = [Painel::PREFIXO . '/tarefas', 'Tarefas agendadas', 'O que roda sozinho: agendamentos, republicação e limpeza.'];

        return $this->resposta($itens, $barra);
    }

    /** @param list<array{0: string, 1: string, 2: string}> $itens */
    private function resposta(array $itens, string $barra): Response
    {
        $lista = '';
        foreach ($itens as [$href, $nome, $frase]) {
            $lista .= '<li><a href="' . Pagina::h($href) . '"><strong>' . Pagina::h($nome) . '</strong><span>' . Pagina::h($frase) . '</span></a></li>';
        }

        return Pagina::resposta('Mais', '<ul class="pbl-lista-mais">' . $lista . '</ul>', Response::HTTP_OK, false, $barra, [['Início', Painel::PREFIXO . '/'], ['Mais', null]], 'Áreas de administração do sistema.');
    }
}
