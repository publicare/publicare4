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

/** Executa o molde da pele (compilado e em cache) para um objeto. */
final class Renderizador
{
    private readonly FonteRepositorio $fonte;

    public function __construct(
        private readonly Caminhos $caminhos,
        ObjetoRepositorio $objetos,
        private readonly ClasseRepositorio $classes,
        private readonly Compilador $compilador,
    ) {
        $this->fonte = new FonteRepositorio($objetos, $classes);
    }

    public function renderizar(Site $site, Objeto $objeto): string
    {
        $classe = $this->classes->porId($objeto->classeId)
            ?? throw new \RuntimeException("Classe {$objeto->classeId} não existe");
        $pele = new ResolvedorDePele($this->caminhos, $site->pele);
        $nome = $pele->existe($classe->nome) ? $classe->nome : 'padrao';
        $molde = $pele->fonte($nome);

        $incluidor = fn (string $arquivo, array $o, Runtime $r): string => ($this->compilador->carregar($pele->fonte($arquivo), $pele))($o, $r);
        $runtime = new Runtime($this->fonte, $incluidor);

        try {
            return ($this->compilador->carregar($molde, $pele))(ContextoDeObjeto::de($objeto, $classe->nome), $runtime);
        } catch (\Pbl\Linguagem\ErroSintaxe $e) {
            throw $e->arquivo === null ? $e->noArquivo("{$site->pele}/{$nome}.pbl") : $e;
        }
    }
}
