<?php

declare(strict_types=1);

namespace Pbl\Email;

/** E-mail em texto puro. Rejeita CR/LF em qualquer cabeçalho (injeção de cabeçalho) e endereço inválido. */
final class Mensagem
{
    public readonly string $para;
    public readonly string $assunto;

    public function __construct(string $para, string $assunto, public readonly string $corpo)
    {
        self::semQuebra($para, 'destinatário');
        self::semQuebra($assunto, 'assunto');
        if (strlen($para) > 255 || filter_var($para, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Destinatário inválido');
        }
        $this->para = $para;
        $this->assunto = $assunto;
    }

    public static function semQuebra(string $valor, string $campo): void
    {
        if (preg_match('/[\r\n\0]/', $valor) === 1) {
            throw new \InvalidArgumentException("Quebra de linha não é permitida em {$campo}");
        }
    }

    /** Mensagem completa (cabeçalhos + corpo) com CRLF, assunto em UTF-8 codificado (RFC 2047). */
    public function formatar(string $remetente, ?\DateTimeImmutable $data = null): string
    {
        self::semQuebra($remetente, 'remetente');
        $cabecalhos = [
            'Date: ' . ($data ?? new \DateTimeImmutable())->format(\DATE_RFC2822),
            'From: ' . $remetente,
            'To: ' . $this->para,
            'Subject: =?UTF-8?B?' . base64_encode($this->assunto) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@pbl4.invalid>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Auto-Submitted: auto-generated',
        ];

        return implode("\r\n", $cabecalhos) . "\r\n\r\n" . str_replace(["\r\n", "\r", "\n"], "\r\n", $this->corpo) . "\r\n";
    }
}
