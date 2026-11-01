<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\LimiteDeTentativas;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Acesso\SessaoRepositorio;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rotas sob `/_pbl/` (o `_` nunca é válido em URL de conteúdo, então nada publicado colide com elas): entrar, sair e a página
 * inicial da administração, ainda mínima (as telas vêm na fase 5). Segurança conforme o ADR 0011: cookie `HttpOnly` +
 * `SameSite=Lax` (+ `Secure` e prefixo `__Host-` em HTTPS), token novo a cada login, CSRF em todo POST, conferência de origem,
 * limite de tentativas no servidor, resposta igual para login inexistente e senha errada e cabeçalhos de segurança.
 */
final class Painel
{
    public const PREFIXO = '/_pbl';

    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
        private readonly SessaoRepositorio $sessoes,
        private readonly LimiteDeTentativas $limite,
    ) {
    }

    /** O caminho pertence à administração? (`/_pbl` ou `/_pbl/...`) */
    public static function atende(Request $requisicao): bool
    {
        $caminho = $requisicao->getPathInfo();

        return $caminho === self::PREFIXO || str_starts_with($caminho, self::PREFIXO . '/');
    }

    public function tratar(Request $requisicao): Response
    {
        $caminho = rtrim($requisicao->getPathInfo(), '/');
        $metodo = $requisicao->getMethod();
        $resposta = match (true) {
            $caminho === self::PREFIXO && $metodo === 'GET' => $this->inicio($requisicao),
            $caminho === self::PREFIXO . '/entrar' && $metodo === 'GET' => $this->formularioDeEntrar($requisicao),
            $caminho === self::PREFIXO . '/entrar' && $metodo === 'POST' => $this->entrar($requisicao),
            $caminho === self::PREFIXO . '/sair' && $metodo === 'POST' => $this->sair($requisicao),
            default => $this->pagina('Não encontrado', '<p>Página não encontrada.</p>', Response::HTTP_NOT_FOUND),
        };

        return $this->protegida($resposta, $requisicao);
    }

    private function inicio(Request $requisicao): Response
    {
        $sessao = $this->sessaoDe($requisicao);
        if ($sessao === null) {
            return new RedirectResponse(self::PREFIXO . '/entrar', Response::HTTP_SEE_OTHER);
        }
        $u = $sessao->usuario;
        $token = $this->h($this->csrf->daSessao($sessao->idHash));

        return $this->pagina('Administração', '<p>Olá, <strong>' . $this->h($u->nome) . '</strong>.' . ($u->administrador ? ' Você é administrador.' : '') . '</p>'
            . '<p>A administração do PBL4 está em construção. Por enquanto, o conteúdo se gerencia pela linha de comando.</p>'
            . '<form method="post" action="' . self::PREFIXO . '/sair"><input type="hidden" name="_csrf" value="' . $token . '"><button type="submit">Sair</button></form>');
    }

    private function formularioDeEntrar(Request $requisicao, ?string $aviso = null, int $status = Response::HTTP_OK): Response
    {
        if ($this->sessaoDe($requisicao) !== null) {
            return new RedirectResponse(self::PREFIXO . '/', Response::HTTP_SEE_OTHER);
        }

        return $this->pagina('Entrar', ($aviso === null ? '' : '<p role="alert" class="erro">' . $this->h($aviso) . '</p>')
            . '<form method="post" action="' . self::PREFIXO . '/entrar" autocomplete="on">'
            . '<input type="hidden" name="_csrf" value="' . $this->h($this->csrf->paraEntrar()) . '">'
            . '<label>Login <input name="login" autocomplete="username" maxlength="64" required autofocus></label>'
            . '<label>Senha <input name="senha" type="password" autocomplete="current-password" maxlength="1024" required></label>'
            . '<button type="submit">Entrar</button></form>', $status);
    }

    private function entrar(Request $requisicao): Response
    {
        if (!$this->rede->origemConfere($requisicao) || !$this->csrf->conferirEntrar((string) $requisicao->request->get('_csrf', ''))) {
            return $this->pagina('Pedido recusado', '<p>O pedido não pôde ser confirmado. Volte e tente de novo.</p>', Response::HTTP_FORBIDDEN);
        }
        $login = mb_substr(trim((string) $requisicao->request->get('login', '')), 0, 64);
        $senha = mb_substr((string) $requisicao->request->get('senha', ''), 0, 1024);
        $ip = $this->rede->ip($requisicao);
        $ipHash = $this->limite->hashDoIp($ip);

        $espera = $this->limite->bloqueadoPor($login, $ip);
        if ($espera !== null) {
            $this->servicos->auditoria->registrar('login.bloqueado', 'anonimo', null, null, [], $ipHash);
            $r = $this->pagina('Muitas tentativas', '<p>Muitas tentativas de entrar. Aguarde alguns minutos e tente de novo.</p>', Response::HTTP_TOO_MANY_REQUESTS);
            $r->headers->set('Retry-After', (string) $espera);

            return $r;
        }
        $usuario = $this->servicos->usuarios->autenticar($login, $senha);
        if ($usuario === null) {
            $this->limite->registrarFalha($login, $ip);
            $this->servicos->auditoria->registrar('login.falha', 'anonimo', null, null, [], $ipHash);

            return $this->formularioDeEntrar($requisicao, 'Login ou senha inválidos.', Response::HTTP_UNAUTHORIZED);
        }
        $this->limite->limparLogin($login);
        // Sessão antiga que veio no cookie não é reaproveitada: token novo a cada login (contra fixação de sessão).
        $antigo = $this->tokenDoCookie($requisicao);
        if ($antigo !== null) {
            $this->sessoes->encerrar($antigo);
        }
        $token = $this->sessoes->criar($usuario->id);
        $this->servicos->auditoria->registrar('login.ok', 'usuario:' . $usuario->id, $usuario->id, null, [], $ipHash);

        $resposta = new RedirectResponse(self::PREFIXO . '/', Response::HTTP_SEE_OTHER);
        $resposta->headers->setCookie($this->cookie($requisicao, $token, time() + SessaoRepositorio::DURACAO_MAXIMA));

        return $resposta;
    }

    private function sair(Request $requisicao): Response
    {
        $sessao = $this->sessaoDe($requisicao);
        if ($sessao === null) {
            return new RedirectResponse(self::PREFIXO . '/entrar', Response::HTTP_SEE_OTHER);
        }
        if (!$this->rede->origemConfere($requisicao) || !$this->csrf->conferirSessao($sessao->idHash, (string) $requisicao->request->get('_csrf', ''))) {
            return $this->pagina('Pedido recusado', '<p>O pedido não pôde ser confirmado.</p>', Response::HTTP_FORBIDDEN);
        }
        $token = $this->tokenDoCookie($requisicao);
        if ($token !== null) {
            $this->sessoes->encerrar($token);
        }
        $this->servicos->auditoria->registrar('logout', 'usuario:' . $sessao->usuario->id, $sessao->usuario->id);
        $resposta = new RedirectResponse(self::PREFIXO . '/entrar', Response::HTTP_SEE_OTHER);
        $resposta->headers->setCookie($this->cookie($requisicao, '', 1));

        return $resposta;
    }

    private function sessaoDe(Request $requisicao): ?SessaoAtiva
    {
        $token = $this->tokenDoCookie($requisicao);

        return $token === null ? null : $this->sessoes->validar($token);
    }

    private function tokenDoCookie(Request $requisicao): ?string
    {
        $valor = $requisicao->cookies->get($this->nomeDoCookie($requisicao));

        return is_string($valor) && $valor !== '' ? $valor : null;
    }

    private function nomeDoCookie(Request $requisicao): string
    {
        // O prefixo __Host- exige Secure; em HTTP puro (desenvolvimento) o navegador o recusaria.
        return $this->rede->seguro($requisicao) ? '__Host-pbl4' : 'pbl4';
    }

    private function cookie(Request $requisicao, string $valor, int $expira): Cookie
    {
        return Cookie::create($this->nomeDoCookie($requisicao), $valor, $expira, '/', null, $this->rede->seguro($requisicao), true, false, Cookie::SAMESITE_LAX);
    }

    private function protegida(Response $r, Request $requisicao): Response
    {
        return Pagina::protegida($r, $this->rede->seguro($requisicao));
    }

    private function pagina(string $titulo, string $corpo, int $status = Response::HTTP_OK): Response
    {
        return Pagina::resposta($titulo, $corpo, $status);
    }

    private function h(string $texto): string
    {
        return Pagina::h($texto);
    }
}
