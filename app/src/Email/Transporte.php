<?php

declare(strict_types=1);

namespace Pbl\Email;

/** Como o PBL4 entrega e-mail. Trocar de SMTP próprio para uma biblioteca só muda a implementação (ADR 0034). */
interface Transporte
{
    public const DESLIGADO = 'desligado';
    public const ARQUIVO = 'arquivo';
    public const SMTP = 'smtp';

    /** @throws \RuntimeException se não foi possível entregar (mensagem sem segredo) */
    public function enviar(Mensagem $mensagem): void;

    /** `desligado`, `arquivo` ou `smtp` */
    public function tipo(): string;
}
