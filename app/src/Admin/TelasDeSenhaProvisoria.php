<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Acesso\TokenDeConta;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tela "Defina sua nova senha" (ADR 0048): quem entrou com senha provisória só chega aqui (e ao "Sair"); o `Painel` barra o resto.
 * Reusa a reautenticação (senha atual, com limite de tentativas), a política de senha e `definirSenha`, que zera a marca,
 * encerra as outras sessões e mantém a atual. Funciona sem JavaScript.
 */
final class TelasDeSenhaProvisoria
{
    use AuxiliaresDeTela;

    public const ROTA = Painel::PREFIXO . '/trocar-senha';
    public const AVISO_TROCADA = 'Senha trocada. Você já pode usar o painel.';

    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
    ) {
    }

    public static function atende(string $caminho, string $metodo): bool
    {
        return $caminho === self::ROTA && in_array($metodo, ['GET', 'POST'], true);
    }

    public function tratar(SessaoAtiva $sessao, Request $requisicao): Response
    {
        if (!$sessao->usuario->senhaProvisoria) {
            return new RedirectResponse(Painel::PREFIXO . '/', Response::HTTP_SEE_OTHER);
        }
        if ($requisicao->getMethod() === 'POST') {
            return $this->postConfere($sessao, $requisicao) ? $this->trocar($sessao, $requisicao) : $this->recusado();
        }

        return $this->pagina($sessao, null, Response::HTTP_OK);
    }

    private function pagina(SessaoAtiva $sessao, ?string $erro, int $status): Response
    {
        $csrf = $this->campoCsrf($sessao);
        $corpo = ($erro === null ? '' : Pagina::alerta('danger', $erro, 'erro-formulario'))
            . '<p>Sua senha atual é provisória. Para continuar, escolha uma senha só sua.</p>'
            . '<form method="post" action="' . self::ROTA . '"' . ($erro === null ? '' : ' aria-describedby="erro-formulario"') . '>' . $csrf
            . Pagina::campo('campo-senha-atual', 'senha_atual', 'Senha atual (provisória)', '', ['tipo' => 'password', 'autocomplete' => 'current-password', 'maxlength' => 1024, 'obrigatorio' => true, 'autofocus' => true])
            . Pagina::campo('campo-senha', 'senha', 'Nova senha', '', ['tipo' => 'password', 'autocomplete' => 'new-password', 'maxlength' => 1024, 'obrigatorio' => true, 'ajuda' => Pagina::h(Pagina::AJUDA_DE_SENHA)])
            . Pagina::campo('campo-senha2', 'senha2', 'Repita a nova senha', '', ['tipo' => 'password', 'autocomplete' => 'new-password', 'maxlength' => 1024, 'obrigatorio' => true])
            . '<div class="pbl-acoes"><button type="submit" class="btn btn-primary">Trocar senha</button></div></form>';
        $sair = '<form method="post" action="' . Painel::PREFIXO . '/sair" class="mt-3">' . $csrf
            . '<button type="submit" class="btn btn-outline-secondary">Sair</button></form>';

        $resposta = Pagina::resposta('Defina sua nova senha', $corpo . $sair, $status, false, '');
        $resposta->headers->set('Cache-Control', 'no-store');

        return $resposta;
    }

    private function trocar(SessaoAtiva $sessao, Request $requisicao): Response
    {
        $atual = $this->texto($requisicao, 'senha_atual');
        $senha = $this->texto($requisicao, 'senha');
        $repita = $this->texto($requisicao, 'senha2');
        if ($atual === null || $senha === null || $repita === null) {
            return $this->incompleto($sessao);
        }
        $falha = $this->exigirReautenticacao($sessao, $requisicao, 'trocar', true, 'senha_atual');
        if ($falha !== null) {
            return $this->aplicarFalhaDeReautenticacao($this->pagina($sessao, $falha['mensagem'], Response::HTTP_OK), $falha);
        }
        if ($senha !== $repita) {
            return $this->pagina($sessao, 'As duas senhas não são iguais.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (hash_equals($atual, $senha)) {
            return $this->pagina($sessao, 'A nova senha precisa ser diferente da atual.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $u = $sessao->usuario;
        try {
            $this->servicos->usuarios->definirSenha($u->id, $senha, 'usuario:' . $u->id, $sessao->idHash);
        } catch (\InvalidArgumentException $e) {
            return $this->pagina($sessao, $this->mensagemDoRepositorio($e), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->servicos->auditoria->registrar('conta.senha_provisoria_trocada', 'usuario:' . $u->id, $u->id);
        $this->servicos->tokensDeConta()->invalidar($u->id, TokenDeConta::SENHA);
        if ($this->servicos->emailsDeConta()->habilitado()) {
            try {
                $this->servicos->emailsDeConta()->senhaAlterada($u);
            } catch (\InvalidArgumentException | \RuntimeException) {
                $this->servicos->auditoria->registrar('recuperacao.falha', 'sistema', $u->id);
            }
        }

        return new RedirectResponse(Painel::PREFIXO . '/?ok=senha-nova', Response::HTTP_SEE_OTHER);
    }
}
