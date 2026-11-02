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

    /**
     * Sugestão de grafia parecida ("você quis dizer…", nível C do ADR 0010) para quem digitou a consulta, dentro do
     * vocabulário **visível ao público** do site (conteúdo publicado e vigente; nunca um termo de conteúdo privado, não
     * publicado ou na lixeira). Null se não há nada para corrigir com confiança: já existe no vocabulário, é curta
     * demais, ou nenhum candidato está perto o bastante — melhor nenhuma sugestão do que uma errada.
     *
     * Quem chama decide **se** chama (a opção é por site, ADR 0010; este método não sabe de configuração de site).
     * Drivers de portais grandes (FTS5, tsvector, FULLTEXT, Meilisearch) podem devolver sempre null aqui e ainda
     * funcionar: a sugestão é um extra do nível C, não um requisito do nível B.
     */
    public function sugerir(int $siteId, string $consulta): ?string;
}
