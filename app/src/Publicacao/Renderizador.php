<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Pbl\Caminhos;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Conteudo\Site;
use Pbl\Linguagem\Compilador;
use Pbl\Linguagem\Runtime;

/** Monta o contexto do objeto (filhos em lote) e executa o molde da pele compilado. */
final class Renderizador
{
    private readonly Runtime $runtime;

    public function __construct(
        private readonly Caminhos $caminhos,
        private readonly ObjetoRepositorio $objetos,
        private readonly ClasseRepositorio $classes,
        private readonly Compilador $compilador,
    ) {
        $this->runtime = new Runtime();
    }

    public function renderizar(Site $site, Objeto $objeto): string
    {
        $classe = $this->classes->porId($objeto->classeId)
            ?? throw new \RuntimeException("Classe {$objeto->classeId} não existe");
        $molde = $this->moldeDaPele($site->pele, $classe->nome);

        $filhos = $this->objetos->filhosEmLote([$objeto->id])[$objeto->id] ?? [];
        $contexto = $this->contexto($objeto);
        $contexto['filhos'] = array_map($this->contexto(...), $filhos);

        return ($this->compilador->carregar($molde))($contexto, $this->runtime);
    }

    /** @return array<string, mixed> */
    private function contexto(Objeto $objeto): array
    {
        return $objeto->dados + ['titulo' => $objeto->titulo, 'url' => '/' . $objeto->urlAmigavel];
    }

    private function moldeDaPele(string $pele, string $classe): string
    {
        if (preg_match('/^[a-z0-9_-]+$/', $pele) !== 1) {
            throw new \RuntimeException("Nome de pele inválido: {$pele}");
        }
        foreach ([$classe . '.pbl', 'padrao.pbl'] as $arquivo) {
            $caminho = $this->caminhos->peles() . "/{$pele}/{$arquivo}";
            if (is_file($caminho)) {
                return (string) file_get_contents($caminho);
            }
        }

        throw new \RuntimeException("A pele {$pele} não tem molde para a classe {$classe}");
    }
}
