<?php

declare(strict_types=1);

namespace Pbl\Email;

/** Configuração de e-mail (valor). `toString` não existe de propósito; a senha nunca sai por `paraTela`. */
final class ConfigDeEmail
{
    public const SEGURANCA_TLS = 'tls';
    public const SEGURANCA_STARTTLS = 'starttls';
    /** Sem TLS: só vale para o servidor de e-mail da própria máquina (loopback). */
    public const SEGURANCA_NENHUMA = 'nenhuma';

    public function __construct(
        public readonly string $driver = Transporte::DESLIGADO,
        public readonly string $host = '',
        public readonly int $porta = 587,
        public readonly string $seguranca = self::SEGURANCA_STARTTLS,
        public readonly string $usuario = '',
        public readonly string $senha = '',
        public readonly string $remetente = '',
    ) {
    }

    public function valida(): ?string
    {
        if (!in_array($this->driver, [Transporte::DESLIGADO, Transporte::ARQUIVO, Transporte::SMTP], true)) {
            return 'Driver de e-mail inválido';
        }
        if ($this->driver === Transporte::DESLIGADO) {
            return null;
        }
        try {
            Mensagem::semQuebra($this->remetente . $this->host . $this->usuario, 'configuração');
        } catch (\InvalidArgumentException) {
            return 'Quebra de linha não é permitida na configuração';
        }
        if (preg_match('/^(?:[^<>@\s]+@[^<>@\s]+|[^<>\r\n]+<[^<>@\s]+@[^<>@\s]+>)$/D', trim($this->remetente)) !== 1) {
            return 'Informe o remetente (ex.: Site <nao-responda@exemplo.org>)';
        }
        if ($this->driver === Transporte::SMTP) {
            if (preg_match('/^(?:[A-Za-z0-9.-]{1,253}|\\[?::1\\]?)$/D', $this->host) !== 1) {
                return 'Servidor SMTP inválido';
            }
            if ($this->porta < 1 || $this->porta > 65535) {
                return 'Porta inválida';
            }
            if (!in_array($this->seguranca, [self::SEGURANCA_TLS, self::SEGURANCA_STARTTLS, self::SEGURANCA_NENHUMA], true)) {
                return 'Segurança inválida (use TLS, STARTTLS ou nenhuma)';
            }
            if ($this->seguranca === self::SEGURANCA_NENHUMA && !self::hostEhLoopback($this->host)) {
                return 'Sem TLS só é permitido no servidor da própria máquina (localhost, 127.0.0.1 ou ::1)';
            }
        }

        return null;
    }

    /** `localhost`, `127.0.0.1` ou `::1` (aceita colchetes), sem diferenciar maiúsculas. */
    public static function hostEhLoopback(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }

    /** @return array{driver: string, host: string, porta: int, seguranca: string, usuario: string, senha: string, remetente: string} senha mascarada */
    public function paraTela(): array
    {
        return [
            'driver' => $this->driver, 'host' => $this->host, 'porta' => $this->porta, 'seguranca' => $this->seguranca,
            'usuario' => $this->usuario, 'senha' => $this->senha === '' ? '' : '••••••••', 'remetente' => $this->remetente,
        ];
    }

    public function comSenha(string $senha): self
    {
        return new self($this->driver, $this->host, $this->porta, $this->seguranca, $this->usuario, $senha, $this->remetente);
    }
}
