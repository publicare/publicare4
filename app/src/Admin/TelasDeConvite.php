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
 * Convite de usuário (ADR 0038): o administrador informa login, nome e e-mail e a própria pessoa define a senha por link de uso
 * único. Reenviar invalida o link anterior; cancelar apaga a conta que nunca aceitou. Só administrador global, com CSRF, origem e
 * modo elevado em todo POST. Sem e-mail configurado a tela avisa e não oferece o formulário (o operador usa `bin/pbl conta-convidar`).
 */
final class TelasDeConvite
{
    use AuxiliaresDeTela;

    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
    ) {
    }

    /** @return array{0: string, 1: int|null}|null */
    public static function rota(string $prefixo, string $caminho, string $metodo): ?array
    {
        if ($caminho === $prefixo . '/contas/convidar') {
            return match ($metodo) {
                'GET' => ['formulario', null],
                'POST' => ['convidar', null],
                default => null,
            };
        }
        if ($metodo === 'POST' && preg_match('#^' . preg_quote($prefixo, '#') . '/conta/([1-9][0-9]{0,9})/(reenviar-convite|cancelar-convite)$#D', $caminho, $m) === 1) {
            return [$m[2] === 'reenviar-convite' ? 'reenviar' : 'cancelar', (int) $m[1]];
        }

        return null;
    }

    /** @param array{0: string, 1: int|null} $rota */
    public function tratar(SessaoAtiva $sessao, array $rota, Request $requisicao): Response
    {
        [$tela, $id] = $rota;
        $post = $requisicao->getMethod() === 'POST';
        if ($post && !$this->postConfere($sessao, $requisicao)) {
            return $this->recusado();
        }
        if (!$this->servicos->permissoes->podeAdministrarContas($sessao->usuario)) {
            if ($post) {
                $this->servicos->auditoria->registrar('conta.negado', 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, null, $id === null ? [] : ['conta_id' => $id]);
            }

            return Pagina::resposta('Sem acesso', Pagina::alerta('warning', 'Só administradores podem convidar pessoas.'), Response::HTTP_FORBIDDEN, false, $this->barra($sessao));
        }

        return match ($tela) {
            'formulario' => $this->formulario($sessao, [], null),
            'convidar' => $this->convidar($sessao, $requisicao),
            default => $this->reenviarOuCancelar($sessao, (int) $id, $tela === 'cancelar', $requisicao),
        };
    }

    /** @param array<string, string> $v */
    private function formulario(SessaoAtiva $sessao, array $v, ?string $erro, int $status = Response::HTTP_OK): Response
    {
        $migalhas = [['Início', Painel::PREFIXO . '/'], ['Contas', Painel::PREFIXO . '/contas'], ['Convidar', null]];
        if (!$this->servicos->emailsDeConta()->habilitado()) {
            return Pagina::resposta('Convidar por e-mail', Pagina::alerta('warning', 'O envio de e-mail não está configurado (ou falta o domínio principal do site), então não dá para convidar por e-mail.')
                . '<p><a href="' . Painel::PREFIXO . '/email">Configurar o e-mail</a> · <a href="' . Painel::PREFIXO . '/contas/nova">Criar a conta com senha</a></p>'
                . '<p class="small text-body-secondary">Quem opera o servidor também pode gerar o link: <code>bin/pbl conta-convidar</code> com <code>--sem-envio</code>.</p>', Response::HTTP_OK, false, $this->barra($sessao), $migalhas);
        }
        $corpo = $this->erro($erro)
            . '<form method="post" action="' . Painel::PREFIXO . '/contas/convidar"' . ($erro === null ? '' : ' aria-describedby="erro-formulario"') . '>' . $this->campoCsrf($sessao)
            . Pagina::campo('campo-login', 'login', 'Login', $v['login'] ?? '', ['autocomplete' => 'off', 'maxlength' => 64, 'obrigatorio' => true, 'ajuda' => '3 a 64 letras minúsculas, números, ponto, hífen ou sublinhado. Não muda depois.'])
            . Pagina::campo('campo-nome', 'nome', 'Nome', $v['nome'] ?? '', ['maxlength' => 200, 'obrigatorio' => true])
            . Pagina::campo('campo-email', 'email', 'E-mail', $v['email'] ?? '', ['tipo' => 'email', 'maxlength' => 255, 'obrigatorio' => true, 'ajuda' => 'O link para definir a senha vai para este endereço e vale 72 horas.'])
            . '<div class="form-check mb-3"><input id="campo-admin" class="form-check-input" type="checkbox" name="administrador" value="1"' . (($v['administrador'] ?? '') === '1' ? ' checked' : '') . '>'
            . '<label for="campo-admin" class="form-check-label">Administrador (vê e altera tudo, em todos os sites)</label></div>'
            . $this->campoSenha($sessao, 'campo-senha-convidar')
            . '<div class="pbl-acoes"><button type="submit" class="btn btn-primary">Enviar convite</button><a class="btn btn-outline-secondary" href="' . Painel::PREFIXO . '/contas">Cancelar</a></div></form>';

        return Pagina::resposta('Convidar por e-mail', $corpo, $status, false, $this->barra($sessao), $migalhas, 'A pessoa recebe um link e escolhe a própria senha; você não vê nem define a senha.');
    }

    private function convidar(SessaoAtiva $sessao, Request $requisicao): Response
    {
        $login = $this->texto($requisicao, 'login');
        $nome = $this->texto($requisicao, 'nome');
        $email = $this->texto($requisicao, 'email');
        if ($login === null || $nome === null || $email === null) {
            return $this->incompleto($sessao);
        }
        $admin = $this->texto($requisicao, 'administrador') === '1';
        $v = ['login' => $login, 'nome' => $nome, 'email' => $email, 'administrador' => $admin ? '1' : ''];
        $falha = $this->exigirReautenticacao($sessao, $requisicao, 'conta');
        if ($falha !== null) {
            return $this->aplicarFalhaDeReautenticacao($this->formulario($sessao, $v, $falha['mensagem']), $falha);
        }
        $erro = $this->erroDeTexto($login, 64, 'O login') ?? $this->erroDeTexto($nome, 200, 'O nome') ?? $this->erroDeTexto($email, 255, 'O e-mail');
        if ($erro === null) {
            try {
                $this->servicos->convites()->convidar($login, $nome, $email, $admin, 'usuario:' . $sessao->usuario->id);

                return new RedirectResponse(Painel::PREFIXO . '/contas?ok=convidada', Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException $e) {
                $erro = $this->mensagemDoRepositorio($e);
            } catch (\RuntimeException) {
                $erro = 'A conta foi criada, mas o e-mail não pôde ser enviado. Abra a conta e use "Reenviar convite".';
            }
        }

        return $this->formulario($sessao, $v, $erro, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function reenviarOuCancelar(SessaoAtiva $sessao, int $id, bool $cancelar, Request $requisicao): Response
    {
        $alvo = $this->servicos->usuarios->porId($id);
        if ($alvo === null || !$this->servicos->usuarios->convitePendente($id)) {
            return Pagina::resposta('Não encontrado', Pagina::alerta('warning', 'Não há convite pendente para esta conta.') . '<p><a href="' . Painel::PREFIXO . '/contas">Voltar às contas</a></p>', Response::HTTP_NOT_FOUND, false, $this->barra($sessao));
        }
        $falha = $this->exigirReautenticacao($sessao, $requisicao, 'conta');
        if ($falha !== null) {
            return $this->aplicarFalhaDeReautenticacao(Pagina::resposta('Confirme a senha', Pagina::alerta('danger', $falha['mensagem']) . '<p><a href="' . Painel::PREFIXO . '/conta/' . $id . '">Voltar à conta</a></p>', Response::HTTP_OK, false, $this->barra($sessao)), $falha);
        }
        $ator = 'usuario:' . $sessao->usuario->id;
        if ($cancelar) {
            $this->servicos->convites()->cancelar($id, $ator);

            return new RedirectResponse(Painel::PREFIXO . '/contas?ok=convite-cancelado', Response::HTTP_SEE_OTHER);
        }
        try {
            $this->servicos->convites()->reenviar($id, $ator);
        } catch (\InvalidArgumentException | \RuntimeException) {
            return Pagina::resposta('Convite não enviado', Pagina::alerta('danger', 'Não foi possível enviar o e-mail agora. Confira a configuração de e-mail e tente de novo.') . '<p><a href="' . Painel::PREFIXO . '/conta/' . $id . '">Voltar à conta</a></p>', Response::HTTP_UNPROCESSABLE_ENTITY, false, $this->barra($sessao));
        }

        return new RedirectResponse(Painel::PREFIXO . '/conta/' . $id . '?ok=convite-reenviado', Response::HTTP_SEE_OTHER);
    }
}
