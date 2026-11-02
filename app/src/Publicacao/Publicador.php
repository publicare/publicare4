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
        if (!is_dir($pasta) && !@mkdir($pasta, 0775, true) && !is_dir($pasta)) {
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
                try {
                    $this->publicar($site, $objeto);
                } catch (\InvalidArgumentException) {
                    continue; // endereço inválido (dado antigo): não derruba a publicação do site inteiro
                }
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
     * O que a passagem do tempo mudou e o cache ainda não reflete: objetos que já deviam estar no ar (a `data_publicacao`
     * chegou) mas não têm página, e objetos que já deviam ter saído (a `data_validade` passou, ou foram despublicados por outro
     * caminho) mas ainda têm página. Só olha o banco e a existência dos arquivos; não grava nada.
     *
     * @return array{entram: list<Objeto>, saem: list<Objeto>}
     */
    public function pendentes(Site $site): array
    {
        $saida = ['entram' => [], 'saem' => []];
        $raiz = $site->objetoRaizId === null ? null : $this->objetos->porId($site->objetoRaizId);
        if ($raiz === null) {
            return $saida;
        }
        $agora = gmdate('c');
        foreach ($this->objetos->todosDaSubarvore($site->id, $raiz->caminho) as $objeto) {
            try {
                $existe = is_file($this->caminhos->pagina($site->id, $objeto->urlAmigavel));
            } catch (\InvalidArgumentException) {
                continue; // endereço inválido (dado antigo): a publicação também o ignora
            }
            if ($objeto->visivel($agora) && !$existe) {
                $saida['entram'][] = $objeto;
            } elseif (!$objeto->visivel($agora) && $existe) {
                $saida['saem'][] = $objeto;
            }
        }

        return $saida;
    }

    /**
     * Aplica as transições por data: se há algo pendente (`pendentes`), republica o site inteiro (páginas que listam o conteúdo
     * que entrou ou saiu também mudam). Sem nada pendente não grava nada, então pode rodar a cada minuto sem custo.
     *
     * @return array{entraram: int, sairam: int}
     */
    public function aplicarAgendados(Site $site): array
    {
        $p = $this->pendentes($site);
        if ($p['entram'] !== [] || $p['saem'] !== []) {
            $this->publicarSite($site);
        }

        return ['entraram' => count($p['entram']), 'sairam' => count($p['saem'])];
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

    /**
     * Tira do cache a página do objeto (no endereço que ele tem no objeto passado): usado ao despublicar, mandar para a
     * lixeira ou trocar de endereço, para o efeito ser imediato, sem esperar a próxima publicação do site.
     */
    public function despublicar(Site $site, Objeto $objeto): void
    {
        $this->remover($site, $objeto);
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
