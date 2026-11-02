<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** O que o molde pode pedir ao sistema. O runtime não conhece banco: só esta interface. Somente leitura de conteúdo publicado. */
interface FonteDeDados
{
    /**
     * Filhos publicados do objeto.
     *
     * @param array<string, mixed> $objeto contexto do objeto pai (tem `id` e `site`)
     * @param array<string, int|string> $filtros classes, ordem, inicio, limite
     * @return list<array<string, mixed>>
     */
    public function filhos(array $objeto, array $filtros): array;

    /**
     * Busca no site do objeto corrente (subconjunto seguro; o motor completo vem na fase 1).
     *
     * @param array<string, mixed> $objeto
     * @param array<string, int|string> $filtros classes, ordem, inicio, limite, pai, niveis, campo/op/valor (e 2, 3), busca, expandir
     * @return list<array<string, mixed>>
     */
    public function localizar(array $objeto, array $filtros): array;

    /**
     * Um objeto publicado do mesmo site, por id, url ou título (nessa ordem de prioridade).
     *
     * @param array<string, mixed> $objeto
     * @return array<string, mixed>|null
     */
    public function objeto(array $objeto, string $id, string $url, string $titulo): ?array;

    /**
     * O objeto apontado por uma propriedade `ref` do objeto corrente (para `#autor.titulo` quando o molde não pediu
     * `expandir`). Só do mesmo site, publicado e vigente.
     *
     * @param array<string, mixed> $objeto
     * @return array<string, mixed>|null
     */
    public function referencia(array $objeto, string $propriedade): ?array;

    /**
     * O formulário público (HTML pronto, com tudo escapado) do formulário `$id` ou, se null, do objeto corrente. Vazio se o
     * formulário não existe neste site, não está no ar ou está mal configurado.
     *
     * @param array<string, mixed> $objeto
     */
    public function formulario(array $objeto, ?int $id): string;

    /**
     * @param array<string, mixed> $objeto
     */
    public function temFilhos(array $objeto, ?int $id): bool;

    /**
     * Sugestão de grafia parecida ("você quis dizer…", nível C do ADR 0010) para a última busca sem resultado. Só
     * calcula algo se `$filtros` tem `busca` e o site do objeto tem a opção ligada; null nos outros casos, inclusive
     * sem candidato confiável. Nunca inclui termo de conteúdo privado, não publicado ou na lixeira.
     *
     * @param array<string, mixed> $objeto
     * @param array<string, int|string> $filtros os mesmos filtros de `localizar` (só `busca` importa aqui)
     */
    public function sugestao(array $objeto, array $filtros): ?string;
}
