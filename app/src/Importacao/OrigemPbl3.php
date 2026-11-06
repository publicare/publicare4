<?php

declare(strict_types=1);

namespace Pbl\Importacao;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Leitura da base do PBL3 (ADR 0027). **Só lê**: nenhuma instrução além de `SELECT`, sempre com os nomes físicos do
 * {@see MapaDeTabelas} e com cada coluna devolvida pelo nome lógico. Tudo é lido em lotes (por faixa de id ou por lista de ids),
 * porque a máquina do dono pode ter pouca memória e a base, milhões de linhas.
 */
final class OrigemPbl3
{
    /** @var array<string, string> tabela.coluna lógica => nome físico que realmente existe (só para as grafias alternativas) */
    private array $resolvidas = [];

    public function __construct(private readonly Connection $conexao, private readonly MapaDeTabelas $mapa, private readonly Relatorio $relatorio)
    {
    }

    public function existe(string $logica): bool
    {
        return $this->conexao->createSchemaManager()->tablesExist([$this->mapa->tabela($logica)]);
    }

    public function contar(string $logica): int
    {
        return (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM ' . $this->tabela($logica));
    }

    /** @return list<array<string, mixed>> */
    public function classes(): array
    {
        return $this->ler('classe', ['cod_classe', 'nome', 'prefixo', 'descricao', 'temfilhos'], '', [], [], 'cod_classe');
    }

    /** @return list<array<string, mixed>> */
    public function propriedades(): array
    {
        return $this->ler('propriedade', ['cod_propriedade', 'cod_classe', 'cod_tipodado', 'cod_referencia_classe', 'campo_ref', 'nome', 'posicao', 'descricao', 'rotulo', 'obrigatorio'], '', [], [], 'cod_classe, posicao, cod_propriedade');
    }

    /** @return array<int, string> id da pele => nome curto (o prefixo, ou o nome se não há prefixo) */
    public function peles(): array
    {
        if (!$this->existe('pele')) {
            return [];
        }
        $saida = [];
        foreach ($this->ler('pele', ['cod_pele', 'nome', 'prefixo'], '', [], [], 'cod_pele') as $l) {
            $nome = trim((string) ($l['prefixo'] ?? '')) !== '' ? (string) $l['prefixo'] : (string) ($l['nome'] ?? '');
            $saida[(int) $l['cod_pele']] = $nome;
        }

        return $saida;
    }

    /** @return array<int, string> id do perfil => nome */
    public function perfis(): array
    {
        if (!$this->existe('perfil')) {
            return [];
        }
        $saida = [];
        foreach ($this->ler('perfil', ['cod_perfil', 'nome'], '', [], [], 'cod_perfil') as $l) {
            $saida[(int) $l['cod_perfil']] = (string) $l['nome'];
        }

        return $saida;
    }

    /** @return list<array<string, mixed>> */
    public function objetosPorIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->ler('objeto', MapaDeTabelas::COLUNAS['objeto'], 'WHERE ' . $this->col('objeto', 'cod_objeto') . ' IN (:ids)', ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER], 'cod_objeto');
    }

    /** @return list<array<string, mixed>> os objetos de topo (sem pai), em ordem de id */
    public function raizes(): array
    {
        $pai = $this->col('objeto', 'cod_pai');

        return $this->ler('objeto', MapaDeTabelas::COLUNAS['objeto'], "WHERE {$pai} IS NULL OR {$pai} < 1", [], [], 'cod_objeto');
    }

    /**
     * Filhos diretos dos objetos `$pais`, em ordem de id e a partir de `$aposId` (paginação por faixa: não depende de OFFSET).
     *
     * @param list<int> $pais
     * @return list<array<string, mixed>>
     */
    public function filhos(array $pais, int $aposId, int $limite): array
    {
        if ($pais === []) {
            return [];
        }

        return $this->ler(
            'objeto',
            MapaDeTabelas::COLUNAS['objeto'],
            'WHERE ' . $this->col('objeto', 'cod_pai') . ' IN (:pais) AND ' . $this->col('objeto', 'cod_objeto') . ' > :apos',
            ['pais' => $pais, 'apos' => $aposId],
            ['pais' => ArrayParameterType::INTEGER],
            'cod_objeto',
            $limite,
        );
    }

    /**
     * Valores de um tipo (`tbl_string`, `tbl_text`, ...) dos objetos `$ids`.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function valores(string $tabela, array $ids): array
    {
        if ($ids === [] || !$this->existe($tabela)) {
            return [];
        }

        return $this->ler($tabela, MapaDeTabelas::COLUNAS[$tabela], 'WHERE ' . $this->col($tabela, 'cod_objeto') . ' IN (:ids)', ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER], 'cod_objeto, cod_propriedade');
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function versoes(array $ids): array
    {
        if ($ids === [] || !$this->existe('versaoobjeto')) {
            return [];
        }

        return $this->ler('versaoobjeto', MapaDeTabelas::COLUNAS['versaoobjeto'], 'WHERE ' . $this->col('versaoobjeto', 'cod_objeto') . ' IN (:ids)', ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER], 'cod_objeto, versao');
    }

    /**
     * @param list<int> $ids
     * @return array<int, list<string>> objeto => nomes das tags
     */
    public function tagsDosObjetos(array $ids): array
    {
        if ($ids === [] || !$this->existe('tag') || !$this->existe('tagxobjeto')) {
            return [];
        }
        $ligacoes = $this->ler('tagxobjeto', ['cod_tag', 'cod_objeto'], 'WHERE ' . $this->col('tagxobjeto', 'cod_objeto') . ' IN (:ids)', ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER], 'cod_objeto, cod_tag');
        if ($ligacoes === []) {
            return [];
        }
        $codigos = array_values(array_unique(array_map(static fn (array $l): int => (int) $l['cod_tag'], $ligacoes)));
        $nomes = [];
        foreach ($this->ler('tag', ['cod_tag', 'nome_tag'], 'WHERE ' . $this->col('tag', 'cod_tag') . ' IN (:ids)', ['ids' => $codigos], ['ids' => ArrayParameterType::INTEGER], 'cod_tag') as $t) {
            $nomes[(int) $t['cod_tag']] = trim((string) $t['nome_tag']);
        }
        $saida = [];
        foreach ($ligacoes as $l) {
            $nome = $nomes[(int) $l['cod_tag']] ?? '';
            if ($nome !== '') {
                $saida[(int) $l['cod_objeto']][] = $nome;
            }
        }

        return $saida;
    }

    /** @return list<array<string, mixed>> */
    public function usuarios(int $aposId, int $limite): array
    {
        return $this->ler('usuario', MapaDeTabelas::COLUNAS['usuario'], 'WHERE ' . $this->col('usuario', 'cod_usuario') . ' > :apos', ['apos' => $aposId], [], 'cod_usuario', $limite);
    }

    /**
     * @param list<int> $usuarios
     * @return list<array<string, mixed>>
     */
    public function acessosDe(array $usuarios): array
    {
        if ($usuarios === [] || !$this->existe('usuarioxobjetoxperfil')) {
            return [];
        }

        return $this->ler('usuarioxobjetoxperfil', MapaDeTabelas::COLUNAS['usuarioxobjetoxperfil'], 'WHERE ' . $this->col('usuarioxobjetoxperfil', 'cod_usuario') . ' IN (:ids)', ['ids' => $usuarios], ['ids' => ArrayParameterType::INTEGER], 'cod_usuario, cod_objeto');
    }

    /**
     * Tipo dos arquivos de cada propriedade de blob (extensão do nome original), lido em lotes por id: decide se a propriedade
     * vira `imagem` ou `arquivo` no PBL4.
     *
     * @param \Closure(string): bool $ehImagem
     * @return array<int, array{imagens: int, outros: int}> propriedade => contagem
     */
    public function tiposDeBlobPorPropriedade(\Closure $ehImagem, int $lote = 2000): array
    {
        $saida = [];
        if (!$this->existe('tbl_blob')) {
            return $saida;
        }
        $ultimo = 0;
        do {
            $linhas = $this->ler('tbl_blob', ['cod_blob', 'cod_propriedade', 'arquivo'], 'WHERE ' . $this->col('tbl_blob', 'cod_blob') . ' > :apos', ['apos' => $ultimo], [], 'cod_blob', $lote);
            foreach ($linhas as $l) {
                $ultimo = max($ultimo, (int) $l['cod_blob']);
                $p = (int) $l['cod_propriedade'];
                $saida[$p] ??= ['imagens' => 0, 'outros' => 0];
                $saida[$p][$ehImagem((string) $l['arquivo']) ? 'imagens' : 'outros']++;
            }
        } while (count($linhas) === $lote);

        return $saida;
    }

    /**
     * @param list<string> $colunas
     * @param array<string, mixed> $parametros
     * @param array<string, mixed> $tipos
     * @param string $ordem colunas lógicas, separadas por vírgula
     * @return list<array<string, mixed>>
     */
    private function ler(string $tabela, array $colunas, string $onde, array $parametros, array $tipos, string $ordem, ?int $limite = null): array
    {
        $lista = [];
        foreach ($colunas as $c) {
            if (in_array($c, MapaDeTabelas::OPCIONAIS[$tabela] ?? [], true) && !$this->colunaExiste($tabela, $this->fisica($tabela, $c))) {
                continue;
            }
            $lista[] = $this->col($tabela, $c) . ' AS ' . $this->conexao->quoteSingleIdentifier($c);
        }
        $ordenar = implode(', ', array_map(fn (string $c): string => $this->col($tabela, trim($c)), explode(',', $ordem)));
        $sql = 'SELECT ' . implode(', ', $lista) . ' FROM ' . $this->tabela($tabela) . ($onde === '' ? '' : ' ' . $onde) . ' ORDER BY ' . $ordenar . ($limite === null ? '' : ' LIMIT ' . max(1, $limite));

        return array_values($this->conexao->fetchAllAssociative($sql, $parametros, $tipos));
    }

    private function tabela(string $logica): string
    {
        return $this->conexao->quoteIdentifier($this->mapa->tabela($logica));
    }

    private function col(string $tabela, string $coluna): string
    {
        return $this->conexao->quoteSingleIdentifier($this->fisica($tabela, $coluna));
    }

    /** O nome físico, trocando pela grafia alternativa conhecida quando a esperada não existe (com aviso). */
    private function fisica(string $tabela, string $coluna): string
    {
        $chave = "{$tabela}.{$coluna}";
        if (isset($this->resolvidas[$chave])) {
            return $this->resolvidas[$chave];
        }
        $fisica = $this->mapa->coluna($tabela, $coluna);
        $alternativa = MapaDeTabelas::ALTERNATIVAS[$tabela][$coluna] ?? null;
        if ($alternativa !== null && $alternativa !== $fisica && !$this->colunaExiste($tabela, $fisica) && $this->colunaExiste($tabela, $alternativa)) {
            $this->relatorio->avisar('coluna com grafia alternativa', "{$tabela}.{$fisica} lida como {$alternativa}");
            $fisica = $alternativa;
        }

        return $this->resolvidas[$chave] = $fisica;
    }

    private function colunaExiste(string $tabela, string $fisica): bool
    {
        try {
            $this->conexao->fetchOne('SELECT ' . $this->conexao->quoteSingleIdentifier($fisica) . ' FROM ' . $this->tabela($tabela) . ' WHERE 1 = 0');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
