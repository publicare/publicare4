<?php

declare(strict_types=1);

namespace Pbl\Instalacao;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\Segredo;
use Pbl\Acesso\SenhaPolitica;
use Pbl\Admin\Pagina;
use Pbl\Caminhos;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assistente de instalação pelo navegador, em `/_pbl/instalar` (fluxo 3). Só existe enquanto o PBL4 não está instalado.
 *
 * Um instalador aberto deixaria qualquer visitante assumir um site recém-enviado. Por isso exige **prova de posse**: quem
 * instala cria um arquivo vazio `dados/instalar.liberar` (só quem tem acesso à pasta, por FTP ou painel, consegue). Além
 * disso: CSRF, conferência de origem, tudo escapado, e a instalação é atômica (se algo falha, dá para tentar de novo).
 */
final class InstaladorWeb
{
    public const PREFIXO = '/_pbl/instalar';
    public const ARQUIVO_DE_POSSE = 'instalar.liberar';

    public function __construct(private readonly Caminhos $caminhos)
    {
    }

    public static function atende(Request $requisicao): bool
    {
        $caminho = rtrim($requisicao->getPathInfo(), '/');

        return $caminho === self::PREFIXO;
    }

    public function tratar(Request $requisicao): Response
    {
        $rede = new Rede($this->caminhos);
        $seguro = $rede->seguro($requisicao);
        if ((new Instalador($this->caminhos))->instalado()) {
            return Pagina::resposta('Não encontrado', '<p>Página não encontrada.</p>', Response::HTTP_NOT_FOUND, $seguro);
        }
        $requisitos = new Requisitos($this->caminhos);
        if (!$requisitos->tudoCerto()) {
            return $this->resposta($requisitos, $requisicao, $seguro, null, [], Response::HTTP_OK);
        }
        if (!in_array($requisicao->getMethod(), ['GET', 'POST'], true)) {
            return Pagina::resposta('Não encontrado', '<p>Página não encontrada.</p>', Response::HTTP_NOT_FOUND, $seguro);
        }
        try {
            $csrf = new Csrf(Segredo::obter($this->caminhos));
        } catch (\RuntimeException $e) {
            return Pagina::resposta('Não foi possível preparar a instalação', '<p class="erro">' . Pagina::h($e->getMessage()) . '</p>', Response::HTTP_INTERNAL_SERVER_ERROR, $seguro);
        }
        if ($requisicao->getMethod() === 'POST') {
            return $this->instalar($requisicao, $requisitos, $rede, $csrf, $seguro);
        }

        return $this->resposta($requisitos, $requisicao, $seguro, $csrf, []);
    }

    private function instalar(Request $requisicao, Requisitos $requisitos, Rede $rede, Csrf $csrf, bool $seguro): Response
    {
        if (!$this->temPosse() || !$rede->origemConfere($requisicao, true) || !$csrf->conferirEntrar((string) $requisicao->request->get('_csrf', ''))) {
            return Pagina::resposta('Pedido recusado', '<p>O pedido não pôde ser confirmado. Volte, confira a prova de posse e tente de novo.</p>', Response::HTTP_FORBIDDEN, $seguro);
        }
        $campos = [
            'site' => mb_substr(trim((string) $requisicao->request->get('site', '')), 0, 200),
            'dominio' => mb_substr(strtolower(trim((string) $requisicao->request->get('dominio', ''))), 0, 253),
            'login' => mb_substr(trim((string) $requisicao->request->get('login', '')), 0, 64),
            'email' => mb_substr(trim((string) $requisicao->request->get('email', '')), 0, 255),
            'nome' => mb_substr(trim((string) $requisicao->request->get('nome', '')), 0, 200),
        ];
        $senha = mb_substr((string) $requisicao->request->get('senha', ''), 0, SenhaPolitica::MAXIMO + 1);
        $senha2 = mb_substr((string) $requisicao->request->get('senha2', ''), 0, SenhaPolitica::MAXIMO + 1);

        $erro = null;
        if ($senha !== $senha2) {
            $erro = 'As duas senhas não são iguais.';
        } else {
            $erro = SenhaPolitica::problema($senha, $campos['login'], $campos['email']);
        }
        if ($erro === null) {
            try {
                (new Instalador($this->caminhos))->instalar($campos['site'], $campos['dominio'], [
                    'login' => $campos['login'], 'email' => $campos['email'], 'nome' => $campos['nome'] === '' ? $campos['login'] : $campos['nome'], 'senha' => $senha,
                ]);
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                $erro = $e->getMessage();
            }
        }
        if ($erro !== null) {
            return $this->resposta($requisitos, $requisicao, $seguro, $csrf, $campos, Response::HTTP_UNPROCESSABLE_ENTITY, $erro);
        }

        // Instalado: publica as páginas de exemplo e desliga a prova de posse (o instalador também some pela trava).
        $aviso = '';
        try {
            $servicos = Servicos::doBancoInstalado($this->caminhos);
            foreach ($servicos->sites->todos() as $site) {
                $servicos->publicador->publicarSite($site);
            }
        } catch (\Throwable) {
            // O site já está instalado (o instalador não volta): a publicação pode ser refeita depois, sem refazer nada.
            $aviso = '<p role="alert" class="erro">O PBL4 foi instalado, mas não conseguiu publicar as páginas de exemplo agora. '
                . 'Dê permissão de escrita à pasta <code>dados/cache/</code> e rode <code>php bin/pbl publicar</code> (ou republique pela administração).</p>';
        }
        @unlink($this->caminhos->dados() . '/' . self::ARQUIVO_DE_POSSE);

        return Pagina::resposta('PBL4 instalado', '<p class="ok">Tudo pronto.</p>' . $aviso . '<ul>'
            . '<li><a href="/">Ver o site</a></li><li><a href="/_pbl/entrar">Entrar na administração</a> com o login <strong>' . Pagina::h($campos['login']) . '</strong></li></ul>'
            . '<h2>Antes de seguir</h2><ul>'
            . '<li>Use <strong>HTTPS</strong> no endereço do site (o login só fica totalmente protegido assim). Se o site fica atrás de um proxy (como o Cloudflare), crie <code>dados/config/rede.json</code> para o login funcionar (veja o guia de instalação).</li>'
            . '<li>Confirme que só a pasta <code>public/</code> é a raiz do site no servidor.</li>'
            . '<li>Guarde a senha em um gerenciador de senhas. O PBL4 não consegue recuperá-la.</li></ul>', Response::HTTP_OK, $seguro);
    }

    /**
     * @param array<string, string> $campos
     */
    private function resposta(Requisitos $requisitos, Request $requisicao, bool $seguro, ?Csrf $csrf, array $campos, int $status = Response::HTTP_OK, ?string $erro = null): Response
    {
        $linhas = '';
        foreach ($requisitos->verificar() as $item) {
            $marca = $item['ok'] ? '✔' : ($item['obrigatorio'] ? '✘' : '⚠');
            $linhas .= '<tr><td class="' . ($item['ok'] ? 'ok' : ($item['obrigatorio'] ? 'no' : 'aviso')) . '">' . $marca . '</td><td><strong>' . Pagina::h($item['nome']) . '</strong><br>' . Pagina::h($item['detalhe']) . '</td></tr>';
        }
        $corpo = '<h2>1. Requisitos do servidor</h2><table>' . $linhas . '</table>';
        if (!$requisitos->tudoCerto() || $csrf === null) {
            return Pagina::resposta('Instalar o PBL4', $corpo . '<p class="erro">Corrija os itens marcados com ✘ e recarregue esta página. Os marcados com ⚠ são avisos: não impedem a instalação.</p>', $status, $seguro);
        }
        $corpo .= '<h2>2. Prove que você é o dono do servidor</h2>';
        if (!$this->temPosse()) {
            return Pagina::resposta('Instalar o PBL4', $corpo
                . '<p>Para ninguém mais poder instalar no seu lugar, crie um arquivo <strong>vazio</strong> chamado <code>' . self::ARQUIVO_DE_POSSE
                . '</code> dentro da pasta <code>dados/</code> do site (por FTP ou pelo painel da hospedagem) e recarregue esta página.</p>', $status, $seguro);
        }
        $v = static fn (string $campo, string $padrao = ''): string => Pagina::h($campos[$campo] ?? $padrao);
        $corpo .= '<p class="ok">✔ Arquivo encontrado.</p><h2>3. Seu site e a conta de administrador</h2>'
            . ($erro === null ? '' : '<p role="alert" class="erro">' . Pagina::h($erro) . '</p>')
            . '<form method="post" action="' . self::PREFIXO . '" autocomplete="off">'
            . '<input type="hidden" name="_csrf" value="' . Pagina::h($csrf->paraEntrar()) . '">'
            . '<label>Nome do site <input name="site" maxlength="200" required value="' . $v('site') . '"></label>'
            . '<label>Endereço (domínio) do site <input name="dominio" maxlength="253" required value="' . $v('dominio', $requisicao->getHost()) . '"></label>'
            . '<label>Login do administrador <input name="login" maxlength="64" required autocomplete="username" value="' . $v('login') . '"></label>'
            . '<label>Nome <input name="nome" maxlength="200" value="' . $v('nome') . '"></label>'
            . '<label>E-mail <input name="email" type="email" maxlength="255" required value="' . $v('email') . '"></label>'
            . '<label>Senha (mínimo ' . SenhaPolitica::MINIMO . ' caracteres) <input name="senha" type="password" required autocomplete="new-password"></label>'
            . '<label>Repita a senha <input name="senha2" type="password" required autocomplete="new-password"></label>'
            . '<button type="submit">Instalar</button></form>';

        return Pagina::resposta('Instalar o PBL4', $corpo, $status, $seguro);
    }

    private function temPosse(): bool
    {
        return is_file($this->caminhos->dados() . '/' . self::ARQUIVO_DE_POSSE);
    }
}
