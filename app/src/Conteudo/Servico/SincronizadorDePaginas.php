<?php

declare(strict_types=1);

namespace Pbl\Conteudo\Servico;

use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Conteudo\SiteRepositorio;
use Pbl\Conteudo\TagRepositorio;
use Pbl\Publicacao\Publicador;

/**
 * Mantém o cache público de acordo com o banco depois de uma gravação (regra que era privada do painel e agora o painel e o
 * serviço de conteúdo compartilham). Falha de publicação nunca desfaz a gravação: devolve false e a próxima republicação cura.
 */
final class SincronizadorDePaginas
{
    public function __construct(
        private readonly ObjetoRepositorio $objetos,
        private readonly SiteRepositorio $sites,
        private readonly Publicador $publicador,
        private readonly TagRepositorio $tags,
    ) {
    }

    /** Regrava (se visível) ou tira do cache (se não) a página de cada objeto, com o estado atual do banco. false = alguma falhou. */
    public function sincronizar(?Objeto ...$objetos): bool
    {
        $vistos = [];
        $ok = true;
        $agora = gmdate('c');
        foreach ($objetos as $o) {
            if ($o === null || isset($vistos[$o->id])) {
                continue;
            }
            $vistos[$o->id] = true;
            $atual = $this->objetos->porId($o->id);
            $site = $atual === null ? null : $this->sites->porId($atual->siteId);
            if ($atual === null || $site === null) {
                continue;
            }
            try {
                if ($atual->visivel($agora)) {
                    $this->publicador->publicar($site, $atual);
                } else {
                    $this->publicador->despublicar($site, $atual);
                }
            } catch (\Throwable) {
                $ok = false;
            }
        }

        return $ok;
    }

    /** Tira do cache a página no endereço que o objeto tinha (o antigo passa a redirecionar pelo histórico de URLs). */
    public function tirarDoAr(Objeto $antigo): bool
    {
        $site = $this->sites->porId($antigo->siteId);
        if ($site === null) {
            return false;
        }
        try {
            $this->publicador->despublicar($site, $antigo);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /** Publicar, despublicar, agendar ou apagar um objeto com tags muda a nuvem e as listas por tag já publicadas. */
    public function visibilidadeMudou(Objeto $antes, Objeto $depois): bool
    {
        $agora = gmdate('c');

        return $antes->visivel($agora) !== $depois->visivel($agora) && $this->tags->porObjeto($depois->id) !== [];
    }

    /** O conjunto de tags (ou a visibilidade) mudou: republica o site, síncrono até o limite, o resto fica para o cron. */
    public function republicarPorTags(Objeto $antes, Objeto $depois): bool
    {
        $agora = gmdate('c');
        if (!$antes->visivel($agora) && !$depois->visivel($agora)) {
            return true;
        }
        $site = $this->sites->porId($depois->siteId);
        try {
            return $site !== null && $this->publicador->republicarPorConfiguracao($site);
        } catch (\Throwable) {
            return false;
        }
    }
}
