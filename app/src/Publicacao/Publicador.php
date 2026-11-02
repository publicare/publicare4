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
 *
 * Menu (ADR 0016): `publicarSite` reconstrói o snapshot do menu uma vez e todas as páginas o reutilizam. Publicar ou
 * despublicar um objeto avulso confere o hash do menu; se mudou, o site inteiro fica pendente e é republicado na hora
 * (site pequeno) ou por `bin/pbl publicar`/`agendados` (site grande).
 */
final class Publicador
{
    /** Até este tamanho o site é republicado na própria requisição quando o menu muda; acima, fica pendente para o cron. */
    public const LIMITE_SINCRONO = 300;

    private bool $emLote = false;

    public function __construct(
        private readonly Caminhos $caminhos,
        private readonly ObjetoRepositorio $objetos,
        private readonly Renderizador $renderizador,
        private readonly ?MenuDoSite $menu = null,
    ) {
    }

    public function publicar(Site $site, Objeto $objeto): string
    {
        $mudouMenu = !$this->emLote && $this->menu?->verificar($site, 'publicacao') === true;
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
        if ($mudouMenu) {
            $this->republicarPorMenu($site);
        }

        return $arquivo;
    }

    /** O menu do site tem uma republicação pendente (alguma edição mudou o hash e o site ainda não foi refeito)? */
    public function menuPendente(Site $site): bool
    {
        return $this->menu?->pendente($site) ?? false;
    }

    private function republicarPorMenu(Site $site): void
    {
        if ($this->objetos->contarDoSite($site->id) <= self::LIMITE_SINCRONO) {
            $this->publicarSite($site);
        }
    }

    /**
     * O domínio principal mudou: `canonical` e `og:*` das páginas publicadas trazem o host antigo. Marca o site como pendente
     * e, se é pequeno, republica na hora; senão fica para `republicarEmLotes` (painel) ou `bin/pbl publicar`.
     */
    public function hostMudou(Site $site): void
    {
        $this->menu?->marcarPendente($site->id);
        $this->republicarPorMenu($site);
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
        $this->menu?->reconstruir($site); // o snapshot é refeito uma vez e as páginas só o leem
        $this->emLote = true;
        try {
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
        } finally {
            $this->emLote = false;
        }
        $this->limparOrfas($site, $publicadas);
        $this->menu?->limparPendente($site);

        return $total;
    }

    /**
     * Republica o site em lotes, para um site grande não estourar o tempo de uma requisição (painel). Cada chamada trata até
     * `$tamanho` objetos com id maior que `$depoisDoId`, ou para ao passar de `$segundos`. O primeiro lote (`$depoisDoId` = 0)
     * refaz o snapshot do menu; o último remove as páginas órfãs e limpa o pendente. Idempotente: pode ser refeito do zero.
     *
     * @return array{processadas: int, ultimo: int, concluido: bool}
     */
    public function republicarLote(Site $site, int $depoisDoId = 0, int $tamanho = 100, float $segundos = 10.0): array
    {
        $raiz = $site->objetoRaizId === null ? null : $this->objetos->porId($site->objetoRaizId);
        if ($raiz === null) {
            return ['processadas' => 0, 'ultimo' => $depoisDoId, 'concluido' => true];
        }
        if ($depoisDoId === 0) {
            $this->menu?->reconstruir($site);
        }
        $inicio = microtime(true);
        $agora = gmdate('c');
        $processadas = 0;
        $ultimo = $depoisDoId;
        $this->emLote = true;
        try {
            $lote = $this->objetos->loteDaSubarvore($site->id, $raiz->caminho, $depoisDoId, $tamanho);
            foreach ($lote as $objeto) {
                if (microtime(true) - $inicio > $segundos) {
                    break;
                }
                try {
                    if ($objeto->visivel($agora)) {
                        $this->publicar($site, $objeto);
                    } else {
                        $this->remover($site, $objeto);
                    }
                } catch (\InvalidArgumentException) {
                    // endereço inválido (dado antigo): não derruba a republicação
                }
                $ultimo = $objeto->id;
                $processadas++;
            }
        } finally {
            $this->emLote = false;
        }
        $concluido = $processadas === count($lote) && count($lote) < $tamanho;
        if ($concluido) {
            $publicadas = [];
            foreach ($this->objetos->indicePublicadoDaSubarvore($site->id, $raiz->caminho) as $p) {
                $publicadas[$p['url']] = true;
            }
            $this->limparOrfas($site, $publicadas);
            $this->menu?->limparPendente($site);
        }

        return ['processadas' => $processadas, 'ultimo' => $ultimo, 'concluido' => $concluido];
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
        $this->menu?->verificar($site, 'agendados'); // a passagem do tempo também muda o menu (item que entra ou sai por data)
        $p = $this->pendentes($site);
        if ($p['entram'] !== [] || $p['saem'] !== [] || $this->menuPendente($site)) {
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
        if ($this->menu?->verificar($site, 'publicacao') === true) {
            $this->republicarPorMenu($site);
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
        if (@file_put_contents($temporario, $conteudo) === false) {
            throw new \RuntimeException("Não foi possível gravar {$arquivo}");
        }
        if (!@rename($temporario, $arquivo)) {
            @unlink($temporario);
            throw new \RuntimeException("Não foi possível gravar {$arquivo}");
        }
    }
}
