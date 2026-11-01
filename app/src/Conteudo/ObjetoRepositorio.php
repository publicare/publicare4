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
     * @param array<string, string|int|float|bool> $dados
     * @param array{peso?: int, descricao?: string, data_publicacao?: string|null, data_validade?: string|null} $meta
     */
    public function criar(int $siteId, Classe $classe, ?int $paiId, string $titulo, string $urlAmigavel, array $dados, string $status = 'publicado', array $meta = []): Objeto
    {
        if (!\Pbl\Caminhos::urlValida($urlAmigavel)) {
            throw new \InvalidArgumentException("URL amigável inválida: {$urlAmigavel}");
        }
        $dados = $this->validar($siteId, $classe, $dados);

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
                'titulo_busca' => mb_strtolower($titulo),
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

    /** Primeiro objeto publicado do site com esse título (sem diferenciar maiúsculas). */
    public function porTitulo(int $siteId, string $titulo): ?Objeto
    {
        $linha = $this->conexao->fetchAssociative(
            'SELECT * FROM objeto WHERE site_id = ? AND status = ? AND titulo_busca = ? ORDER BY id LIMIT 1',
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

    /**
     * Valida cada valor pelo tipo da propriedade e devolve os dados normalizados (booleano vira 0/1, referência vira id).
     *
     * @param array<string, string|int|float|bool> $dados
     * @return array<string, string|int|float>
     */
    private function validar(int $siteId, Classe $classe, array $dados): array
    {
        $definidas = [];
        foreach ($classe->propriedades as $p) {
            $definidas[$p['nome']] = $p;
        }
        $saida = [];
        foreach ($dados as $chave => $valor) {
            if (!isset($definidas[$chave])) {
                throw new \InvalidArgumentException("Propriedade desconhecida na classe {$classe->nome}: {$chave}");
            }
            $p = $definidas[$chave];
            $saida[$chave] = match ($p['tipo']) {
                'numero' => is_int($valor) || is_float($valor) ? $valor : throw new \InvalidArgumentException("Propriedade {$chave} deve ser número"),
                'booleano' => $this->booleano($chave, $valor),
                'data' => $this->data($chave, $valor),
                'ref' => $this->referencia($siteId, $chave, $valor, $p['classe'] ?? null),
                default => is_bool($valor) ? throw new \InvalidArgumentException("Propriedade {$chave} deve ser texto") : $valor,
            };
        }

        return $saida;
    }

    private function booleano(string $chave, string|int|float|bool $valor): int
    {
        return match (true) {
            $valor === true, $valor === 1, $valor === '1' => 1,
            $valor === false, $valor === 0, $valor === '0' => 0,
            default => throw new \InvalidArgumentException("Propriedade {$chave} deve ser verdadeiro/falso (0 ou 1)"),
        };
    }

    private function data(string $chave, string|int|float|bool $valor): string
    {
        if (is_string($valor)) {
            foreach (['!Y-m-d', '!Y-m-d H:i:s', '!Y-m-d\TH:i:sP'] as $formato) {
                $d = \DateTimeImmutable::createFromFormat($formato, $valor);
                $problemas = \DateTimeImmutable::getLastErrors();
                if ($d !== false && ($problemas === false || ($problemas['warning_count'] === 0 && $problemas['error_count'] === 0))) {
                    return $valor;
                }
            }
        }

        throw new \InvalidArgumentException("Propriedade {$chave} deve ser uma data válida (aaaa-mm-dd ou aaaa-mm-dd hh:mm:ss)");
    }

    /** O alvo precisa existir **no mesmo site** (e ser da classe indicada, se a propriedade restringe). */
    private function referencia(int $siteId, string $chave, string|int|float|bool $valor, ?string $classeNome): int
    {
        $id = is_int($valor) ? $valor : (is_string($valor) && ctype_digit($valor) ? (int) $valor : 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException("Propriedade {$chave} deve ser o id de um objeto");
        }
        $classeId = $this->conexao->fetchOne('SELECT classe_id FROM objeto WHERE id = ? AND site_id = ?', [$id, $siteId]);
        if ($classeId === false) {
            throw new \InvalidArgumentException("Propriedade {$chave} aponta para um objeto que não existe neste site: {$id}");
        }
        if ($classeNome !== null) {
            $esperada = $this->conexao->fetchOne('SELECT id FROM classe WHERE nome = ?', [$classeNome]);
            if ($esperada === false || (int) $esperada !== (int) $classeId) {
                throw new \InvalidArgumentException("Propriedade {$chave} deve apontar para um objeto da classe {$classeNome}");
            }
        }

        return $id;
    }

    /** @param array<string, string|int|float> $dados */
    private function indexar(int $id, Classe $classe, array $dados): void
    {
        foreach ($classe->propriedades as $p) {
            if (!$p['indexavel'] || !array_key_exists($p['nome'], $dados)) {
                continue;
            }
            $valor = $dados[$p['nome']];
            $numerico = in_array($p['tipo'], ['numero', 'booleano', 'ref'], true);
            $this->conexao->insert('objeto_indice', [
                'objeto_id' => $id,
                'chave' => $p['nome'],
                'texto' => $numerico ? null : mb_substr((string) $valor, 0, 500),
                'numero' => $numerico ? (float) $valor : null,
            ]);
        }
    }

    /**
     * @param list<array<string, mixed>> $linhas
     * @return list<Objeto>
     */
    public function hidratarLinhas(array $linhas): array
    {
        return array_map($this->hidratar(...), $linhas);
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
