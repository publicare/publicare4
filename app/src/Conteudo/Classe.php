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
     * @param bool $fixa classe essencial (ADR 0015): não se apaga, não se renomeia e não perde as propriedades da base
     *                   (`ClassesPadrao::nomesDaBase`); acrescentar propriedades continua permitido
     */
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly array $propriedades,
        public readonly string $descricao = '',
        public readonly bool $aceitaFilhos = true,
        public readonly array $filhas = [],
        public readonly int $versao = 1,
        public readonly bool $fixa = false,
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

        if (is_string($rotulo) && $rotulo !== '') {
            return $rotulo;
        }
        $nome = str_replace('_', ' ', (string) ($propriedade['nome'] ?? ''));

        return $nome === '' ? '' : mb_strtoupper(mb_substr($nome, 0, 1)) . mb_substr($nome, 1);
    }

    /** Este tipo aceita conteúdo do tipo `$filha` dentro dele? (`aceita_filhos` e a lista `filhas`; vazia = qualquer tipo) */
    public function aceita(Classe $filha): bool
    {
        return $this->aceitaFilhos && ($this->filhas === [] || in_array($filha->nome, $this->filhas, true));
    }
}
