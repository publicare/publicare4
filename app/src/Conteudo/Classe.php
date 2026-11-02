<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

/**
 * Definição de um tipo de conteúdo criado pelo admin (sem código).
 *
 * Cada propriedade tem `nome` (técnico, fixo depois de criado: é o que os moldes usam), `tipo`, `indexavel` e, opcionais:
 * `classe` (só em `ref`: restringe a classe do alvo), `rotulo` (o nome que a equipe vê), `obrigatorio` e `arquivada` (some dos
 * formulários, mas os valores continuam guardados e os moldes continuam lendo). A ordem da lista é a ordem dos campos.
 *
 * @phpstan-type Propriedade array{nome: string, tipo: string, indexavel: bool, classe?: string, rotulo?: string, obrigatorio?: bool, arquivada?: bool, tipos?: list<string>, tamanho_max_mb?: int}
 */
final class Classe
{
    /**
     * Tipos: `texto`, `numero`, `html`, `data` (aaaa-mm-dd[ hh:mm:ss]), `booleano` e `ref` (aponta para outro objeto do
     * mesmo site; `classe` opcional restringe a classe do alvo).
     *
     * @param list<Propriedade> $propriedades
     * @param list<string> $filhas nomes das classes aceitas como filhas; vazia (com `$aceitaFilhos`) = qualquer classe
     */
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly array $propriedades,
        public readonly string $descricao = '',
        public readonly bool $aceitaFilhos = true,
        public readonly array $filhas = [],
        public readonly int $versao = 1,
    ) {
    }

    /**
     * O nome que a equipe vê para a propriedade: o rótulo, ou o nome técnico se não houver.
     *
     * @param array<string, mixed> $propriedade
     */
    public static function rotuloDe(array $propriedade): string
    {
        $rotulo = $propriedade['rotulo'] ?? '';

        return is_string($rotulo) && $rotulo !== '' ? $rotulo : (string) ($propriedade['nome'] ?? '');
    }

    /** Este tipo aceita conteúdo do tipo `$filha` dentro dele? (`aceita_filhos` e a lista `filhas`; vazia = qualquer tipo) */
    public function aceita(Classe $filha): bool
    {
        return $this->aceitaFilhos && ($this->filhas === [] || in_array($filha->nome, $this->filhas, true));
    }
}
