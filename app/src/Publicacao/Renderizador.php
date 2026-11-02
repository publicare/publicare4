<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Pbl\Caminhos;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\Site;
use Pbl\Linguagem\Compilador;
use Pbl\Linguagem\Runtime;

/** Executa o molde da pele (compilado e em cache) para um objeto. */
final class Renderizador
{
    public function __construct(
        private readonly Caminhos $caminhos,
        private readonly FonteRepositorio $fonte,
        private readonly ClasseRepositorio $classes,
        private readonly Compilador $compilador,
    ) {
    }

    /** A pele do site tem este molde? (`busca`, `erro404`…) */
    public function temMolde(Site $site, string $nome): bool
    {
        return (new ResolvedorDePele($this->caminhos, $site->pele))->existe($nome);
    }

    /**
     * `$peleParaPreVisualizar` e `$sobrescritas` (fase 5j): só a pré-visualização isolada de um rascunho os usa, para
     * renderizar com uma pele e um conteúdo que ainda não foram gravados em disco, sem tocar no cache público nem na
     * pele realmente ativa do site. Sem eles, o comportamento é o mesmo da fase 5e (pele do próprio `$site`).
     *
     * @param string|null $moldeNomeado molde da pele a usar no lugar do molde da classe do objeto (páginas dinâmicas: `busca`, `erro404`)
     * @param array<string, scalar> $variaveis variáveis do molde (`$q`, `$pagina`) definidas antes de executar
     * @param array<string, string> $sobrescritas nome do molde (sem `.pbl`) => conteúdo
     */
    public function renderizar(Site $site, Objeto $objeto, ?string $moldeNomeado = null, array $variaveis = [], ?string $peleParaPreVisualizar = null, array $sobrescritas = []): string
    {
        $classe = $this->classes->porId($objeto->classeId)
            ?? throw new \RuntimeException("Classe {$objeto->classeId} não existe");
        $nomeDaPele = $peleParaPreVisualizar ?? $site->pele;
        $pele = new ResolvedorDePele($this->caminhos, $nomeDaPele, $sobrescritas);
        $nome = $moldeNomeado ?? ($pele->existe($classe->nome) ? $classe->nome : 'padrao');
        $molde = $pele->fonte($nome);

        $incluidor = fn (string $arquivo, array $o, Runtime $r): string => ($this->compilador->carregar($pele->fonte($arquivo), $pele))($o, $r);
        $runtime = new Runtime($this->fonte, $incluidor);
        $variaveis = array_merge(['pagina' => 1, 'q' => '', 'q_url' => ''], $variaveis);
        $variaveis['q_url'] = rawurlencode((string) $variaveis['q']);
        foreach ($variaveis as $chave => $valor) {
            $runtime->definir($chave, $valor);
        }

        try {
            return ($this->compilador->carregar($molde, $pele))($this->fonte->contextoDe($objeto), $runtime);
        } catch (\Pbl\Linguagem\ErroSintaxe $e) {
            throw $e->arquivo === null ? $e->noArquivo("{$nomeDaPele}/{$nome}.pbl") : $e;
        }
    }
}
