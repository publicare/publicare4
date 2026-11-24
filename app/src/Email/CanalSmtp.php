<?php

declare(strict_types=1);

namespace Pbl\Email;

/** Conexão de texto com o servidor SMTP (separada para testar o protocolo sem rede). */
interface CanalSmtp
{
    /**
     * Lê uma resposta completa (todas as linhas `NNN-`…`NNN `): código e texto.
     *
     * @return array{int, string}
     */
    public function ler(): array;

    public function escrever(string $dados): void;

    /** Liga TLS na conexão aberta (STARTTLS), validando o certificado. */
    public function ligarTls(): bool;

    public function fechar(): void;
}
