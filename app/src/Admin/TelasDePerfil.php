<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Perfil da própria conta (ADR 0037): trocar o nome e a senha. A troca de senha exige a senha atual (com o limite de tentativas do
 * modo elevado), aplica a política de senha, encerra as **outras** sessões e mantém a atual, e avisa por e-mail quando há envio.
 * Sempre age sobre a conta da sessão (nunca um id vindo da requisição). Todo POST confere origem e CSRF.
 */
final class TelasDePerfil
{
    use AuxiliaresDeTela;

    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
    ) {
    }

    /** @return array{0: string}|null */
    public static function rota(string $prefixo, string $caminho, string $metodo): ?array
    {
        return match (true) {
            $caminho === $prefixo . '/perfil' && $metodo === 'GET' => ['ver'],
            $caminho === $prefixo . '/perfil/senha' && $metodo === 'POST' => ['senha'],
            $caminho === $prefixo . '/perfil/nome' && $metodo === 'POST' => ['nome'],
            default => null,
        };
    }

    /** @param array{0: string} $rota */
    public function tratar(SessaoAtiva $sessao, array $rota, Request $requisicao): Response
    {
        if ($requisicao->getMethod() === 'POST' && !$this->postConfere($sessao, $requisicao)) {
            return $this->recusado();
        }

        return match ($rota[0]) {
            'ver' => $this->pagina($sessao, null, Response::HTTP_OK, $this->aviso($requisicao)),
            'senha' => $this->trocarSenha($sessao, $requisicao),
            default => $this->trocarNome($sessao, $requisicao),
        };
    }

    private function aviso(Request $requisicao): string
    {
        return match ($requisicao->query->all()['ok'] ?? null) {
            'senha' => Pagina::alerta('success', 'Senha trocada. As outras sessões foram encerradas.'),
            'nome' => Pagina::alerta('success', 'Nome salvo.'),
            default => '',
        };
    }

    private function pagina(SessaoAtiva $sessao, ?string $erro, int $status, string $aviso = '', string $nome = ''): Response
    {
        $u = $sessao->usuario;
        $csrf = $this->campoCsrf($sessao);
        $ajuda = 'No mínimo 10 caracteres, nada óbvio (como "senha123456"). Tamanho vale mais que símbolos.';
        $corpo = $aviso . $this->erro($erro)
            . '<p>Login: <code>' . Pagina::h($u->login) . '</code> · E-mail: ' . Pagina::h($u->email) . '</p>'
            . '<h2 class="h5">Nome</h2><form method="post" action="' . Painel::PREFIXO . '/perfil/nome">' . $csrf
            . Pagina::campo('campo-nome', 'nome', 'Nome', $nome !== '' ? $nome : $u->nome, ['maxlength' => 200, 'obrigatorio' => true])
            . '<div class="pbl-acoes"><button type="submit" class="btn btn-primary">Salvar nome</button></div></form>'
            . '<hr class="my-4"><h2 class="h5">Trocar a senha</h2><form method="post" action="' . Painel::PREFIXO . '/perfil/senha">' . $csrf
            . Pagina::campo('campo-senha-atual', 'senha_atual', 'Senha atual', '', ['tipo' => 'password', 'autocomplete' => 'current-password', 'maxlength' => 1024, 'obrigatorio' => true])
            . Pagina::campo('campo-senha', 'senha', 'Nova senha', '', ['tipo' => 'password', 'autocomplete' => 'new-password', 'maxlength' => 1024, 'obrigatorio' => true, 'ajuda' => Pagina::h($ajuda)])
            . Pagina::campo('campo-senha2', 'senha2', 'Repita a nova senha', '', ['tipo' => 'password', 'autocomplete' => 'new-password', 'maxlength' => 1024, 'obrigatorio' => true])
            . '<div class="pbl-acoes"><button type="submit" class="btn btn-warning">Trocar senha</button></div></form>';

        return Pagina::resposta('Meu perfil', $corpo, $status, false, $this->barra($sessao), [['Mais', Painel::PREFIXO . '/mais'], ['Meu perfil', null]]);
    }

    private function trocarNome(SessaoAtiva $sessao, Request $requisicao): Response
    {
        $nome = $this->texto($requisicao, 'nome');
        if ($nome === null) {
            return $this->incompleto($sessao);
        }
        $erro = $this->erroDeTexto($nome, 200, 'O nome');
        if ($erro === null) {
            try {
                $this->servicos->usuarios->atualizar($sessao->usuario->id, $nome, $sessao->usuario->email, 'usuario:' . $sessao->usuario->id);

                return new RedirectResponse(Painel::PREFIXO . '/perfil?ok=nome', Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException $e) {
                $erro = $this->mensagemDoRepositorio($e);
            }
        }

        return $this->pagina($sessao, $erro, Response::HTTP_UNPROCESSABLE_ENTITY, '', $nome);
    }

    private function trocarSenha(SessaoAtiva $sessao, Request $requisicao): Response
    {
        $senha = $this->texto($requisicao, 'senha');
        $repita = $this->texto($requisicao, 'senha2');
        if ($senha === null || $repita === null || $this->texto($requisicao, 'senha_atual') === null) {
            return $this->incompleto($sessao);
        }
        $falha = $this->exigirReautenticacao($sessao, $requisicao, 'perfil', true, 'senha_atual');
        if ($falha !== null) {
            return $this->aplicarFalhaDeReautenticacao($this->pagina($sessao, $falha['mensagem'], Response::HTTP_OK), $falha);
        }
        if ($senha !== $repita) {
            return $this->pagina($sessao, 'As duas senhas não são iguais.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $u = $sessao->usuario;
        try {
            $this->servicos->usuarios->definirSenha($u->id, $senha, 'usuario:' . $u->id, $sessao->idHash);
        } catch (\InvalidArgumentException $e) {
            return $this->pagina($sessao, $this->mensagemDoRepositorio($e), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->servicos->tokensDeConta()->invalidar($u->id, \Pbl\Acesso\TokenDeConta::SENHA);
        if ($this->servicos->emailsDeConta()->habilitado()) {
            try {
                $this->servicos->emailsDeConta()->senhaAlterada($u);
            } catch (\InvalidArgumentException | \RuntimeException) {
                $this->servicos->auditoria->registrar('recuperacao.falha', 'sistema', $u->id);
            }
        }

        return new RedirectResponse(Painel::PREFIXO . '/perfil?ok=senha', Response::HTTP_SEE_OTHER);
    }
}
