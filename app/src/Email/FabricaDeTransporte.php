<?php

declare(strict_types=1);

namespace Pbl\Email;

/** Monta o {@see Transporte} da configuração. */
final class FabricaDeTransporte
{
    public static function criar(ConfigDeEmail $c, string $pastaDaCaixa, ?\Closure $abrirCanal = null): Transporte
    {
        if ($c->valida() !== null) {
            return new TransporteNulo();
        }

        return match ($c->driver) {
            Transporte::ARQUIVO => new TransporteArquivo($pastaDaCaixa, $c->remetente),
            Transporte::SMTP => new TransporteSmtp(
                $abrirCanal ?? static fn (): CanalSmtp => new CanalSmtpSocket($c->host, $c->porta, $c->seguranca === ConfigDeEmail::SEGURANCA_TLS),
                $c->seguranca === ConfigDeEmail::SEGURANCA_STARTTLS, $c->usuario, $c->senha, $c->remetente,
            ),
            default => new TransporteNulo(),
        };
    }
}
