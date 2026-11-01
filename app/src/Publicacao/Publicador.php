<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Pbl\Caminhos;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Conteudo\Site;

/**
 * Renderiza ao publicar e grava a página no cache; a requisição pública só lê o arquivo (ADR 0003 §8).
 *
 * Páginas com `data_validade` ganham um arquivo `.expira` ao lado: a `Aplicacao` deixa de servi-las quando a data
 * passa, mesmo sem nova publicação. Páginas que deixaram de estar visíveis (despublicadas, vencidas, rascunho)
 * são removidas do cache a cada `publicarSite`.
 */
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
        $this->gravar($arquivo, $this->renderizador->renderizar($site, $objeto));
        if ($objeto->dataValidade !== null) {
            $this->gravar($arquivo . '.expira', $objeto->dataValidade);
        } elseif (is_file($arquivo . '.expira')) {
            unlink($arquivo . '.expira');
        }

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
        $agora = gmdate('c');
        $total = 0;
        $publicadas = [];
        foreach ($this->objetos->todosDaSubarvore($site->id, $raiz->caminho) as $objeto) {
            if ($objeto->visivel($agora)) {
                $this->publicar($site, $objeto);
                $publicadas[$objeto->urlAmigavel] = true;
                $total++;
            } else {
                $this->remover($site, $objeto);
            }
        }
        $this->limparOrfas($site, $publicadas);

        return $total;
    }

    /**
     * Remove do cache as páginas cujo endereço não pertence mais a nenhum objeto publicado (o objeto mudou de URL ou saiu
     * da árvore). O endereço antigo continua respondendo por redirecionamento (histórico de URLs).
     *
     * @param array<string, true> $publicadas URLs que devem existir
     */
    private function limparOrfas(Site $site, array $publicadas): void
    {
        $pasta = $this->caminhos->paginasDoSite($site->id);
        if (!is_dir($pasta)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pasta, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f instanceof \SplFileInfo || !str_ends_with($f->getFilename(), '.html')) {
                continue;
            }
            $relativo = substr($f->getPathname(), strlen($pasta) + 1, -5);
            if (!isset($publicadas[$relativo === 'index' ? '' : $relativo])) {
                @unlink($f->getPathname());
                @unlink($f->getPathname() . '.expira');
            }
        }
    }

    private function remover(Site $site, Objeto $objeto): void
    {
        $arquivo = $this->caminhos->pagina($site->id, $objeto->urlAmigavel);
        foreach ([$arquivo, $arquivo . '.expira'] as $caminho) {
            if (is_file($caminho) || is_link($caminho)) {
                unlink($caminho);
            }
        }
    }

    private function gravar(string $arquivo, string $conteudo): void
    {
        $temporario = $arquivo . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($temporario, $conteudo);
        rename($temporario, $arquivo);
    }
}
