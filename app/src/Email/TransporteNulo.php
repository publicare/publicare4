<?php

declare(strict_types=1);

namespace Pbl\Email;

/** Padrão: sem e-mail configurado. Recuperação e convite por e-mail ficam desabilitados. */
final class TransporteNulo implements Transporte
{
    public function enviar(Mensagem $mensagem): void
    {
        throw new \RuntimeException('O envio de e-mail não está configurado');
    }

    public function tipo(): string
    {
        return self::DESLIGADO;
    }
}
