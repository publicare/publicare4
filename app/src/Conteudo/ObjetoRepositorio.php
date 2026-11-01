<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

use Doctrine\DBAL\Connection;

/**
 * Objetos com dados em JSON validados pela classe, caminho materializado (substitui `parentesco`)
 * e índice tipado só para propriedades marcadas como indexáveis (ADR 0003, fluxo 9).
 */
final class ObjetoRepositorio
{
    public function __construct(private readonly Connection $conexao)
    {
    }

    /** @param array<string, string|int|float> $dados */
    public function criar(int $siteId, Classe $classe, ?int $paiId, string $titulo, string $urlAmigavel, array $dados, string $status = 'publicado'): Objeto
    {
        if (preg_match(\Pbl\Caminhos::URL_VALIDA, $urlAmigavel) !== 1) {
            throw new \InvalidArgumentException("URL amigável inválida: {$urlAmigavel}");
        }
        $this->validar($classe, $dados);

        return $this->conexao->transactional(function () use ($siteId, $classe, $paiId, $titulo, $urlAmigavel, $dados, $status): Objeto {
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
                'criado_em' => $agora,
                'atualizado_em' => $agora,
            ]);
            $id = (int) $this->conexao->lastInsertId();
            $caminho = $caminhoPai . $id . '/';
            $this->conexao->update('objeto', ['caminho' => $caminho], ['id' => $id]);
            $this->indexar($id, $classe, $dados);

            return new Objeto($id, $siteId, $classe->id, $paiId, $caminho, $titulo, $urlAmigavel, $status, $dados);
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
     * Filhos publicados de um objeto, com filtro de classes, ordenação segura e janela.
     *
     * @param list<int>|null $classeIds
     * @return list<Objeto>
     */
    public function filhos(int $paiId, ?array $classeIds, string $ordem, int $inicio, int $limite): array
    {
        $ordenacao = $this->ordenacao($ordem);
        $sql = 'SELECT * FROM objeto WHERE status = ? AND pai_id = ?';
        $params = ['publicado', $paiId];
        $tipos = [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::INTEGER];
        if ($classeIds !== null) {
            $sql .= ' AND classe_id IN (?)';
            $params[] = $classeIds;
            $tipos[] = \Doctrine\DBAL\ArrayParameterType::INTEGER;
        }
        $sql .= " ORDER BY {$ordenacao}, id ASC LIMIT " . max(1, $limite) . ' OFFSET ' . max(0, $inicio);

        return array_map($this->hidratar(...), $this->conexao->executeQuery($sql, $params, $tipos)->fetchAllAssociative());
    }

    /**
     * Busca publicada num site: subárvore (`pai`, `niveis`), classes, igualdade em campo indexado
     * (`campo`/`valor`) e texto no título (`busca`). Subconjunto seguro; o motor único é a fase 1.
     *
     * @param array{classeIds?: list<int>, pai?: int, niveis?: int, campo?: string, valor?: string, busca?: string, ordem?: string, inicio?: int, limite?: int} $f
     * @return list<Objeto>
     */
    public function localizar(int $siteId, array $f): array
    {
        $sql = 'SELECT o.* FROM objeto o WHERE o.site_id = ? AND o.status = ?';
        $params = [$siteId, 'publicado'];
        $tipos = [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::STRING];

        if (isset($f['pai']) && $f['pai'] >= 0) {
            $caminhoPai = $this->conexao->fetchOne('SELECT caminho FROM objeto WHERE id = ? AND site_id = ?', [$f['pai'], $siteId]);
            if ($caminhoPai === false) {
                return [];
            }
            $sql .= " AND o.caminho LIKE ? ESCAPE '!' AND o.id <> ?";
            $params[] = $this->escaparLike((string) $caminhoPai) . '%';
            $params[] = $f['pai'];
            $tipos[] = \Doctrine\DBAL\ParameterType::STRING;
            $tipos[] = \Doctrine\DBAL\ParameterType::INTEGER;
            if (isset($f['niveis']) && $f['niveis'] > 0) {
                $barras = substr_count((string) $caminhoPai, '/') + $f['niveis'];
                $sql .= " AND (LENGTH(o.caminho) - LENGTH(REPLACE(o.caminho, '/', ''))) <= ?";
                $params[] = $barras;
                $tipos[] = \Doctrine\DBAL\ParameterType::INTEGER;
            }
        }
        if (isset($f['classeIds'])) {
            $sql .= ' AND o.classe_id IN (?)';
            $params[] = $f['classeIds'];
            $tipos[] = \Doctrine\DBAL\ArrayParameterType::INTEGER;
        }
        if (isset($f['campo'], $f['valor'])) {
            $sql .= ' AND EXISTS (SELECT 1 FROM objeto_indice i WHERE i.objeto_id = o.id AND i.chave = ? AND (i.texto = ?' . (is_numeric($f['valor']) ? ' OR i.numero = ?' : '') . '))';
            array_push($params, $f['campo'], $f['valor']);
            array_push($tipos, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING);
            if (is_numeric($f['valor'])) {
                $params[] = (float) $f['valor'];
                $tipos[] = \Doctrine\DBAL\ParameterType::STRING;
            }
        }
        if (isset($f['busca']) && $f['busca'] !== '') {
            $sql .= " AND LOWER(o.titulo) LIKE ? ESCAPE '!'";
            $params[] = '%' . $this->escaparLike(mb_strtolower($f['busca'])) . '%';
            $tipos[] = \Doctrine\DBAL\ParameterType::STRING;
        }
        $sql .= ' ORDER BY ' . $this->ordenacao($f['ordem'] ?? '', 'o.') . ', o.id ASC LIMIT ' . max(1, $f['limite'] ?? 100) . ' OFFSET ' . max(0, $f['inicio'] ?? 0);

        return array_map($this->hidratar(...), $this->conexao->executeQuery($sql, $params, $tipos)->fetchAllAssociative());
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

    /** Ordenação por lista fechada de colunas: nada do molde chega ao SQL como texto livre. */
    private function ordenacao(string $ordem, string $prefixo = ''): string
    {
        $partes = preg_split('/\s+/', trim($ordem)) ?: [];
        $coluna = strtolower($partes[0] ?? '');
        if (!in_array($coluna, ['id', 'titulo', 'criado_em', 'atualizado_em'], true)) {
            $coluna = 'id';
        }

        return $prefixo . $coluna . (strtolower($partes[1] ?? '') === 'desc' ? ' DESC' : ' ASC');
    }

    private function escaparLike(string $texto): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $texto);
    }

    /** @return list<Objeto> publicados do site cujo caminho começa com o prefixo (a própria subárvore). */
    public function publicadosDaSubarvore(int $siteId, string $caminhoRaiz): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT * FROM objeto WHERE site_id = ? AND status = ? AND caminho LIKE ? ORDER BY id',
            [$siteId, 'publicado', $caminhoRaiz . '%'],
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
        );
    }
}
