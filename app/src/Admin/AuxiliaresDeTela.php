<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\SessaoAtiva;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Peças comuns das telas administrativas (ADR 0012): conferência de origem e CSRF, campo oculto do token, barra, mensagens,
 * leitura de texto da requisição e o "modo elevado" (ADR 0011, fase 5h) para ações sensíveis. Quem usa declara `$rede` (`Rede`),
 * `$csrf` (`Csrf`) e `$servicos` (`Servicos`).
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
        return $erro === null ? '' : Pagina::alerta('danger', $erro, 'erro-formulario');
    }

    // Componentes de formulário (HTML escapado em Pagina): usados pelas telas para não repetir marcação.

    /**
     * @param array<string, mixed> $o ver `Pagina::campo`
     */
    private function campo(string $id, string $nome, string $rotulo, string $valor = '', array $o = []): string
    {
        return Pagina::campo($id, $nome, $rotulo, $valor, $o);
    }

    private function checkbox(string $id, string $nome, string $rotulo, bool $marcado, string $ajuda = '', string $valor = '1', bool $rotuloHtml = false): string
    {
        return Pagina::checkbox($id, $nome, $rotulo, $marcado, $ajuda, $valor, $rotuloHtml);
    }

    private function card(string $titulo, string $corpo, string $ajuda = '', string $tag = 'fieldset', string $classe = ''): string
    {
        return Pagina::card($titulo, $corpo, $ajuda, $tag, $classe);
    }

    private function alerta(string $tipo, string $texto, string $id = '', bool $html = false): string
    {
        return Pagina::alerta($tipo, $texto, $id, $html);
    }

    /**
     * @param list<array{0: string, 1: string}> $secundarios
     * @param array{0: string, 1: string}|null $destrutivo
     */
    private function acoes(string $primario, array $secundarios = [], ?array $destrutivo = null, string $classePrimario = 'btn-primary'): string
    {
        return Pagina::acoes($primario, $secundarios, $destrutivo, $classePrimario);
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

    // ---------------------------------------------------------------- modo elevado (ADR 0011/0012, fase 5h)

    /**
     * Exige a confirmação de senha de uma ação sensível (ou aproveita o "modo elevado" aberto há pouco pela sessão).
     * Quem usa declara `$servicos` (`Servicos`). `$sempreExigir` força a conferência mesmo com o modo já elevado
     * (as telas de atualizar/reverter, que trocam o código em execução). `$campo` é o nome do campo do POST; o
     * padrão `senha` serve quase toda tela, exceto onde o formulário já usa esse nome para outra coisa (a senha
     * nova de uma conta, por exemplo), caso em que a tela passa outro nome (e o mesmo em `campoSenha`).
     *
     * @return array{mensagem: string, status: int, retryAfter: int|null}|null null = pode seguir com a ação
     */
    private function exigirReautenticacao(SessaoAtiva $sessao, Request $requisicao, string $prefixoAcao, bool $sempreExigir = false, string $campo = 'senha'): ?array
    {
        $modo = $this->servicos->modoElevado();
        if (!$sempreExigir && $modo->ativo($sessao)) {
            return null;
        }
        $senha = $this->texto($requisicao, $campo);
        if ($senha === null) {
            return ['mensagem' => 'Confirme sua senha para continuar.', 'status' => Response::HTTP_UNPROCESSABLE_ENTITY, 'retryAfter' => null];
        }

        return $modo->confirmar($sessao, $senha, $this->rede->ip($requisicao), $prefixoAcao);
    }

    /**
     * Aplica num `Response` o status e o `Retry-After` de uma falha de `exigirReautenticacao`.
     *
     * @param array{mensagem: string, status: int, retryAfter: int|null} $falha
     */
    private function aplicarFalhaDeReautenticacao(Response $resposta, array $falha): Response
    {
        $resposta->setStatusCode($falha['status']);
        if ($falha['retryAfter'] !== null) {
            $resposta->headers->set('Retry-After', (string) $falha['retryAfter']);
        }

        return $resposta;
    }

    /**
     * Campo de senha para confirmar uma ação sensível, pronto para entrar num `<form>`; vazio (com uma nota) se a
     * sessão já está no "modo elevado" e não precisa da senha de novo por alguns minutos. `$campo` precisa ser o
     * mesmo nome passado para `exigirReautenticacao`.
     */
    private function campoSenha(SessaoAtiva $sessao, string $id = 'campo-senha', string $campo = 'senha'): string
    {
        if ($this->servicos->modoElevado()->ativo($sessao)) {
            return '<p class="small text-body-secondary">Confirmado há pouco: não precisa da senha de novo por alguns minutos.</p>';
        }

        return '<div class="mb-3"><label for="' . $id . '" class="form-label">Sua senha, para confirmar</label>'
            . '<input id="' . $id . '" class="form-control" name="' . $campo . '" type="password" autocomplete="current-password" maxlength="1024" required></div>';
    }
}
