<?php

declare(strict_types=1);

namespace Pbl\Busca;

/**
 * Motor de busca de texto (ADR 0010). O driver padrão (`BuscaSql`) usa uma tabela do próprio banco; portais grandes podem
 * trocar por outro driver (FTS5, tsvector, FULLTEXT, Meilisearch) sem mudar os moldes nem o `MotorDeConsulta`.
 *
 * O driver **não decide visibilidade**: estado e datas de publicação são aplicados pelo `MotorDeConsulta`, como em toda
 * consulta, então um objeto que sai do ar ou vence deixa de aparecer sem esperar reindexação.
 */
interface BuscaInterface
{
    /**
     * (Re)grava o índice de um objeto. `$campos` = título, descrição e os textos das propriedades (já sem HTML).
     *
     * @param array{titulo: string, descricao: string, corpo: string} $campos
     */
    public function indexar(int $objetoId, array $campos): void;

    public function remover(int $objetoId): void;

    /**
     * Condição e pontos para a consulta digitada, sobre o objeto de alias `$alias` (tabela `objeto`). `$prefixo` torna únicos
     * os nomes dos parâmetros. Texto em branco = null (sem filtro). Todo texto do usuário vai como parâmetro.
     */
    public function fragmento(string $consulta, string $alias, string $prefixo): ?FragmentoDeBusca;
}
