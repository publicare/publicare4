<?php

declare(strict_types=1);

namespace Pbl\Email;

/** Configuração de e-mail (valor). `toString` não existe de propósito; a senha nunca sai por `paraTela`. */
final class ConfigDeEmail
{
    public const SEGURANCA_TLS = 'tls';
    public const SEGURANCA_STARTTLS = 'starttls';

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
            if (preg_match('/^[A-Za-z0-9.-]{1,253}$/D', $this->host) !== 1) {
                return 'Servidor SMTP inválido';
            }
            if ($this->porta < 1 || $this->porta > 65535) {
                return 'Porta inválida';
            }
            if (!in_array($this->seguranca, [self::SEGURANCA_TLS, self::SEGURANCA_STARTTLS], true)) {
                return 'Segurança inválida (use TLS ou STARTTLS)';
            }
        }

        return null;
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
