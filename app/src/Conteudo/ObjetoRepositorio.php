<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Objetos com dados em JSON validados pela classe, caminho materializado (substitui `parentesco`)
 * e índice tipado só para propriedades marcadas como indexáveis (ADR 0003, fluxo 9).
 */
final class ObjetoRepositorio
{
    public function __construct(private readonly Connection $conexao)
    {
    }

    /**
     * @param array<string, string|int|float> $dados
     * @param array{peso?: int, descricao?: string, data_publicacao?: string|null, data_validade?: string|null} $meta
     */
    public function criar(int $siteId, Classe $classe, ?int $paiId, string $titulo, string $urlAmigavel, array $dados, string $status = 'publicado', array $meta = []): Objeto
    {
        if (!\Pbl\Caminhos::urlValida($urlAmigavel)) {
            throw new \InvalidArgumentException("URL amigável inválida: {$urlAmigavel}");
        }
        $this->validar($classe, $dados);

        return $this->conexao->transactional(function () use ($siteId, $classe, $paiId, $titulo, $urlAmigavel, $dados, $status, $meta): Objeto {
            $caminhoPai = '/';
            if ($paiId !== null) {
                $caminhoPai = (string) $this->conexao->fetchOne('SELECT caminho FROM objeto WHERE id = ? AND site_id = ?', [$paiId, $siteId]);
                if ($caminhoPai === '') {
                    throw new \InvalidArgumentException("Pai {$paiId} não existe no site {$siteId}");
                }
            }
            $agora = gmdate('c');
            $this->conexao->insert('objeto', [
                'site_id' => $siteId,
                'classe_id' => $classe->id,
                'pai_id' => $paiId,
                'caminho' => '',
                'titulo' => $titulo,
                'url_amigavel' => $urlAmigavel,
                'status' => $status,
                'dados' => json_encode($dados, JSON_THROW_ON_ERROR),
                'peso' => $meta['peso'] ?? 0,
                'descricao' => $meta['descricao'] ?? null,
                'data_publicacao' => $meta['data_publicacao'] ?? null,
                'data_validade' => $meta['data_validade'] ?? null,
                'criado_em' => $agora,
                'atualizado_em' => $agora,
            ]);
            $id = (int) $this->conexao->lastInsertId();
            $caminho = $caminhoPai . $id . '/';
            $this->conexao->update('objeto', ['caminho' => $caminho], ['id' => $id]);
            $this->indexar($id, $classe, $dados);

            return new Objeto($id, $siteId, $classe->id, $paiId, $caminho, $titulo, $urlAmigavel, $status, $dados, $meta['peso'] ?? 0, $meta['descricao'] ?? '', $meta['data_publicacao'] ?? null, $meta['data_validade'] ?? null);
        });
    }

    public function porId(int $id): ?Objeto
    {
        $linha = $this->conexao->fetchAssociative('SELECT * FROM objeto WHERE id = ?', [$id]);

        return $linha === false ? null : $this->hidratar($linha);
    }

    public function porUrl(int $siteId, string $urlAmigavel): ?Objeto
    {
        $linha = $this->conexao->fetchAssociative(
            'SELECT * FROM objeto WHERE site_id = ? AND url_amigavel = ?',
            [$siteId, $urlAmigavel],
        );

        return $linha === false ? null : $this->hidratar($linha);
    }

    /**
     * Filhos publicados de vários pais, numa única consulta (carga em lote, fluxo 9).
     *
     * @param list<int> $paiIds
     * @return array<int, list<Objeto>> indexado pelo id do pai
     */
    public function filhosEmLote(array $paiIds): array
    {
        if ($paiIds === []) {
            return [];
        }
        $linhas = $this->conexao->executeQuery(
            'SELECT * FROM objeto WHERE status = ? AND pai_id IN (?) ORDER BY id',
            ['publicado', $paiIds],
            [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::INTEGER],
        )->fetchAllAssociative();
        $saida = [];
        foreach ($linhas as $linha) {
            $o = $this->hidratar($linha);
            $saida[(int) $o->paiId][] = $o;
        }

        return $saida;
    }

    /**
     * Filhos publicados e vigentes de um objeto, com filtro de classes, ordenação segura e janela.
     *
     * @param list<int>|null $classeIds
     * @return list<Objeto>
     */
    public function filhos(int $paiId, ?array $classeIds, string $ordem, int $inicio, int $limite): array
    {
        $consulta = $this->consulta('o.pai_id = :pai', ['pai' => [$paiId, ParameterType::INTEGER]]);
        if ($classeIds !== null) {
            $consulta['where'][] = 'o.classe_id IN (:classes)';
            $consulta['params']['classes'] = [$classeIds, ArrayParameterType::INTEGER];
        }

        return $this->executar($consulta, $ordem, $inicio, $limite);
    }

    /**
     * Busca publicada e vigente num site: subárvore (`pai`, `niveis`), classes, igualdade em campo indexado
     * (`campo`/`valor`) e texto no título (`busca`). Subconjunto seguro; o motor único é a fase 1.
     *
     * @param array{classeIds?: list<int>, pai?: int, niveis?: int, campo?: string, valor?: string, busca?: string, ordem?: string, inicio?: int, limite?: int} $f
     * @return list<Objeto>
     */
    public function localizar(int $siteId, array $f): array
    {
        $consulta = $this->consulta('o.site_id = :site', ['site' => [$siteId, ParameterType::INTEGER]]);

        if (isset($f['pai']) && $f['pai'] >= 0) {
            $caminhoPai = $this->conexao->fetchOne('SELECT caminho FROM objeto WHERE id = ? AND site_id = ?', [$f['pai'], $siteId]);
            if ($caminhoPai === false) {
                return [];
            }
            $consulta['where'][] = "o.caminho LIKE :prefixo ESCAPE '!' AND o.id <> :pai";
            $consulta['params']['prefixo'] = [$this->escaparLike((string) $caminhoPai) . '%', ParameterType::STRING];
            $consulta['params']['pai'] = [$f['pai'], ParameterType::INTEGER];
            if (isset($f['niveis']) && $f['niveis'] > 0) {
                $consulta['where'][] = "(LENGTH(o.caminho) - LENGTH(REPLACE(o.caminho, '/', ''))) <= :barras";
                $consulta['params']['barras'] = [substr_count((string) $caminhoPai, '/') + $f['niveis'], ParameterType::INTEGER];
            }
        }
        if (isset($f['classeIds'])) {
            $consulta['where'][] = 'o.classe_id IN (:classes)';
            $consulta['params']['classes'] = [$f['classeIds'], ArrayParameterType::INTEGER];
        }
        if (isset($f['campo'], $f['valor'])) {
            $igual = 'i.texto = :valor' . (is_numeric($f['valor']) ? ' OR i.numero = :numero' : '');
            $consulta['where'][] = "EXISTS (SELECT 1 FROM objeto_indice i WHERE i.objeto_id = o.id AND i.chave = :campo AND ({$igual}))";
            $consulta['params']['campo'] = [$f['campo'], ParameterType::STRING];
            $consulta['params']['valor'] = [$f['valor'], ParameterType::STRING];
            if (is_numeric($f['valor'])) {
                $consulta['params']['numero'] = [(float) $f['valor'], ParameterType::STRING];
            }
        }
        if (isset($f['busca']) && $f['busca'] !== '') {
            $consulta['where'][] = "LOWER(o.titulo) LIKE :busca ESCAPE '!'";
            $consulta['params']['busca'] = ['%' . $this->escaparLike(mb_strtolower($f['busca'])) . '%', ParameterType::STRING];
        }

        return $this->executar($consulta, $f['ordem'] ?? '', $f['inicio'] ?? 0, $f['limite'] ?? 100);
    }

    /**
     * Base de toda consulta pública: só publicado e dentro da vigência.
     *
     * @param array<string, array{mixed, \Doctrine\DBAL\ParameterType|\Doctrine\DBAL\ArrayParameterType}> $params
     * @return array{where: list<string>, params: array<string, array{mixed, \Doctrine\DBAL\ParameterType|\Doctrine\DBAL\ArrayParameterType}>}
     */
    private function consulta(string $condicaoInicial, array $params): array
    {
        $params['status'] = ['publicado', ParameterType::STRING];
        $params['agora'] = [gmdate('c'), ParameterType::STRING];

        return [
            'where' => [
                $condicaoInicial,
                'o.status = :status',
                '(o.data_publicacao IS NULL OR o.data_publicacao <= :agora)',
                '(o.data_validade IS NULL OR o.data_validade >= :agora)',
            ],
            'params' => $params,
        ];
    }

    /**
     * @param array{where: list<string>, params: array<string, array{mixed, \Doctrine\DBAL\ParameterType|\Doctrine\DBAL\ArrayParameterType}>} $consulta
     * @return list<Objeto>
     */
    private function executar(array $consulta, string $ordem, int $inicio, int $limite): array
    {
        [$joins, $order, $paramsOrdem] = $this->ordenacao($ordem);
        $params = [];
        $tipos = [];
        foreach ($consulta['params'] + $paramsOrdem as $nome => [$valor, $tipo]) {
            $params[$nome] = $valor;
            $tipos[$nome] = $tipo;
        }
        $sql = 'SELECT o.* FROM objeto o' . $joins . ' WHERE ' . implode(' AND ', $consulta['where'])
            . ' ORDER BY ' . $order . ' LIMIT ' . max(1, $limite) . ' OFFSET ' . max(0, $inicio);

        return array_map($this->hidratar(...), $this->conexao->executeQuery($sql, $params, $tipos)->fetchAllAssociative());
    }

    /**
     * Ordenação por lista fechada: `peso, -data_publicacao`, `titulo desc`, ou propriedade **indexável**
     * da classe. Nada do molde chega ao SQL como texto livre: colunas vêm de uma tabela fixa e nomes de
     * propriedade vão como parâmetro. Sem ordem informada vale `peso` (como no PBL3).
     *
     * @return array{string, string, array<string, array{mixed, \Doctrine\DBAL\ParameterType}>}
     */
    private function ordenacao(string $ordem): array
    {
        $colunas = [
            'id' => 'o.id', 'titulo' => 'o.titulo', 'peso' => 'o.peso', 'descricao' => 'o.descricao',
            'data_publicacao' => 'o.data_publicacao', 'data_validade' => 'o.data_validade',
            'criado_em' => 'o.criado_em', 'atualizado_em' => 'o.atualizado_em',
        ];
        $joins = '';
        $itens = [];
        $params = [];
        $n = 0;
        foreach (explode(',', $ordem) as $item) {
            $item = trim($item);
            if ($item === '' || $n >= 4) {
                continue;
            }
            $desc = false;
            if ($item[0] === '-' || $item[0] === '+') {
                $desc = $item[0] === '-';
                $item = ltrim(substr($item, 1));
            }
            if (preg_match('/^([a-z][a-z0-9_]*)(?:\s+(asc|desc))?$/i', $item, $m) !== 1) {
                continue;
            }
            $nome = strtolower($m[1]);
            $desc = $desc || strtolower($m[2] ?? '') === 'desc';
            $dir = $desc ? ' DESC' : ' ASC';
            if (isset($colunas[$nome])) {
                $itens[] = $colunas[$nome] . $dir;
            } else {
                $n++;
                $joins .= " LEFT JOIN objeto_indice ox{$n} ON ox{$n}.objeto_id = o.id AND ox{$n}.chave = :ordem{$n}";
                $params["ordem{$n}"] = [$nome, ParameterType::STRING];
                $itens[] = "ox{$n}.numero{$dir}, ox{$n}.texto{$dir}";
            }
        }
        if ($itens === []) {
            $itens[] = 'o.peso ASC';
        }
        $itens[] = 'o.id ASC';

        return [$joins, implode(', ', $itens), $params];
    }

    /**
     * Todos os objetos do site abaixo do caminho, em qualquer estado. Usado para limpar do cache o que deixou
     * de estar visível (despublicado, vencido, rascunho).
     *
     * @return list<Objeto>
     */
    public function todosDaSubarvore(int $siteId, string $caminhoRaiz): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            "SELECT * FROM objeto WHERE site_id = ? AND caminho LIKE ? ESCAPE '!' ORDER BY id",
            [$siteId, $this->escaparLike($caminhoRaiz) . '%'],
        );

        return array_map($this->hidratar(...), $linhas);
    }

    public function temFilhos(int $id): bool
    {
        return $this->conexao->fetchOne('SELECT 1 FROM objeto WHERE pai_id = ? AND status = ? LIMIT 1', [$id, 'publicado']) !== false;
    }

    /** Primeiro objeto publicado do site com esse título (sem diferenciar maiúsculas). */
    public function porTitulo(int $siteId, string $titulo): ?Objeto
    {
        $linha = $this->conexao->fetchAssociative(
            'SELECT * FROM objeto WHERE site_id = ? AND status = ? AND LOWER(titulo) = ? ORDER BY id LIMIT 1',
            [$siteId, 'publicado', mb_strtolower($titulo)],
        );

        return $linha === false ? null : $this->hidratar($linha);
    }

    private function escaparLike(string $texto): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $texto);
    }

    /** @return list<Objeto> publicados do site cujo caminho começa com o prefixo (a própria subárvore). */
    public function publicadosDaSubarvore(int $siteId, string $caminhoRaiz): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT * FROM objeto WHERE site_id = ? AND status = ? AND caminho LIKE ?'
            . ' AND (data_publicacao IS NULL OR data_publicacao <= ?) AND (data_validade IS NULL OR data_validade >= ?) ORDER BY id',
            [$siteId, 'publicado', $caminhoRaiz . '%', gmdate('c'), gmdate('c')],
        );

        return array_map($this->hidratar(...), $linhas);
    }

    /** @param array<string, string|int|float> $dados */
    private function validar(Classe $classe, array $dados): void
    {
        $definidas = [];
        foreach ($classe->propriedades as $p) {
            $definidas[$p['nome']] = $p['tipo'];
        }
        foreach ($dados as $chave => $valor) {
            if (!isset($definidas[$chave])) {
                throw new \InvalidArgumentException("Propriedade desconhecida na classe {$classe->nome}: {$chave}");
            }
            if ($definidas[$chave] === 'numero' && !is_int($valor) && !is_float($valor)) {
                throw new \InvalidArgumentException("Propriedade {$chave} deve ser número");
            }
        }
    }

    /** @param array<string, string|int|float> $dados */
    private function indexar(int $id, Classe $classe, array $dados): void
    {
        foreach ($classe->propriedades as $p) {
            if (!$p['indexavel'] || !array_key_exists($p['nome'], $dados)) {
                continue;
            }
            $valor = $dados[$p['nome']];
            $this->conexao->insert('objeto_indice', [
                'objeto_id' => $id,
                'chave' => $p['nome'],
                'texto' => $p['tipo'] === 'numero' ? null : mb_substr((string) $valor, 0, 500),
                'numero' => $p['tipo'] === 'numero' ? (float) $valor : null,
            ]);
        }
    }

    /** @param array<string, mixed> $linha */
    private function hidratar(array $linha): Objeto
    {
        /** @var array<string, string|int|float> $dados */
        $dados = json_decode((string) $linha['dados'], true, 512, JSON_THROW_ON_ERROR);

        return new Objeto(
            (int) $linha['id'],
            (int) $linha['site_id'],
            (int) $linha['classe_id'],
            $linha['pai_id'] === null ? null : (int) $linha['pai_id'],
            (string) $linha['caminho'],
            (string) $linha['titulo'],
            (string) $linha['url_amigavel'],
            (string) $linha['status'],
            $dados,
            (int) ($linha['peso'] ?? 0),
            (string) ($linha['descricao'] ?? ''),
            isset($linha['data_publicacao']) ? (string) $linha['data_publicacao'] : null,
            isset($linha['data_validade']) ? (string) $linha['data_validade'] : null,
        );
    }
}
