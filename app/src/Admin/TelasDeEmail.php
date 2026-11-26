<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Email\ConfigDeEmail;
use Pbl\Email\FalhaDeEmail;
use Pbl\Email\Transporte;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Configuração do envio de e-mail (ADR 0034): desligado, arquivo (grava a mensagem em `dados/email/caixa/`) ou SMTP. Só
 * administrador global, com CSRF, origem e modo elevado. A senha do SMTP nunca volta para a tela (só uma máscara) e só é trocada
 * se um valor novo vier; a auditoria guarda apenas os nomes dos campos alterados.
 */
final class TelasDeEmail
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
            $caminho === $prefixo . '/email' && $metodo === 'GET' => ['ver'],
            $caminho === $prefixo . '/email' && $metodo === 'POST' => ['salvar'],
            $caminho === $prefixo . '/email/teste' && $metodo === 'POST' => ['teste'],
            default => null,
        };
    }

    /** @param array{0: string} $rota */
    public function tratar(SessaoAtiva $sessao, array $rota, Request $requisicao): Response
    {
        $post = $requisicao->getMethod() === 'POST';
        if ($post && !$this->postConfere($sessao, $requisicao)) {
            return $this->recusado();
        }
        if (!$this->servicos->permissoes->podeAdministrarContas($sessao->usuario)) {
            if ($post) {
                $this->servicos->auditoria->registrar('conta.negado', 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, null, ['tela' => 'email']);
            }

            return Pagina::resposta('Sem acesso', Pagina::alerta('warning', 'Só administradores podem configurar o e-mail.'), Response::HTTP_FORBIDDEN, false, $this->barra($sessao));
        }

        return match ($rota[0]) {
            'ver' => $this->pagina($sessao, $this->servicos->configDeEmail()->carregar()->paraTela(), null, Response::HTTP_OK, $this->aviso($requisicao)),
            'salvar' => $this->salvar($sessao, $requisicao),
            default => $this->testar($sessao, $requisicao),
        };
    }

    private function aviso(Request $requisicao): string
    {
        $q = $requisicao->query->all();

        return match ($q['ok'] ?? null) {
            'salvo' => Pagina::alerta('success', 'Configuração de e-mail salva.'),
            'teste' => Pagina::alerta('success', 'Mensagem de teste enviada para o seu e-mail.'),
            default => '',
        };
    }

    /** @param array{driver: string, host: string, porta: int, seguranca: string, usuario: string, senha: string, remetente: string} $c senha só mascarada */
    private function pagina(SessaoAtiva $sessao, array $c, ?string $erro, int $status, string $aviso = ''): Response
    {
        $h = Pagina::h(...);
        $drivers = [Transporte::DESLIGADO => 'Desligado (recuperação por e-mail desativada)', Transporte::ARQUIVO => 'Arquivo (grava a mensagem em dados/email/caixa/)', Transporte::SMTP => 'SMTP'];
        $seguranca = [ConfigDeEmail::SEGURANCA_STARTTLS => 'STARTTLS (porta 587)', ConfigDeEmail::SEGURANCA_TLS => 'TLS direto (porta 465)', ConfigDeEmail::SEGURANCA_NENHUMA => 'Nenhuma (só no servidor da própria máquina)'];
        $corpo = $aviso . $this->erro($erro)
            . ($c['driver'] === Transporte::ARQUIVO ? Pagina::alerta('warning', 'No driver "arquivo" os links de recuperação ficam gravados em disco, legíveis por quem acessa o servidor. Use só em desenvolvimento ou quando não há SMTP; sem SMTP, prefira bin/pbl conta-recuperar.') : '')
            . '<form method="post" action="' . Painel::PREFIXO . '/email"' . ($erro === null ? '' : ' aria-describedby="erro-formulario"') . '>' . $this->campoCsrf($sessao)
            . Pagina::campo('campo-driver', 'driver', 'Envio', $c['driver'], ['tipo' => 'select', 'opcoes' => $drivers])
            . Pagina::campo('campo-remetente', 'remetente', 'Remetente', $c['remetente'], ['maxlength' => 255, 'ajuda' => 'Ex.: Meu Site <nao-responda@exemplo.org>', 'autocomplete' => 'off'])
            . Pagina::campo('campo-host', 'host', 'Servidor SMTP', $c['host'], ['maxlength' => 253, 'autocomplete' => 'off'])
            . Pagina::campo('campo-porta', 'porta', 'Porta', (string) $c['porta'], ['tipo' => 'number', 'min' => 1, 'max' => 65535, 'inputmode' => 'numeric'])
            . Pagina::campo('campo-seguranca', 'seguranca', 'Segurança da conexão', $c['seguranca'], ['tipo' => 'select', 'opcoes' => $seguranca, 'ajuda' => 'Sem TLS ("Nenhuma") só vale quando o servidor é o e-mail da própria máquina: localhost, 127.0.0.1 ou ::1. Para qualquer outro servidor use TLS ou STARTTLS, com certificado válido.'])
            . Pagina::campo('campo-usuario', 'usuario', 'Usuário do SMTP', $c['usuario'], ['maxlength' => 255, 'autocomplete' => 'off', 'ajuda' => 'Opcional: deixe usuário e senha em branco se o servidor local aceita envio sem login.'])
            . '<div class="mb-3"><label for="campo-senha-smtp" class="form-label">Senha do SMTP</label>'
            . '<input id="campo-senha-smtp" class="form-control" name="senha_smtp" type="password" autocomplete="new-password" maxlength="1024" value="" aria-describedby="ajuda-senha-smtp" placeholder="' . $h($c['senha']) . '">'
            . '<div id="ajuda-senha-smtp" class="form-text">' . ($c['senha'] === '' ? 'Nenhuma senha guardada.' : 'Há uma senha guardada (cifrada). Deixe em branco para mantê-la.') . '</div></div>'
            . $this->campoSenha($sessao, 'campo-senha-email')
            . '<div class="pbl-acoes"><button type="submit" class="btn btn-primary">Salvar</button></div></form>'
            . '<hr class="my-4"><h2 class="h5">Enviar mensagem de teste</h2><p>Envia um e-mail para o endereço da sua conta.</p>'
            . '<form method="post" action="' . Painel::PREFIXO . '/email/teste">' . $this->campoCsrf($sessao) . $this->campoSenha($sessao, 'campo-senha-teste')
            . '<button type="submit" class="btn btn-outline-primary">Enviar teste</button></form>';

        return Pagina::resposta('E-mail do site', $corpo, $status, false, $this->barra($sessao), [['Início', Painel::PREFIXO . '/'], ['E-mail', null]],
            'Usado para recuperar senha, convidar pessoas e avisar sobre a conta. O site precisa de um domínio principal, pois os links usam esse endereço.');
    }

    private function salvar(SessaoAtiva $sessao, Request $requisicao): Response
    {
        $campos = [];
        foreach (['driver', 'remetente', 'host', 'porta', 'seguranca', 'usuario', 'senha_smtp'] as $nome) {
            $v = $this->texto($requisicao, $nome);
            if ($v === null) {
                return $this->incompleto($sessao);
            }
            $campos[$nome] = $v;
        }
        $repo = $this->servicos->configDeEmail();
        $atual = $repo->carregar();
        $nova = new ConfigDeEmail($campos['driver'], trim($campos['host']), (int) $campos['porta'], $campos['seguranca'], trim($campos['usuario']), '', trim($campos['remetente']));
        $falha = $this->exigirReautenticacao($sessao, $requisicao, 'email');
        if ($falha !== null) {
            return $this->aplicarFalhaDeReautenticacao($this->pagina($sessao, $nova->comSenha($atual->senha)->paraTela(), $falha['mensagem'], Response::HTTP_OK), $falha);
        }
        foreach (['remetente', 'host', 'usuario'] as $nome) {
            $erro = $this->erroDeTexto($campos[$nome], 255, 'O campo ' . $nome);
            if ($erro !== null) {
                return $this->pagina($sessao, $nova->comSenha($atual->senha)->paraTela(), $erro, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
        try {
            $repo->salvar($nova, $campos['senha_smtp']);
        } catch (\InvalidArgumentException $e) {
            return $this->pagina($sessao, $nova->comSenha($atual->senha)->paraTela(), $this->mensagemDoRepositorio($e), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $mudou = array_keys(array_filter([
            'driver' => $atual->driver !== $nova->driver, 'host' => $atual->host !== $nova->host, 'porta' => $atual->porta !== $nova->porta,
            'seguranca' => $atual->seguranca !== $nova->seguranca, 'usuario' => $atual->usuario !== $nova->usuario,
            'senha' => $campos['senha_smtp'] !== '', 'remetente' => $atual->remetente !== $nova->remetente,
        ]));
        $this->servicos->auditoria->registrar('email.configurar', 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, null, ['campos' => implode(',', $mudou)]);

        return new RedirectResponse(Painel::PREFIXO . '/email?ok=salvo', Response::HTTP_SEE_OTHER);
    }

    private function testar(SessaoAtiva $sessao, Request $requisicao): Response
    {
        $atual = $this->servicos->configDeEmail()->carregar();
        $falha = $this->exigirReautenticacao($sessao, $requisicao, 'email');
        if ($falha !== null) {
            return $this->aplicarFalhaDeReautenticacao($this->pagina($sessao, $atual->paraTela(), $falha['mensagem'], Response::HTTP_OK), $falha);
        }
        $emails = $this->servicos->emailsDeConta();
        if (!$emails->habilitado()) {
            return $this->pagina($sessao, $atual->paraTela(), 'O envio está desligado ou falta o domínio principal do site.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $emails->enviar($sessao->usuario->email, 'Teste de e-mail', "Se você leu esta mensagem, o envio de e-mail do site está funcionando.\n");
        } catch (FalhaDeEmail $e) {
            return $this->pagina($sessao, $atual->paraTela(), self::mensagemDeFalha($e->categoria), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\RuntimeException) {
            return $this->pagina($sessao, $atual->paraTela(), self::mensagemDeFalha(FalhaDeEmail::RECUSADO), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new RedirectResponse(Painel::PREFIXO . '/email?ok=teste', Response::HTTP_SEE_OTHER);
    }

    /** Texto por categoria; nunca repete resposta do servidor, usuário ou senha. */
    private static function mensagemDeFalha(string $categoria): string
    {
        return match ($categoria) {
            FalhaDeEmail::REDE => 'Sem conexão com o servidor de e-mail. Confira servidor e porta.',
            FalhaDeEmail::TLS => 'Falha de TLS: o certificado do servidor não foi aceito ou a conexão segura não abriu. Confira a segurança escolhida e o certificado.',
            FalhaDeEmail::AUTENTICACAO => 'O servidor recusou o usuário ou a senha.',
            FalhaDeEmail::CONFIGURACAO => 'Configuração recusada: sem TLS só vale para o servidor da própria máquina (localhost, 127.0.0.1 ou ::1).',
            default => 'O servidor recebeu a conexão, mas recusou a mensagem. Confira o remetente e se o servidor aceita envio deste endereço.',
        };
    }
}
