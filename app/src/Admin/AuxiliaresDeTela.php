<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\SessaoAtiva;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Peças comuns das telas de contas e de domínios (ADR 0012): conferência de origem e CSRF, campo oculto do token, barra, mensagens
 * e leitura de texto da requisição. Quem usa declara `$rede` (`Rede`) e `$csrf` (`Csrf`).
 */
trait AuxiliaresDeTela
{
    private function postConfere(SessaoAtiva $sessao, Request $requisicao): bool
    {
        $token = $requisicao->request->all()['_csrf'] ?? null;

        return $this->rede->origemConfere($requisicao) && is_string($token) && $this->csrf->conferirSessao($sessao->idHash, $token);
    }

    private function recusado(): Response
    {
        return Pagina::resposta('Pedido recusado', '<p>O pedido não pôde ser confirmado. Volte, recarregue a página e tente de novo.</p>', Response::HTTP_FORBIDDEN);
    }

    private function incompleto(SessaoAtiva $sessao): Response
    {
        return Pagina::resposta('Pedido inválido', '<p>O formulário chegou incompleto. Volte, recarregue a página e tente de novo.</p>', Response::HTTP_BAD_REQUEST, false, $this->barra($sessao));
    }

    private function campoCsrf(SessaoAtiva $sessao): string
    {
        return '<input type="hidden" name="_csrf" value="' . Pagina::h($this->csrf->daSessao($sessao->idHash)) . '">';
    }

    private function barra(SessaoAtiva $sessao): string
    {
        return Pagina::barra($sessao->usuario->nome, $this->csrf->daSessao($sessao->idHash), $sessao->usuario->administrador);
    }

    private function erro(?string $erro): string
    {
        return $erro === null ? '' : '<div id="erro-formulario" class="alert alert-danger" role="alert">' . Pagina::h($erro) . '</div>';
    }

    /** Campo de texto do POST (só texto: lista ou outro tipo vira null). */
    private function texto(Request $requisicao, string $nome): ?string
    {
        $v = $requisicao->request->all()[$nome] ?? null;

        return is_string($v) ? $v : null;
    }

    /** Texto de uma linha: UTF-8 válido, sem caracteres de controle, até `$max` caracteres. */
    private function erroDeTexto(string $texto, int $max, string $campo): ?string
    {
        if (!mb_check_encoding($texto, 'UTF-8')) {
            return $campo . ' tem caracteres inválidos.';
        }
        if (mb_strlen($texto) > $max) {
            return $campo . ' pode ter até ' . $max . ' caracteres.';
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $texto) === 1) {
            return $campo . ' tem caracteres de controle, que não são permitidos.';
        }

        return null;
    }

    /** As mensagens dos repositórios já são em português e não trazem dado secreto; só ganham ponto final. */
    private function mensagemDoRepositorio(\InvalidArgumentException $e): string
    {
        return rtrim($e->getMessage(), '.') . '.';
    }

    /** Inteiro positivo de até 10 dígitos, sem zero à esquerda, ou null. */
    private function numero(mixed $valor): ?int
    {
        return is_string($valor) && preg_match('/^[1-9][0-9]{0,9}$/D', $valor) === 1 ? (int) $valor : null;
    }
}
