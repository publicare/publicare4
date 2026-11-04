<?php

declare(strict_types=1);

namespace Pbl\Consulta;

/**
 * Descrição de uma busca de objetos. É a **única** forma de consultar conteúdo: o portal, os moldes, o admin e a API
 * montam uma `Consulta` e o `MotorDeConsulta` a executa. Sempre limitada a um site.
 */
final class Consulta
{
    /**
     * @param list<int>|null $classeIds restringe às classes (null = qualquer)
     * @param list<Filtro> $filtros todos precisam valer (E)
     * @param string $ordem `peso, -data_publicacao`, `titulo desc`, `autor.titulo`, `relevancia`… (padrão: `peso`; com `busca`, `relevancia`)
     * @param string|null $busca texto digitado: várias palavras (todas), prefixo, plural e relevância (nível B); null/em branco = sem busca
     * @param list<string> $tags slugs de tags (fase 5r): o objeto precisa ter todas (E); vazio = sem filtro
     * @param bool $apenasVisiveis true = só publicado e dentro da vigência (público); false = qualquer estado (admin)
     */
    public function __construct(
        public readonly int $siteId,
        public readonly ?int $paiId = null,
        public readonly ?int $niveis = null,
        public readonly ?array $classeIds = null,
        public readonly array $filtros = [],
        public readonly string $ordem = '',
        public readonly int $inicio = 0,
        public readonly int $limite = 100,
        public readonly bool $apenasVisiveis = true,
        public readonly ?string $busca = null,
        public readonly array $tags = [],
    ) {
    }
}
