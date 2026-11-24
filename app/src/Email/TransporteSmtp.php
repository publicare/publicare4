<?php

declare(strict_types=1);

namespace Pbl\Email;

/**
 * Cliente SMTP mínimo (ADR 0034): com TLS (implícito ou STARTTLS) e, só no loopback, sem TLS (ADR 0039); usuário/senha opcionais, AUTH PLAIN/LOGIN, um destinatário por
 * mensagem. Mensagens de erro não repetem usuário, senha nem a resposta do servidor.
 */
final class TransporteSmtp implements Transporte
{
    private const ERRO_AUTH = 'Autenticação recusada pelo servidor de e-mail';

    /** @param \Closure(): CanalSmtp $abrir */
    public function __construct(
        private readonly \Closure $abrir,
        private readonly bool $starttls,
        private readonly string $usuario,
        private readonly string $senha,
        private readonly string $remetente,
        private readonly string $nomeLocal = 'pbl4.invalid',
        private readonly bool $semTls = false,
        private readonly string $host = '',
    ) {
    }

    public function enviar(Mensagem $mensagem): void
    {
        if ($this->semTls && (!ConfigDeEmail::hostEhLoopback($this->host) || $this->starttls)) {
            throw new FalhaDeEmail('Conexão sem TLS só é permitida para o servidor da própria máquina', FalhaDeEmail::CONFIGURACAO);
        }
        $de = $this->enderecoDe($this->remetente);
        $canal = ($this->abrir)();
        try {
            $this->esperar($canal->ler(), 220);
            $this->comando($canal, 'EHLO ' . $this->nomeLocal, 250);
            if ($this->starttls) {
                $this->comando($canal, 'STARTTLS', 220);
                if (!$canal->ligarTls()) {
                    throw new FalhaDeEmail('Falha ao negociar TLS com o servidor de e-mail', FalhaDeEmail::TLS);
                }
                $this->comando($canal, 'EHLO ' . $this->nomeLocal, 250);
            }
            if ($this->usuario !== '') {
                $this->autenticar($canal);
            }
            $this->comando($canal, 'MAIL FROM:<' . $de . '>', 250);
            $this->comando($canal, 'RCPT TO:<' . $mensagem->para . '>', 250);
            $this->comando($canal, 'DATA', 354);
            $corpo = preg_replace('/^\./m', '..', $mensagem->formatar($this->remetente)) ?? '';
            $canal->escrever($corpo . ".\r\n");
            $this->esperar($canal->ler(), 250);
            try {
                $canal->escrever("QUIT\r\n");
            } catch (\RuntimeException) {
            }
        } finally {
            $canal->fechar();
        }
    }

    public function tipo(): string
    {
        return self::SMTP;
    }

    private function autenticar(CanalSmtp $canal): void
    {
        $canal->escrever('AUTH PLAIN ' . base64_encode("\0" . $this->usuario . "\0" . $this->senha) . "\r\n");
        [$codigo] = $canal->ler();
        if ($codigo === 235) {
            return;
        }
        // Servidores que só aceitam LOGIN.
        $erro = self::ERRO_AUTH;
        $this->comando($canal, 'AUTH LOGIN', 334, $erro);
        $this->comando($canal, base64_encode($this->usuario), 334, $erro);
        $this->comando($canal, base64_encode($this->senha), 235, $erro);
    }

    /** @param array{int, string} $resposta */
    private function esperar(array $resposta, int $esperado, string $erro = 'O servidor de e-mail recusou o envio'): void
    {
        if ($resposta[0] !== $esperado) {
            throw new FalhaDeEmail($erro, $erro === self::ERRO_AUTH ? FalhaDeEmail::AUTENTICACAO : FalhaDeEmail::RECUSADO);
        }
    }

    private function comando(CanalSmtp $canal, string $linha, int $esperado, string $erro = 'O servidor de e-mail recusou o envio'): void
    {
        $canal->escrever($linha . "\r\n");
        $this->esperar($canal->ler(), $esperado, $erro);
    }

    private function enderecoDe(string $remetente): string
    {
        Mensagem::semQuebra($remetente, 'remetente');
        $endereco = preg_match('/<([^<>]+)>\s*$/', $remetente, $m) === 1 ? $m[1] : trim($remetente);
        if (filter_var($endereco, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Remetente inválido');
        }

        return $endereco;
    }
}
