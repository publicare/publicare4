<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Pbl\Busca\Texto;

/**
 * Objetos com dados em JSON validados pela classe, caminho materializado (substitui `parentesco`)
 * e índice tipado só para propriedades marcadas como indexáveis (ADR 0003, fluxo 9).
 */
final class ObjetoRepositorio
{
    /** Estados de um objeto (fluxo 5). Só `publicado` aparece ao público. */
    public const STATUS = ['privado', 'submetido', 'rejeitado', 'publicado', 'apagado'];

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
        if (!in_array($status, self::STATUS, true)) {
            throw new \InvalidArgumentException("Estado inválido: {$status}");
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
                'titulo_busca' => mb_strtolower($titulo), // legado: a versão anterior do código ainda lê esta coluna
                'titulo_norm' => Texto::normalizar($titulo),
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
            $this->registrarVersao($id, 'criação');

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
            'SELECT * FROM objeto WHERE site_id = ? AND status = ? AND titulo_norm = ? ORDER BY id LIMIT 1',
            [$siteId, 'publicado', Texto::normalizar($titulo)],
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
     * Altera um objeto e guarda uma nova versão. `dados` é mesclado sobre os atuais (valor `null` remove a propriedade);
     * as demais chaves substituem o valor. Se a URL muda, a antiga vira redirecionamento 301 para o objeto
     * (`url_historico`). `$versaoEsperada` evita sobrescrever o trabalho de outra pessoa.
     *
     * @param array{titulo?: string, url_amigavel?: string, status?: string, dados?: array<string, string|int|float|bool|null>, peso?: int, descricao?: string, data_publicacao?: string|null, data_validade?: string|null} $mudancas
     * @throws ConflitoDeVersao se a versão atual não for a esperada
     */
    public function atualizar(int $id, array $mudancas, string $motivo = '', ?int $versaoEsperada = null, bool $liberarUrl = false): Objeto
    {
        return $this->conexao->transactional(function () use ($id, $mudancas, $motivo, $versaoEsperada, $liberarUrl): Objeto {
            $atual = $this->porId($id) ?? throw new \InvalidArgumentException("Objeto {$id} não existe");
            if ($versaoEsperada !== null && $versaoEsperada !== $atual->versao) {
                throw new ConflitoDeVersao($versaoEsperada, $atual->versao);
            }
            $linha = ['versao' => $atual->versao + 1, 'atualizado_em' => gmdate('c')];

            if (isset($mudancas['titulo']) && $mudancas['titulo'] !== $atual->titulo) {
                $linha['titulo'] = $mudancas['titulo'];
                $linha['titulo_busca'] = mb_strtolower($mudancas['titulo']);
                $linha['titulo_norm'] = Texto::normalizar($mudancas['titulo']);
            }
            if (isset($mudancas['url_amigavel']) && $mudancas['url_amigavel'] !== $atual->urlAmigavel) {
                $this->trocarUrl($atual, $mudancas['url_amigavel'], $liberarUrl);
                $linha['url_amigavel'] = $mudancas['url_amigavel'];
            }
            if (isset($mudancas['status'])) {
                if (!in_array($mudancas['status'], self::STATUS, true)) {
                    throw new \InvalidArgumentException("Estado inválido: {$mudancas['status']}");
                }
                $linha['status'] = $mudancas['status'];
            }
            foreach (['peso', 'descricao'] as $campo) {
                if (array_key_exists($campo, $mudancas)) {
                    $linha[$campo] = $mudancas[$campo];
                }
            }
            foreach (['data_publicacao', 'data_validade'] as $campo) {
                if (array_key_exists($campo, $mudancas)) {
                    $linha[$campo] = $mudancas[$campo];
                }
            }
            if (isset($mudancas['dados'])) {
                $classe = (new ClasseRepositorio($this->conexao))->porId($atual->classeId)
                    ?? throw new \RuntimeException("Classe {$atual->classeId} não existe");
                $novos = $atual->dados;
                foreach ($mudancas['dados'] as $chave => $valor) {
                    if ($valor === null) {
                        unset($novos[$chave]);
                    } else {
                        $novos[$chave] = $valor;
                    }
                }
                $novos = $this->validar($atual->siteId, $classe, $novos);
                $linha['dados'] = json_encode($novos, JSON_THROW_ON_ERROR);
                $this->conexao->delete('objeto_indice', ['objeto_id' => $id]);
                $this->indexar($id, $classe, $novos);
            }
            $this->conexao->update('objeto', $linha, ['id' => $id]);
            $this->registrarVersao($id, $motivo);

            return $this->porId($id) ?? throw new \RuntimeException('Objeto sumiu durante a atualização');
        });
    }

    /**
     * Muda o objeto de pai (e a subárvore junto). A URL amigável não depende da árvore, então nenhum endereço muda.
     */
    public function mover(int $id, ?int $novoPaiId, string $motivo = 'mover'): Objeto
    {
        return $this->conexao->transactional(function () use ($id, $novoPaiId, $motivo): Objeto {
            $o = $this->porId($id) ?? throw new \InvalidArgumentException("Objeto {$id} não existe");
            $caminhoPai = '/';
            if ($novoPaiId !== null) {
                $pai = $this->porId($novoPaiId);
                if ($pai === null || $pai->siteId !== $o->siteId) {
                    throw new \InvalidArgumentException("Pai {$novoPaiId} não existe neste site");
                }
                if (str_starts_with($pai->caminho, $o->caminho)) {
                    throw new \InvalidArgumentException('Um objeto não pode ser movido para dentro de si mesmo');
                }
                $caminhoPai = $pai->caminho;
            }
            $novo = $caminhoPai . $id . '/';
            foreach ($this->conexao->fetchAllAssociative("SELECT id, caminho FROM objeto WHERE site_id = ? AND caminho LIKE ? ESCAPE '!'", [$o->siteId, $this->escaparLike($o->caminho) . '%']) as $d) {
                $this->conexao->update('objeto', ['caminho' => $novo . substr((string) $d['caminho'], strlen($o->caminho))], ['id' => $d['id']]);
            }
            $this->conexao->update('objeto', ['pai_id' => $novoPaiId, 'versao' => $o->versao + 1, 'atualizado_em' => gmdate('c')], ['id' => $id]);
            $this->registrarVersao($id, $motivo);

            return $this->porId($id) ?? throw new \RuntimeException('Objeto sumiu durante a movimentação');
        });
    }

    /**
     * Histórico do objeto, da versão mais nova para a mais antiga.
     *
     * @return list<array{versao: int, titulo: string, status: string, motivo: string, criado_em: string}>
     */
    public function versoes(int $id): array
    {
        $linhas = $this->conexao->fetchAllAssociative('SELECT versao, titulo, status, motivo, criado_em FROM objeto_versao WHERE objeto_id = ? ORDER BY versao DESC', [$id]);

        return array_map(static fn (array $l): array => [
            'versao' => (int) $l['versao'], 'titulo' => (string) $l['titulo'], 'status' => (string) $l['status'],
            'motivo' => (string) ($l['motivo'] ?? ''), 'criado_em' => (string) $l['criado_em'],
        ], $linhas);
    }

    /**
     * Volta o conteúdo de uma versão antiga **como uma nova versão** (o histórico nunca é apagado). O pai atual é mantido.
     */
    public function restaurar(int $id, int $versao, string $motivo = ''): Objeto
    {
        $v = $this->conexao->fetchAssociative('SELECT * FROM objeto_versao WHERE objeto_id = ? AND versao = ?', [$id, $versao]);
        $atual = $this->porId($id);
        if ($v === false || $atual === null) {
            throw new \InvalidArgumentException("Versão {$versao} do objeto {$id} não existe");
        }
        /** @var array<string, string|int|float|null> $dados */
        $dados = json_decode((string) $v['dados'], true, 512, JSON_THROW_ON_ERROR);
        foreach (array_keys($atual->dados) as $chave) {
            $dados[$chave] ??= null;
        }

        return $this->atualizar($id, [
            'titulo' => (string) $v['titulo'],
            'url_amigavel' => (string) $v['url_amigavel'],
            'status' => (string) $v['status'],
            'dados' => $dados,
            'peso' => (int) $v['peso'],
            'descricao' => $v['descricao'] === null ? '' : (string) $v['descricao'],
            'data_publicacao' => $v['data_publicacao'] === null ? null : (string) $v['data_publicacao'],
            'data_validade' => $v['data_validade'] === null ? null : (string) $v['data_validade'],
        ], $motivo !== '' ? $motivo : "restaurada a versão {$versao}");
    }

    /**
     * Se `$url` é o endereço antigo de um objeto visível, devolve o endereço atual dele (para o 301). Nunca devolve a
     * própria URL pedida nem de objeto fora do ar.
     */
    public function urlAtualDoHistorico(int $siteId, string $url): ?string
    {
        $id = $this->conexao->fetchOne('SELECT objeto_id FROM url_historico WHERE site_id = ? AND url_amigavel = ?', [$siteId, $url]);
        $objeto = $id === false ? null : $this->porId((int) $id);
        if ($objeto === null || $objeto->siteId !== $siteId || !$objeto->visivel(gmdate('c')) || $objeto->urlAmigavel === $url) {
            return null;
        }

        return $objeto->urlAmigavel;
    }

    private function trocarUrl(Objeto $atual, string $nova, bool $liberarUrl): void
    {
        if (!\Pbl\Caminhos::urlValida($nova)) {
            throw new \InvalidArgumentException("URL amigável inválida: {$nova}");
        }
        if ($this->conexao->fetchOne('SELECT id FROM objeto WHERE site_id = ? AND url_amigavel = ? AND id <> ?', [$atual->siteId, $nova, $atual->id]) !== false) {
            throw new \InvalidArgumentException("A URL já é usada por outro objeto: {$nova}");
        }
        $dono = $this->conexao->fetchOne('SELECT objeto_id FROM url_historico WHERE site_id = ? AND url_amigavel = ?', [$atual->siteId, $nova]);
        if ($dono !== false && (int) $dono !== $atual->id && !$liberarUrl) {
            throw new \InvalidArgumentException("A URL {$nova} era de outro objeto e hoje redireciona para ele; é preciso liberá-la de propósito");
        }
        $this->conexao->delete('url_historico', ['site_id' => $atual->siteId, 'url_amigavel' => $nova]);
        $this->conexao->delete('url_historico', ['site_id' => $atual->siteId, 'url_amigavel' => $atual->urlAmigavel]);
        $this->conexao->insert('url_historico', ['site_id' => $atual->siteId, 'url_amigavel' => $atual->urlAmigavel, 'objeto_id' => $atual->id, 'criado_em' => gmdate('c')]);
    }

    /** Grava o estado atual do objeto como uma linha de `objeto_versao`. */
    private function registrarVersao(int $id, string $motivo): void
    {
        $o = $this->conexao->fetchAssociative('SELECT * FROM objeto WHERE id = ?', [$id]);
        if ($o === false) {
            return;
        }
        $this->conexao->insert('objeto_versao', [
            'objeto_id' => $id, 'versao' => $o['versao'], 'titulo' => $o['titulo'], 'url_amigavel' => $o['url_amigavel'],
            'status' => $o['status'], 'dados' => $o['dados'], 'peso' => $o['peso'], 'descricao' => $o['descricao'],
            'data_publicacao' => $o['data_publicacao'], 'data_validade' => $o['data_validade'], 'pai_id' => $o['pai_id'],
            'motivo' => $motivo === '' ? null : mb_substr($motivo, 0, 300), 'criado_em' => gmdate('c'),
        ]);
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
            // Corta o original em 500 e só então normaliza: o fim do texto que cabe no índice continua pesquisável.
            $texto = mb_substr((string) $valor, 0, 500);
            $this->conexao->insert('objeto_indice', [
                'objeto_id' => $id,
                'chave' => $p['nome'],
                'texto' => $numerico ? null : $texto,
                'texto_norm' => $numerico ? null : Texto::normalizar($texto),
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
            (int) ($linha['versao'] ?? 1),
        );
    }
}
