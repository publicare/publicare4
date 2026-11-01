<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Pbl\Caminhos;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Conteudo\Site;

/** Renderiza ao publicar e grava a página no cache; a requisição pública só lê o arquivo (ADR 0003 §8). */
final class Publicador
{
    public function __construct(
        private readonly Caminhos $caminhos,
        private readonly ObjetoRepositorio $objetos,
        private readonly Renderizador $renderizador,
    ) {
    }

    public function publicar(Site $site, Objeto $objeto): string
    {
        $arquivo = $this->caminhos->pagina($site->id, $objeto->urlAmigavel);
        $pasta = dirname($arquivo);
        if (!is_dir($pasta) && !mkdir($pasta, 0775, true) && !is_dir($pasta)) {
            throw new \RuntimeException("Não foi possível criar {$pasta}");
        }
        $temporario = $arquivo . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($temporario, $this->renderizador->renderizar($site, $objeto));
        rename($temporario, $arquivo);

        return $arquivo;
    }

    /** @return int quantidade de páginas publicadas */
    public function publicarSite(Site $site): int
    {
        if ($site->objetoRaizId === null) {
            return 0;
        }
        $raiz = $this->objetos->porId($site->objetoRaizId);
        if ($raiz === null) {
            return 0;
        }
        $total = 0;
        foreach ($this->objetos->publicadosDaSubarvore($site->id, $raiz->caminho) as $objeto) {
            $this->publicar($site, $objeto);
            $total++;
        }

        return $total;
    }
}
