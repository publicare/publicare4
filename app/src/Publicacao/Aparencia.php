<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Doctrine\DBAL\Connection;
use Pbl\Caminhos;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\Site;

/**
 * Pele e molde efetivos de um objeto (ADR 0022). Pele: a do ancestral mais próximo que tenha uma (inclusive o próprio
 * objeto), senão a do site; o ancestral onde ela começa é a **raiz da pele** (de onde partem o menu e as migalhas). Pele que
 * sumiu do disco é ignorada (vale a de cima). Cada consulta lê o banco na hora: nada fica em memória entre publicações.
 */
final class Aparencia
{
    /** Onde o PHP serve os arquivos das peles (`dados/peles/<pele>/assets/`). */
    public const URL_ASSETS = '/_pbl/peles';

    /** @var array<string, string> */
    private array $versoes = [];

    public function __construct(private readonly Caminhos $caminhos, private readonly Connection $conexao)
    {
    }

    /** @return array{pele: string, raiz: int|null} `raiz`: id do objeto onde a pele começa; null = a pele do próprio site */
    public function da(Site $site, Objeto $objeto): array
    {
        $ids = $this->idsDoCaminho($objeto);
        if ($ids === []) {
            return ['pele' => $site->pele, 'raiz' => null];
        }
        $this->consultar();
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT id, pele FROM objeto WHERE pele IS NOT NULL AND id IN (' . implode(',', $ids) . ')',
        );
        $porId = [];
        foreach ($linhas as $l) {
            $porId[(int) $l['id']] = (string) $l['pele'];
        }
        foreach (array_reverse($ids) as $id) {
            if (isset($porId[$id]) && $this->peleExiste($porId[$id])) {
                return ['pele' => $porId[$id], 'raiz' => $id];
            }
        }

        return ['pele' => $site->pele, 'raiz' => null];
    }

    /** Endereço (sem barra final) dos arquivos da pele; vai para o molde como `#pele_url`. */
    public function urlDaPele(string $pele): string
    {
        return self::URL_ASSETS . '/' . $pele;
    }

    /** Versão (curta) do script da barra de edição (`public/_pbl/assets/pbl-barra.js` e `.css`), para o `?v=`. */
    public static function versaoDaBarra(): string
    {
        $partes = '';
        foreach (['pbl-barra.js', 'pbl-barra.css'] as $nome) {
            $arquivo = dirname(__DIR__, 3) . '/public/_pbl/assets/' . $nome;
            $partes .= is_file($arquivo) ? (string) hash_file('crc32b', $arquivo) : '-';
        }

        return substr(hash('sha256', $partes), 0, 8);
    }

    /** Versão (curta) dos arquivos da pele, para o `?v=` dos assets: muda quando um arquivo muda. */
    public function versaoDaPele(string $pele): string
    {
        if (isset($this->versoes[$pele])) {
            return $this->versoes[$pele];
        }
        $pasta = $this->caminhos->peles() . '/' . $pele . '/assets';
        $partes = [];
        if (is_dir($pasta)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pasta, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $arquivo) {
                if ($arquivo instanceof \SplFileInfo && $arquivo->isFile() && !$arquivo->isLink()) {
                    $partes[] = substr($arquivo->getPathname(), strlen($pasta)) . '|' . $arquivo->getSize() . '|' . hash_file('crc32b', $arquivo->getPathname());
                }
            }
        }
        sort($partes);

        return $this->versoes[$pele] = substr(hash('sha256', implode("\n", $partes)), 0, 8);
    }

    /**
     * Migalhas (HTML já escapado) da raiz da pele até o objeto. Vazio na própria raiz. Só entram ancestrais publicados.
     *
     * @param int|null $inicioId raiz da pele (ou a do site)
     */
    public function migalhas(Objeto $objeto, ?int $inicioId): string
    {
        if ($inicioId === null || $inicioId === $objeto->id) {
            return '';
        }
        $ids = $this->idsDoCaminho($objeto);
        $posicao = array_search($inicioId, $ids, true);
        if ($posicao === false) {
            return '';
        }
        $ids = array_slice($ids, $posicao);
        $this->consultar();
        $linhas = $this->conexao->fetchAllAssociative(
            "SELECT id, titulo, rotulo_menu, url_amigavel FROM objeto WHERE status = 'publicado' AND id IN (" . implode(',', $ids) . ')',
        );
        $porId = [];
        foreach ($linhas as $l) {
            $porId[(int) $l['id']] = $l;
        }
        $itens = '';
        foreach ($ids as $id) {
            $l = $porId[$id] ?? null;
            if ($id === $objeto->id) {
                $itens .= '<li class="breadcrumb-item active" aria-current="page">' . self::h($objeto->titulo) . '</li>';
            } elseif ($l !== null) {
                $rotulo = $id === $inicioId ? 'Início' : (trim((string) $l['rotulo_menu']) !== '' ? (string) $l['rotulo_menu'] : (string) $l['titulo']);
                $itens .= '<li class="breadcrumb-item"><a href="' . self::h('/' . $l['url_amigavel']) . '">' . self::h($rotulo) . '</a></li>';
            }
        }

        return '<nav class="pbl-migalhas" aria-label="Migalhas"><ol class="breadcrumb">' . $itens . '</ol></nav>';
    }

    private function peleExiste(string $pele): bool
    {
        return preg_match('/^[a-z0-9_-]+$/D', $pele) === 1 && is_dir($this->caminhos->peles() . '/' . $pele);
    }

    /** @return list<int> ids do `caminho` (`/1/5/9/`), da raiz do site ao próprio objeto */
    private function idsDoCaminho(Objeto $objeto): array
    {
        $ids = array_values(array_filter(array_map('intval', explode('/', $objeto->caminho))));

        return $ids === [] ? [] : $ids;
    }

    private function consultar(): void
    {
        $this->consultas++;
    }

    /** Quantas consultas ao banco esta instância fez (testes de desempenho). */
    public int $consultas = 0;

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
