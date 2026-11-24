<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\RecuperacaoDeAcesso;
use Pbl\Acesso\Rede;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telas **sem sessão** de recuperação de acesso (ADR 0035 e 0036): "esqueci a senha", "esqueci o usuário", definir nova senha
 * pelo link do e-mail e aceitar um convite. Todo POST confere origem e o CSRF pré-sessão do login. O GET de um link com token só
 * mostra o formulário e **nunca consome** o token (scanner de e-mail); quem consome é o POST. Os pedidos de "esqueci" respondem
 * sempre a mesma página, exista a conta ou não (a busca e o envio rodam depois, na fila). Sem script nem `style` inline (ADR 0022).
 */
final class TelasPublicasDeConta
{
    private const TOKEN = '[0-9a-f]{64}';

    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
    ) {
    }

    /** @return array{0: string, 1: string}|null [tela, token ('' se não há)] */
    public static function rota(string $prefixo, string $caminho, string $metodo): ?array
    {
        if ($metodo !== 'GET' && $metodo !== 'POST') {
            return null;
        }
        if ($caminho === $prefixo . '/esqueci') {
            return ['esqueci-senha', ''];
        }
        if ($caminho === $prefixo . '/esqueci-usuario') {
            return ['esqueci-usuario', ''];
        }
        if (preg_match('#^' . preg_quote($prefixo, '#') . '/(redefinir-senha|convite)/(' . self::TOKEN . ')$#D', $caminho, $m) === 1) {
            return [$m[1], $m[2]];
        }

        return null;
    }

    /** @param array{0: string, 1: string} $rota */
    public function tratar(array $rota, Request $requisicao): Response
    {
        [$tela, $token] = $rota;
        $post = $requisicao->getMethod() === 'POST';
        if ($post && !$this->postConfere($requisicao)) {
            return Pagina::resposta('Pedido recusado', Pagina::alerta('danger', 'O pedido não pôde ser confirmado. Volte, recarregue a página e tente de novo.'), Response::HTTP_FORBIDDEN);
        }

        return match ($tela) {
            'esqueci-senha' => $post ? $this->pedir(RecuperacaoDeAcesso::SENHA, $requisicao) : $this->formularioDePedido(RecuperacaoDeAcesso::SENHA),
            'esqueci-usuario' => $post ? $this->pedir(RecuperacaoDeAcesso::USUARIO, $requisicao) : $this->formularioDePedido(RecuperacaoDeAcesso::USUARIO),
            'redefinir-senha' => $post ? $this->definir($token, false, $requisicao) : $this->formularioDeSenha($token, false),
            default => $post ? $this->definir($token, true, $requisicao) : $this->formularioDeSenha($token, true),
        };
    }

    private function postConfere(Request $requisicao): bool
    {
        $token = $requisicao->request->all()['_csrf'] ?? null;

        return $this->rede->origemConfere($requisicao) && is_string($token) && $this->csrf->conferirEntrar($token);
    }

    // ---------------------------------------------------------------- esqueci a senha / o usuário

    private function formularioDePedido(string $tipo): Response
    {
        $senha = $tipo === RecuperacaoDeAcesso::SENHA;
        $titulo = $senha ? 'Esqueci a senha' : 'Esqueci o usuário';
        $voltar = '<p class="mt-3"><a href="' . Painel::PREFIXO . '/entrar">Voltar para entrar</a></p>';
        if (!$this->servicos->recuperacao()->habilitado()) {
            return Pagina::resposta($titulo, Pagina::alerta('warning', 'A recuperação por e-mail não está ativada neste site. Peça a um administrador para redefinir o acesso, ou a quem cuida do servidor (bin/pbl conta-recuperar).') . $voltar);
        }
        $campo = $senha
            ? Pagina::campo('campo-identificador', 'identificador', 'Login ou e-mail', '', ['autocomplete' => 'username', 'maxlength' => 255, 'obrigatorio' => true, 'autofocus' => true])
            : Pagina::campo('campo-identificador', 'identificador', 'E-mail cadastrado', '', ['tipo' => 'email', 'autocomplete' => 'email', 'maxlength' => 255, 'obrigatorio' => true, 'autofocus' => true]);

        return Pagina::resposta($titulo, '<form method="post" action="' . Painel::PREFIXO . ($senha ? '/esqueci' : '/esqueci-usuario') . '" class="pbl-entrar">'
            . '<input type="hidden" name="_csrf" value="' . Pagina::h($this->csrf->paraEntrar()) . '">' . $campo
            . Pagina::acoes($senha ? 'Enviar link' : 'Enviar usuário') . '</form>' . $voltar, Response::HTTP_OK, false, '', [],
            $senha ? 'Informe o login ou o e-mail da conta. Se existir, enviamos um link para escolher uma nova senha.' : 'Informe o e-mail da conta. Se existir, enviamos o seu usuário.');
    }

    /** Resposta idêntica para qualquer entrada: só grava o pedido na fila (a busca e o envio rodam depois). */
    private function pedir(string $tipo, Request $requisicao): Response
    {
        $recuperacao = $this->servicos->recuperacao();
        if (!$recuperacao->habilitado()) {
            return $this->formularioDePedido($tipo);
        }
        $valor = $requisicao->request->all()['identificador'] ?? '';
        $ip = $this->rede->ip($requisicao);
        $mensagem = $recuperacao->solicitar($tipo, is_string($valor) ? $valor : '', $ip, $this->servicos->limiteDeTentativas()->hashDoIp($ip));
        $this->processarDepoisDaResposta($recuperacao);

        return Pagina::resposta($tipo === RecuperacaoDeAcesso::SENHA ? 'Esqueci a senha' : 'Esqueci o usuário',
            Pagina::alerta('success', $mensagem) . '<p class="mt-3"><a href="' . Painel::PREFIXO . '/entrar">Voltar para entrar</a></p>');
    }

    /** Com FastCGI a resposta sai antes do trabalho; sem ele, o cron processa a fila (tempo da resposta não depende da conta). */
    private function processarDepoisDaResposta(RecuperacaoDeAcesso $recuperacao): void
    {
        if (!function_exists('fastcgi_finish_request')) {
            return;
        }
        register_shutdown_function(static function () use ($recuperacao): void {
            fastcgi_finish_request();
            try {
                $recuperacao->processarFila();
            } catch (\Throwable) {
                // a fila volta a ser tentada pelo cron
            }
        });
    }

    // ---------------------------------------------------------------- definir senha (recuperação e convite)

    private function formularioDeSenha(string $token, bool $convite, ?string $erro = null, int $status = Response::HTTP_OK): Response
    {
        $valido = $convite ? $this->servicos->convites()->tokenValido($token) : $this->servicos->recuperacao()->tokenValido($token);
        if (!$valido) {
            return $this->linkInvalido();
        }
        $rota = Painel::PREFIXO . ($convite ? '/convite/' : '/redefinir-senha/') . $token;
        $ajuda = 'No mínimo 10 caracteres, nada óbvio (como "senha123456"). Tamanho vale mais que símbolos.';

        return Pagina::resposta($convite ? 'Aceitar o convite' : 'Nova senha',
            ($erro === null ? '' : Pagina::alerta('danger', $erro, 'erro-formulario'))
            . '<form method="post" action="' . $rota . '" class="pbl-entrar"' . ($erro === null ? '' : ' aria-describedby="erro-formulario"') . '>'
            . '<input type="hidden" name="_csrf" value="' . Pagina::h($this->csrf->paraEntrar()) . '">'
            . Pagina::campo('campo-senha', 'senha', 'Nova senha', '', ['tipo' => 'password', 'autocomplete' => 'new-password', 'maxlength' => 1024, 'obrigatorio' => true, 'autofocus' => true, 'ajuda' => Pagina::h($ajuda)])
            . Pagina::campo('campo-senha2', 'senha2', 'Repita a nova senha', '', ['tipo' => 'password', 'autocomplete' => 'new-password', 'maxlength' => 1024, 'obrigatorio' => true])
            . Pagina::acoes($convite ? 'Definir senha e aceitar' : 'Salvar nova senha') . '</form>',
            $status, false, '', [], $convite ? 'Escolha a senha da sua conta. O link vale uma vez.' : 'Escolha uma nova senha. As outras sessões da conta serão encerradas.');
    }

    private function definir(string $token, bool $convite, Request $requisicao): Response
    {
        $tudo = $requisicao->request->all();
        $senha = is_string($tudo['senha'] ?? null) ? $tudo['senha'] : '';
        $repita = is_string($tudo['senha2'] ?? null) ? $tudo['senha2'] : '';
        if ($senha !== $repita) {
            return $this->formularioDeSenha($token, $convite, 'As duas senhas não são iguais.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $ipHash = $this->servicos->limiteDeTentativas()->hashDoIp($this->rede->ip($requisicao));
        $motivo = $convite ? $this->servicos->convites()->aceitar($token, $senha) : $this->servicos->recuperacao()->redefinir($token, $senha, $ipHash);
        if ($motivo === RecuperacaoDeAcesso::MENSAGEM_TOKEN_INVALIDO) {
            return $this->linkInvalido();
        }
        if ($motivo !== null) {
            return $this->formularioDeSenha($token, $convite, $motivo, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return Pagina::resposta($convite ? 'Convite aceito' : 'Senha alterada',
            Pagina::alerta('success', $convite ? 'Pronto: sua conta está ativa.' : 'Pronto: sua senha foi alterada e as outras sessões foram encerradas.')
            . '<p class="mt-3"><a class="btn btn-primary" href="' . Painel::PREFIXO . '/entrar">Entrar</a></p>');
    }

    /** Usado, vencido, trocado, de outro tipo ou inventado: sempre a mesma resposta. */
    private function linkInvalido(): Response
    {
        return Pagina::resposta('Link inválido', Pagina::alerta('danger', RecuperacaoDeAcesso::MENSAGEM_TOKEN_INVALIDO)
            . '<p class="mt-3"><a href="' . Painel::PREFIXO . '/esqueci">Pedir um novo link</a> · <a href="' . Painel::PREFIXO . '/entrar">Entrar</a></p>', Response::HTTP_BAD_REQUEST);
    }
}
