<?php

declare(strict_types=1);

namespace Pbl\Consulta;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\ObjetoRepositorio;

/**
 * Executa `Consulta`s sobre `objeto` e `objeto_indice`. Tudo numa consulta SQL, sem tabela temporária e sem DDL:
 * filtrar ou ordenar pelo campo de um objeto **referenciado** (`autor.cargo`) é um `LEFT JOIN` no índice da referência
 * e outro no objeto apontado (o PBL3 achatava o resultado numa tabela temporária para isso).
 *
 * Segurança: nenhum texto do chamador entra no SQL; colunas vêm de uma tabela fixa, nomes de propriedade e valores
 * vão sempre como parâmetro, e os curingas do `LIKE` são escapados.
 */
final class MotorDeConsulta
{
    /** Colunas de `objeto` que podem ser filtradas e ordenadas, por nome lógico. */
    private const COLUNAS = [
        'id' => 'id', 'titulo' => 'titulo', 'peso' => 'peso', 'descricao' => 'descricao',
        'data_publicacao' => 'data_publicacao', 'data_validade' => 'data_validade',
        'criado_em' => 'criado_em', 'atualizado_em' => 'atualizado_em',
    ];
    private const MAX_JUNCOES = 8;
    private const MAX_ORDENS = 4;
    private const MAX_FILTROS = 6;

    public function __construct(private readonly Connection $conexao, private readonly ObjetoRepositorio $objetos)
    {
    }

    /** @return list<Objeto> */
    public function buscar(Consulta $c): array
    {
        $q = $this->montar($c, true);
        $sql = 'SELECT o.* FROM objeto o' . $q['juncoes'] . ' WHERE ' . $q['onde']
            . ' ORDER BY ' . $q['ordem'] . ' LIMIT ' . max(1, $c->limite) . ' OFFSET ' . max(0, $c->inicio);

        return $this->objetos->hidratarLinhas($this->conexao->executeQuery($sql, $q['valores'], $q['tipos'])->fetchAllAssociative());
    }

    /** Quantos objetos a consulta acharia, ignorando `inicio` e `limite` (para paginação). */
    public function contar(Consulta $c): int
    {
        $q = $this->montar($c, false);

        return (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM objeto o' . $q['juncoes'] . ' WHERE ' . $q['onde'], $q['valores'], $q['tipos']);
    }

    /**
     * Carga em lote: vários objetos do site de uma vez (usada para hidratar referências).
     *
     * @param list<int> $ids
     * @return array<int, Objeto> indexado pelo id
     */
    public function porIds(int $siteId, array $ids, bool $apenasVisiveis = true): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $sql = 'SELECT o.* FROM objeto o WHERE o.site_id = :site AND o.id IN (:ids)';
        $valores = ['site' => $siteId, 'ids' => $ids];
        $tipos = ['site' => ParameterType::INTEGER, 'ids' => ArrayParameterType::INTEGER];
        if ($apenasVisiveis) {
            $sql .= ' AND ' . $this->visivel('o', $valores, $tipos);
        }
        $saida = [];
        foreach ($this->objetos->hidratarLinhas($this->conexao->executeQuery($sql, $valores, $tipos)->fetchAllAssociative()) as $o) {
            $saida[$o->id] = $o;
        }

        return $saida;
    }

    /** O objeto precisa ser do site informado e ter filhos visíveis (um molde não sonda a estrutura de outro site). */
    public function temFilhos(int $id, int $siteId): bool
    {
        return in_array($id, $this->idsComFilhos([$id], $siteId), true);
    }

    /**
     * Dos ids dados, os que têm ao menos um filho visível, numa consulta só.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public function idsComFilhos(array $ids, int $siteId): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $valores = ['site' => $siteId, 'ids' => $ids];
        $tipos = ['site' => ParameterType::INTEGER, 'ids' => ArrayParameterType::INTEGER];
        $sql = 'SELECT DISTINCT f.pai_id FROM objeto f JOIN objeto p ON p.id = f.pai_id'
            . ' WHERE f.pai_id IN (:ids) AND f.site_id = :site AND p.site_id = :site AND ' . $this->visivel('f', $valores, $tipos);

        return array_map('intval', $this->conexao->executeQuery($sql, $valores, $tipos)->fetchFirstColumn());
    }

    /**
     * @return array{juncoes: string, onde: string, ordem: string, valores: array<string, mixed>, tipos: array<string, ParameterType|ArrayParameterType>}
     */
    private function montar(Consulta $c, bool $comOrdem): array
    {
        $q = ['juncoes' => [], 'onde' => [], 'valores' => [], 'tipos' => [], 'n' => 0];
        $this->param($q, 'site', $c->siteId, ParameterType::INTEGER);
        $q['onde'][] = 'o.site_id = :site';

        if ($c->apenasVisiveis) {
            $q['onde'][] = $this->visivel('o', $q['valores'], $q['tipos']);
        }
        if ($c->paiId !== null) {
            if ($c->niveis === 1) {
                $this->param($q, 'pai', $c->paiId, ParameterType::INTEGER);
                $q['onde'][] = 'o.pai_id = :pai';
            } else {
                $caminho = $this->conexao->fetchOne('SELECT caminho FROM objeto WHERE id = ? AND site_id = ?', [$c->paiId, $c->siteId]);
                // pai de outro site ou inexistente: nada a devolver (condição impossível)
                $q['onde'][] = $caminho === false ? '1 = 0' : "o.caminho LIKE :prefixo ESCAPE '!' AND o.id <> :pai";
                if ($caminho !== false) {
                    $this->param($q, 'prefixo', $this->escaparLike((string) $caminho) . '%', ParameterType::STRING);
                    $this->param($q, 'pai', $c->paiId, ParameterType::INTEGER);
                    if ($c->niveis !== null && $c->niveis > 1) {
                        $this->param($q, 'barras', substr_count((string) $caminho, '/') + $c->niveis, ParameterType::INTEGER);
                        $q['onde'][] = "(LENGTH(o.caminho) - LENGTH(REPLACE(o.caminho, '/', ''))) <= :barras";
                    }
                }
            }
        }
        if ($c->classeIds !== null) {
            $q['onde'][] = 'o.classe_id IN (:classes)';
            $this->param($q, 'classes', $c->classeIds === [] ? [0] : $c->classeIds, ArrayParameterType::INTEGER);
        }
        foreach (array_slice($c->filtros, 0, self::MAX_FILTROS) as $f) {
            $q['onde'][] = $this->condicao($f, $q);
        }

        $ordem = $comOrdem ? $this->ordenacao($c->ordem, $q) : '';

        return [
            'juncoes' => $q['juncoes'] === [] ? '' : ' ' . implode(' ', $q['juncoes']),
            'onde' => implode(' AND ', $q['onde']),
            'ordem' => $ordem,
            'valores' => $q['valores'],
            'tipos' => $q['tipos'],
        ];
    }

    /**
     * @param array<string, mixed> $valores
     * @param array<string, ParameterType|ArrayParameterType> $tipos
     */
    private function visivel(string $alias, array &$valores, array &$tipos): string
    {
        $valores['status'] = 'publicado';
        $valores['agora'] = gmdate('c');
        $tipos['status'] = ParameterType::STRING;
        $tipos['agora'] = ParameterType::STRING;

        return "({$alias}.status = :status AND ({$alias}.data_publicacao IS NULL OR {$alias}.data_publicacao <= :agora)"
            . " AND ({$alias}.data_validade IS NULL OR {$alias}.data_validade >= :agora))";
    }

    /** @param array<string, mixed> $q */
    private function condicao(Filtro $f, array &$q): string
    {
        [$texto, $numero] = $this->campo($f->campo, $q);
        $n = ++$q['n'];
        $numerico = is_numeric($f->valor);
        $tv = "v{$n}";
        $nv = "n{$n}";
        $this->param($q, $tv, $f->valor, ParameterType::STRING);
        if ($numerico) {
            $this->param($q, $nv, str_contains($f->valor, '.') || stripos($f->valor, 'e') !== false ? (float) $f->valor : (int) $f->valor, str_contains($f->valor, '.') || stripos($f->valor, 'e') !== false ? ParameterType::STRING : ParameterType::INTEGER);
        }

        switch ($f->operador) {
            case '=':
                return $numerico ? "({$texto} = :{$tv} OR {$numero} = :{$nv})" : "{$texto} = :{$tv}";
            case '!=':
                return $numerico
                    ? "(({$texto} IS NULL OR {$texto} <> :{$tv}) AND ({$numero} IS NULL OR {$numero} <> :{$nv}))"
                    : "({$texto} IS NULL OR {$texto} <> :{$tv})";
            case 'contem':
                $emTitulo = $f->campo === 'titulo';
                $this->param($q, $tv, '%' . $this->escaparLike($emTitulo ? mb_strtolower($f->valor) : $f->valor) . '%', ParameterType::STRING);

                return ($emTitulo ? 'o.titulo_busca' : $texto) . " LIKE :{$tv} ESCAPE '!'";
            default: // < > <= >=
                return $numerico ? "{$numero} {$f->operador} :{$nv}" : "{$texto} {$f->operador} :{$tv}";
        }
    }

    /** @param array<string, mixed> $q */
    private function ordenacao(string $ordem, array &$q): string
    {
        $itens = [];
        foreach (array_slice(explode(',', $ordem), 0, self::MAX_ORDENS) as $item) {
            $item = trim($item);
            $desc = false;
            if ($item !== '' && ($item[0] === '-' || $item[0] === '+')) {
                $desc = $item[0] === '-';
                $item = ltrim(substr($item, 1));
            }
            if (preg_match('/^([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?)(?:\s+(asc|desc))?$/i', $item, $m) !== 1) {
                continue;
            }
            $dir = ($desc || strtolower($m[2] ?? '') === 'desc') ? ' DESC' : ' ASC';
            [$texto, $numero] = $this->campo(strtolower($m[1]), $q);
            // Nulos sempre por último, em qualquer direção e em qualquer banco (SQLite e MySQL põem nulos primeiro; PostgreSQL, último).
            $nulo = $texto === $numero ? "({$texto} IS NULL)" : "({$texto} IS NULL AND {$numero} IS NULL)";
            $itens[] = $nulo . ', ' . ($texto === $numero ? $texto . $dir : "{$numero}{$dir}, {$texto}{$dir}");
        }
        if ($itens === []) {
            $itens[] = 'o.peso ASC';
        }
        $itens[] = 'o.id ASC';

        return implode(', ', $itens);
    }

    /**
     * Expressões SQL (texto, número) de um campo. `ref.campo` segue a referência: junta o índice da propriedade `ref`
     * e o objeto apontado, **do mesmo site**.
     *
     * @param array<string, mixed> $q
     * @return array{string, string}
     */
    private function campo(string $caminho, array &$q): array
    {
        $partes = explode('.', $caminho);
        if (count($partes) > 2) {
            throw new \InvalidArgumentException("Só é possível seguir uma referência: {$caminho}");
        }
        if (count($partes) === 1) {
            return $this->coluna($partes[0], 'o', $q);
        }
        $this->exigirJuncao($q, 2);
        $n = ++$q['n'];
        $this->param($q, "ref{$n}", $partes[0], ParameterType::STRING);
        $q['juncoes'][] = "LEFT JOIN objeto_indice r{$n} ON r{$n}.objeto_id = o.id AND r{$n}.chave = :ref{$n}";
        $q['juncoes'][] = "LEFT JOIN objeto t{$n} ON t{$n}.id = r{$n}.numero AND t{$n}.site_id = o.site_id";

        return $this->coluna($partes[1], "t{$n}", $q);
    }

    /**
     * @param array<string, mixed> $q
     * @return array{string, string}
     */
    private function coluna(string $nome, string $alias, array &$q): array
    {
        if (isset(self::COLUNAS[$nome])) {
            $c = $alias . '.' . self::COLUNAS[$nome];

            return [$c, $c];
        }
        if (preg_match('/^[a-z][a-z0-9_]*$/', $nome) !== 1) {
            throw new \InvalidArgumentException("Campo inválido: {$nome}");
        }
        $this->exigirJuncao($q, 1);
        $n = ++$q['n'];
        $this->param($q, "prop{$n}", $nome, ParameterType::STRING);
        $q['juncoes'][] = "LEFT JOIN objeto_indice x{$n} ON x{$n}.objeto_id = {$alias}.id AND x{$n}.chave = :prop{$n}";

        return ["x{$n}.texto", "x{$n}.numero"];
    }

    /** @param array<string, mixed> $q */
    private function exigirJuncao(array $q, int $novas): void
    {
        if (count($q['juncoes']) + $novas > self::MAX_JUNCOES) {
            throw new \InvalidArgumentException('Consulta com campos demais (limite de junções)');
        }
    }

    /** @param array<string, mixed> $q */
    private function param(array &$q, string $nome, mixed $valor, ParameterType|ArrayParameterType $tipo): void
    {
        $q['valores'][$nome] = $valor;
        $q['tipos'][$nome] = $tipo;
    }

    private function escaparLike(string $texto): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $texto);
    }
}
