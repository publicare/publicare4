<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\LimiteDeTentativas;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Acesso\SessaoRepositorio;
use Pbl\Cron\PseudoCron;
use Pbl\Demo\AvisoDaDemo;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rotas sob `/_pbl/` (o `_` nunca é válido em URL de conteúdo, então nada publicado colide com elas): entrar, sair e as telas
 * da administração (ADR 0012, em `TelasDeConteudo`, `TelasDeObjetos` e `TelasDeClasses`), que só recebem a requisição com sessão válida. Segurança conforme o ADR 0011: cookie `HttpOnly` +
 * `SameSite=Lax` (+ `Secure` e prefixo `__Host-` em HTTPS), token novo a cada login, CSRF em todo POST, conferência de origem,
 * limite de tentativas no servidor, resposta igual para login inexistente e senha errada e cabeçalhos de segurança.
 */
final class Painel
{
    public const PREFIXO = '/_pbl';
    /** Cookie não-HttpOnly que só indica "há sessão" para a barra de edição (ADR 0022). */
    public const INDICADOR = 'pbl4_ind';

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
        Pagina::definirCaminho($caminho);
        Pagina::definirAvisoDeDemo(AvisoDaDemo::linhaDoPainel($this->servicos->caminhos));
        $metodo = $requisicao->getMethod();
        // Id validado por expressão ancorada e sem ambiguidade antes de virar inteiro; a sub-rota precisa existir para o método.
        $objeto = preg_match('#^' . self::PREFIXO . '/objeto/([1-9][0-9]{0,9})(?:/([a-z]{1,20}))?$#D', $caminho, $m) === 1 ? (int) $m[1] : null;
        $subrota = $m[2] ?? '';
        $rotaDeClasse = TelasDeClasses::rota(self::PREFIXO, $caminho, $metodo);
        $rotaDeModelo = TelasDeModelos::rota(self::PREFIXO, $caminho, $metodo);
        $rotaDeConta = TelasDeContas::rota(self::PREFIXO, $caminho, $metodo);
        $rotaDeDominio = TelasDeDominios::rota(self::PREFIXO, $caminho, $metodo);
        $rotaDeSite = TelasDeSite::rota(self::PREFIXO, $caminho, $metodo);
        $rotaDeAtualizacao = TelasDeAtualizacao::rota(self::PREFIXO, $caminho, $metodo);
        $rotaDeCron = TelasDeCron::rota(self::PREFIXO, $caminho, $metodo);
        $rotaDeRevisao = TelasDeRevisao::rota(self::PREFIXO, $caminho, $metodo);
        $rotaDeNavegacao = TelasDeNavegacao::rota(self::PREFIXO, $caminho, $metodo);
        $rotaDePilha = TelasDePilha::rota(self::PREFIXO, $caminho, $metodo);
        $rotaDeVencidos = TelasDeVencidos::rota(self::PREFIXO, $caminho, $metodo);
        $rotaDeLixeira = TelasDeLixeira::rota(self::PREFIXO, $caminho, $metodo);
        $resposta = match (true) {
            $caminho === self::PREFIXO && $metodo === 'GET' => $this->comSessao($requisicao, fn (SessaoAtiva $s): Response => $this->telas()->inicio($s)),
            ApiDeAcoes::atende($caminho, $metodo) => $this->api($requisicao, $caminho),
            $objeto !== null && TelasDeObjetos::atende($subrota, $metodo) => $this->comSessao($requisicao, fn (SessaoAtiva $s): Response => $this->objetos()->tratar($s, $objeto, $subrota, $requisicao)),
            $rotaDeClasse !== null => $this->comSessao($requisicao, fn (SessaoAtiva $s): Response => (new TelasDeClasses($this->servicos, $this->rede, $this->csrf))->tratar($s, $rotaDeClasse, $requisicao)),
            $rotaDeModelo !== null => $this->comSessao($requisicao, fn (SessaoAtiva $s): Response => (new TelasDeModelos($this->servicos, $this->rede, $this->csrf))->tratar($s, $rotaDeModelo, $requisicao)),
            $rotaDeConta !== null => $this->comSessao($requisicao, fn (SessaoAtiva $s): Response => (new TelasDeContas($this->servicos, $this->rede, $this->csrf))->tratar($s, $rotaDeConta, $requisicao)),
            $rotaDeDominio !== null => $this->comSessao($requisicao, fn (SessaoAtiva $s): Response => (new TelasDeDominios($this->servicos, $this->rede, $this->csrf))->tratar($s, $rotaDeDominio, $requisicao)),
            $rotaDeSite !== null => $this->comSessao($requisicao, fn (SessaoAtiva $s): Response => (new TelasDeSite($this->servicos, $this->rede, $this->csrf))->tratar($s, $rotaDeSite, $requisicao)),
            $rotaDeAtualizacao !== null => $this->comSessao($requisicao, fn (SessaoAtiva $s): Response => (new TelasDeAtualizacao($this->servicos, $this->rede, $this->csrf))->tratar($s, $rotaDeAtualizacao, $requisicao)),
            $rotaDeCron !== null => $this->comSessao($requisicao, fn (SessaoAtiva $s): Response => (new TelasDeCron($this->servicos, $this->rede, $this->csrf))->tratar($s, $rotaDeCron, $requisicao)),
            $rotaDeRevisao !== null => $this->comSessao($requisicao, fn (SessaoAtiva $s): Response => (new TelasDeRevisao($this->servicos, $this->rede, $this->csrf))->tratar($s, $rotaDeRevisao, $requisicao)),
            $rotaDeNavegacao !== null => $this->comSessao($requisicao, fn (SessaoAtiva $s): Response => (new TelasDeNavegacao($this->servicos, $this->rede, $this->csrf))->tratar($s, $rotaDeNavegacao, $requisicao)),
            $rotaDePilha !== null => $this->comSessao($requisicao, fn (SessaoAtiva $s): Response => (new TelasDePilha($this->servicos, $this->rede, $this->csrf))->tratar($s, $rotaDePilha, $requisicao)),
            $rotaDeVencidos !== null => $this->comSessao($requisicao, fn (SessaoAtiva $s): Response => (new TelasDeVencidos($this->servicos, $this->rede, $this->csrf))->tratar($s, $rotaDeVencidos, $requisicao)),
            $rotaDeLixeira !== null => $this->comSessao($requisicao, fn (SessaoAtiva $s): Response => (new TelasDeLixeira($this->servicos, $this->rede, $this->csrf))->tratar($s, $rotaDeLixeira, $requisicao)),
            $caminho === self::PREFIXO . '/entrar' && $metodo === 'GET' => $this->formularioDeEntrar($requisicao),
            $caminho === self::PREFIXO . '/entrar' && $metodo === 'POST' => $this->entrar($requisicao),
            $caminho === self::PREFIXO . '/sair' && $metodo === 'POST' => $this->sair($requisicao),
            default => $this->pagina('Não encontrado', '<p>Página não encontrada.</p>', Response::HTTP_NOT_FOUND),
        };

        return $this->protegida($resposta, $requisicao);
    }

    /**
     * Toda tela da administração passa por aqui: sem sessão válida, vai para entrar (também no POST, que então não grava nada).
     *
     * @param \Closure(SessaoAtiva): Response $tela
     */
    private function comSessao(Request $requisicao, \Closure $tela): Response
    {
        $sessao = $this->sessaoDe($requisicao);

        if ($sessao === null) {
            return new RedirectResponse(self::PREFIXO . '/entrar', Response::HTTP_SEE_OTHER);
        }
        $this->prepararBarra($sessao);
        if ($requisicao->getMethod() === 'GET') {
            PseudoCron::talvez($this->servicos); // hospedagem sem cron: só no painel, só GET, nunca lança
        }

        return $tela($sessao);
    }

    /** Contador de pendências e links de revisão da barra; uma falha aqui nunca derruba a tela. */
    private function prepararBarra(SessaoAtiva $sessao): void
    {
        try {
            $u = $sessao->usuario;
            $revisor = $this->servicos->revisao->ehRevisor($u);
            Pagina::definirRevisao($revisor ? $this->servicos->revisao->contarPendencias($u) : 0, $revisor, $this->servicos->revisao->podeEnviar($u));
        } catch (\Throwable) {
            Pagina::definirRevisao(0, false, false);
        }
    }

    /** API da barra de edição: sem sessão devolve 401 em JSON (e não o redirecionamento das telas). */
    private function api(Request $requisicao, string $caminho): Response
    {
        $sessao = $this->sessaoDe($requisicao);

        return $sessao === null ? ApiDeAcoes::semSessao() : (new ApiDeAcoes($this->servicos, $this->rede, $this->csrf))->tratar($sessao, $caminho, $requisicao);
    }

    private function telas(): TelasDeConteudo
    {
        return new TelasDeConteudo($this->servicos, $this->csrf);
    }

    private function objetos(): TelasDeObjetos
    {
        return new TelasDeObjetos($this->servicos, $this->rede, $this->csrf);
    }

    private function formularioDeEntrar(Request $requisicao, ?string $aviso = null, int $status = Response::HTTP_OK): Response
    {
        if ($this->sessaoDe($requisicao) !== null) {
            return new RedirectResponse(self::PREFIXO . '/', Response::HTTP_SEE_OTHER);
        }

        return Pagina::resposta('Entrar', ($aviso === null ? '' : Pagina::alerta('danger', $aviso))
            . '<form method="post" action="' . self::PREFIXO . '/entrar" autocomplete="on" class="pbl-entrar">'
            . '<input type="hidden" name="_csrf" value="' . $this->h($this->csrf->paraEntrar()) . '">'
            . Pagina::campo('campo-login', 'login', 'Login', '', ['autocomplete' => 'username', 'maxlength' => 64, 'obrigatorio' => true, 'autofocus' => true])
            . Pagina::campo('campo-senha', 'senha', 'Senha', '', ['tipo' => 'password', 'autocomplete' => 'current-password', 'maxlength' => 1024, 'obrigatorio' => true])
            . Pagina::acoes('Entrar') . '</form>', $status, false, '', [], 'Entre com o login e a senha da sua conta.');
    }

    private function entrar(Request $requisicao): Response
    {
        if (!$this->rede->origemConfere($requisicao) || !$this->csrf->conferirEntrar((string) $requisicao->request->get('_csrf', ''))) {
            return $this->pagina('Pedido recusado', Pagina::alerta('danger', 'O pedido não pôde ser confirmado. Volte e tente de novo.'), Response::HTTP_FORBIDDEN);
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
        $resposta->headers->setCookie($this->indicador($requisicao, '1', time() + SessaoRepositorio::DURACAO_MAXIMA));

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
        $resposta->headers->setCookie($this->indicador($requisicao, '', 1));

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

    /**
     * Cookie indicador "há sessão" (sem segredo, legível por JavaScript): só avisa à barra de edição das páginas públicas que vale
     * perguntar ao servidor o que a pessoa pode fazer. Não autentica nada.
     */
    private function indicador(Request $requisicao, string $valor, int $expira): Cookie
    {
        return Cookie::create(self::INDICADOR, $valor, $expira, '/', null, $this->rede->seguro($requisicao), false, false, Cookie::SAMESITE_LAX);
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
