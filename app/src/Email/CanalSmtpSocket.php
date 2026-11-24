<?php

declare(strict_types=1);

namespace Pbl\Email;

/** Canal real: `stream_socket_client` com timeouts curtos e verificação de certificado (exceto `nenhuma`, só loopback). */
final class CanalSmtpSocket implements CanalSmtp
{
    /** @var resource */
    private $socket;

    public function __construct(string $host, int $porta, bool $tlsImplicito, int $timeout = 10, bool $semTls = false)
    {
        if ($semTls && ($tlsImplicito || !ConfigDeEmail::hostEhLoopback($host))) {
            throw new FalhaDeEmail('Conexão sem TLS só é permitida para o servidor da própria máquina', FalhaDeEmail::CONFIGURACAO);
        }
        $contexto = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $alvo = str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host;
        $s = @stream_socket_client(($tlsImplicito ? 'ssl://' : 'tcp://') . $alvo . ':' . $porta, $codigo, $texto, $timeout, STREAM_CLIENT_CONNECT, $contexto);
        if ($s === false) {
            $tls = $tlsImplicito && preg_match('/ssl|tls|certificate/i', (string) $texto) === 1;
            throw new FalhaDeEmail($tls ? 'Falha ao negociar TLS com o servidor de e-mail' : 'Não foi possível conectar ao servidor de e-mail', $tls ? FalhaDeEmail::TLS : FalhaDeEmail::REDE);
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
                throw new FalhaDeEmail('O servidor de e-mail não respondeu', FalhaDeEmail::REDE);
            }
            $texto .= $linha;
        } while (strlen($linha) >= 4 && $linha[3] === '-');

        return [(int) substr($texto, 0, 3), $texto];
    }

    public function escrever(string $dados): void
    {
        if (@fwrite($this->socket, $dados) === false) {
            throw new FalhaDeEmail('Falha ao enviar ao servidor de e-mail', FalhaDeEmail::REDE);
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
