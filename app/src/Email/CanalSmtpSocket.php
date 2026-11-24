<?php

declare(strict_types=1);

namespace Pbl\Email;

/** Canal real: `stream_socket_client` com timeouts curtos e verificação de certificado. */
final class CanalSmtpSocket implements CanalSmtp
{
    /** @var resource */
    private $socket;

    public function __construct(string $host, int $porta, bool $tlsImplicito, int $timeout = 10)
    {
        $contexto = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $s = @stream_socket_client(($tlsImplicito ? 'ssl://' : 'tcp://') . $host . ':' . $porta, $codigo, $texto, $timeout, STREAM_CLIENT_CONNECT, $contexto);
        if ($s === false) {
            throw new \RuntimeException('Não foi possível conectar ao servidor de e-mail');
        }
        stream_set_timeout($s, $timeout);
        $this->socket = $s;
    }

    /** @return array{int, string} */
    public function ler(): array
    {
        $texto = '';
        do {
            $linha = fgets($this->socket, 1024);
            if ($linha === false) {
                throw new \RuntimeException('O servidor de e-mail não respondeu');
            }
            $texto .= $linha;
        } while (strlen($linha) >= 4 && $linha[3] === '-');

        return [(int) substr($texto, 0, 3), $texto];
    }

    public function escrever(string $dados): void
    {
        if (@fwrite($this->socket, $dados) === false) {
            throw new \RuntimeException('Falha ao enviar ao servidor de e-mail');
        }
    }

    public function ligarTls(): bool
    {
        return stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) === true;
    }

    public function fechar(): void
    {
        @fclose($this->socket);
    }
}
