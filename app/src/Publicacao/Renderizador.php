<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Pbl\Caminhos;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\Site;
use Pbl\Demo\AvisoDaDemo;
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
        private readonly ?Aparencia $aparencia = null,
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
        // Pele efetiva (ADR 0022): a do ancestral mais próximo que tenha uma, senão a do site. Páginas dinâmicas (`busca`,
        // `erro404`) usam a do site, porque não pertencem a nenhuma subárvore.
        $efetiva = $moldeNomeado === null && $this->aparencia !== null ? $this->aparencia->da($site, $objeto) : ['pele' => $site->pele, 'raiz' => null];
        $nomeDaPele = $peleParaPreVisualizar ?? $efetiva['pele'];
        $pele = new ResolvedorDePele($this->caminhos, $nomeDaPele, $sobrescritas);
        $nome = $moldeNomeado ?? $this->escolherMolde($site, $objeto, $classe->nome, $pele);
        $molde = $pele->fonte($nome);

        $incluidor = fn (string $arquivo, array $o, Runtime $r): string => ($this->compilador->carregar($pele->fonte($arquivo), $pele))($o, $r);
        $runtime = new Runtime($this->fonte, $incluidor);
        $variaveis = array_merge(['pagina' => 1, 'q' => '', 'q_url' => ''], $variaveis);
        $variaveis['q_url'] = rawurlencode((string) $variaveis['q']);
        foreach ($variaveis as $chave => $valor) {
            $runtime->definir($chave, $valor);
        }

        try {
            return $this->comAvisoDeDemo(($this->compilador->carregar($molde, $pele))($this->contexto($site, $objeto, $nomeDaPele, $efetiva['raiz']), $runtime));
        } catch (\Pbl\Linguagem\ErroSintaxe $e) {
            throw $e->arquivo === null ? $e->noArquivo("{$nomeDaPele}/{$nome}.pbl") : $e;
        }
    }

    /** Em instalação de demonstração, a faixa "Demo: reinicia em DD/MM às 04:00" vai antes de `</body>`; fora dela, o HTML sai como veio. */
    private function comAvisoDeDemo(string $html): string
    {
        $faixa = AvisoDaDemo::faixa($this->caminhos);
        $fim = $faixa === '' ? false : strripos($html, '</body>');

        return $fim === false ? $html : substr($html, 0, $fim) . $faixa . substr($html, $fim);
    }

    /** Molde do objeto (se existir na pele) > `home` na raiz do site > molde da classe > `padrao`. */
    private function escolherMolde(Site $site, Objeto $objeto, string $classe, ResolvedorDePele $pele): string
    {
        if ($objeto->molde !== null && $pele->existe($objeto->molde)) {
            return $objeto->molde;
        }
        if ($site->objetoRaizId === $objeto->id && $pele->existe('home')) {
            return 'home';
        }

        return $pele->existe($classe) ? $classe : 'padrao';
    }

    /**
     * Contexto do objeto mais o que depende da pele: `#pele`, `#pele_url`, `#pele_v` (versão dos assets), `#raiz_pele`
     * (onde a pele começa: o menu parte dele) e `#migalhas` (HTML escapado).
     *
     * @return array<string, mixed>
     */
    private function contexto(Site $site, Objeto $objeto, string $pele, ?int $raizDaPele): array
    {
        $contexto = $this->fonte->contextoDe($objeto);
        if ($this->aparencia === null) {
            return $contexto;
        }
        $inicio = $raizDaPele ?? $site->objetoRaizId;
        $contexto['pele'] = $pele;
        $contexto['pele_url'] = $this->aparencia->urlDaPele($pele);
        $contexto['pele_v'] = $this->aparencia->versaoDaPele($pele);
        $contexto['barra_v'] = Aparencia::versaoDaBarra();
        $contexto['raiz_pele_url'] = $this->aparencia->urlDaRaiz($site, $raizDaPele);
        $contexto['raiz_pele'] = $inicio ?? '';
        $contexto['migalhas'] = $this->aparencia->migalhas($objeto, $inicio);

        return $contexto;
    }
}
