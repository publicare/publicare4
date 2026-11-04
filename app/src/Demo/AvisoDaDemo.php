<?php

declare(strict_types=1);

namespace Pbl\Demo;

use Pbl\Caminhos;

/** O aviso discreto "Demo: reinicia em DD/MM às 04:00" (pele pública e painel). Vazio fora de demo; nunca lança. */
final class AvisoDaDemo
{
    public static function texto(Caminhos $caminhos): string
    {
        try {
            if (!ConfigDaDemo::ativa($caminhos)) {
                return '';
            }

            return 'Demo: reinicia em ' . AgendaDaDemo::rotulo((new AgendaDaDemo($caminhos))->proximo());
        } catch (\Throwable) {
            return '';
        }
    }

    /** Faixa fina fixa no canto da página pública (estilo embutido: serve a qualquer pele). */
    public static function faixa(Caminhos $caminhos): string
    {
        $t = self::texto($caminhos);

        return $t === '' ? '' : '<div class="pbl-demo-aviso" role="note" style="position:fixed;bottom:0;right:0;z-index:2147483000;margin:0;padding:.2rem .6rem;'
            . 'font:12px/1.4 system-ui,sans-serif;color:#fff;background:rgba(33,37,41,.85);border-top-left-radius:.4rem">' . htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
    }

    /** Linha no topo do painel. */
    public static function linhaDoPainel(Caminhos $caminhos): string
    {
        $t = self::texto($caminhos);

        return $t === '' ? '' : '<div class="pbl-demo-aviso small text-center text-bg-secondary py-1" role="note">' . htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
    }
}
