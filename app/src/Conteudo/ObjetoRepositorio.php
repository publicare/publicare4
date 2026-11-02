<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Pbl\Acesso\Auditoria;
use Pbl\Arquivos\TiposDeArquivo;
use Pbl\Busca\BuscaInterface;
use Pbl\Busca\BuscaSql;
use Pbl\Busca\IndexadorDeObjeto;
use Pbl\Busca\Texto;

/**
 * Objetos com dados em JSON validados pela classe, caminho materializado (substitui `parentesco`)
 * e índice tipado só para propriedades marcadas como indexáveis (ADR 0003, fluxo 9).
 */
final class ObjetoRepositorio
{
    /** Estados de um objeto (fluxo 5). Só `publicado` aparece ao público. */
    public const STATUS = ['privado', 'submetido', 'rejeitado', 'publicado', 'apagado'];

    private readonly IndexadorDeObjeto $buscador;

    public function __construct(private readonly Connection $conexao, private readonly ?Auditoria $auditoria = null, ?BuscaInterface $busca = null)
    {
        $this->buscador = new IndexadorDeObjeto($busca ?? new BuscaSql($conexao), new ClasseRepositorio($conexao));
    }

    /**
     * @param array<string, string|int|float|bool> $dados
     * @param array{peso?: int, descricao?: string, no_menu?: bool, rotulo_menu?: string, data_publicacao?: string|null, data_validade?: string|null, criado_por?: int|null} $meta `criado_por` = id do usuário (o autor só edita o que criou)
     * @param bool $liberarUrl true = tomar de propósito um endereço que hoje redireciona para outro objeto
     * @param (\Closure(int): array<string, string|int|float|bool>)|null $aposInserir chamado **dentro da mesma transação**, já com
     *     o id do objeto novo; devolve dados adicionais para mesclar (upload de arquivo, que precisa do id do dono antes de
     *     poder gravar, ADR 0014 §4). Se lançar, a transação inteira é desfeita e o objeto não chega a existir — "criar e
     *     anexar" é atômico: ou os dois acontecem, ou nenhum. Propriedades `arquivo`/`imagem` obrigatórias não entram na
     *     conferência inicial (`$dados`): são conferidas de novo depois de mescladas, já com o retorno de `$aposInserir`.
     */
    public function criar(int $siteId, Classe $classe, ?int $paiId, string $titulo, string $urlAmigavel, array $dados, string $status = 'publicado', array $meta = [], bool $liberarUrl = false, ?\Closure $aposInserir = null): Objeto
    {
        if (!\Pbl\Caminhos::urlValida($urlAmigavel)) {
            throw new \InvalidArgumentException("URL amigável inválida: {$urlAmigavel}");
        }
        if (!in_array($status, self::STATUS, true)) {
            throw new \InvalidArgumentException("Estado inválido: {$status}");
        }
        $dados = $this->validar($siteId, $classe, $dados);
        $this->exigirObrigatorios($classe, $dados, $aposInserir !== null);
        $this->exigirRegrasDoTipo($classe, $dados);
        $meta['data_publicacao'] = $this->dataDaVigencia('data_publicacao', $meta['data_publicacao'] ?? null);
        $meta['data_validade'] = $this->dataDaVigencia('data_validade', $meta['data_validade'] ?? null);

        return $this->conexao->transactional(function () use ($siteId, $classe, $paiId, $titulo, $urlAmigavel, $dados, $status, $meta, $liberarUrl, $aposInserir): Objeto {
            // Um endereço que redireciona para outro conteúdo não pode ser tomado por acidente: o cache da página nova passaria
            // na frente do histórico e o link antigo deixaria de levar ao conteúdo original.
            if ($this->conexao->fetchOne('SELECT objeto_id FROM url_historico WHERE site_id = ? AND url_amigavel = ?', [$siteId, $urlAmigavel]) !== false) {
                if (!$liberarUrl) {
                    throw new \InvalidArgumentException("A URL {$urlAmigavel} era de outro objeto e hoje redireciona para ele; é preciso liberá-la de propósito");
                }
                $this->conexao->delete('url_historico', ['site_id' => $siteId, 'url_amigavel' => $urlAmigavel]);
            }
            $caminhoPai = '/';
            if ($paiId !== null) {
                $caminhoPai = (string) $this->conexao->fetchOne('SELECT caminho FROM objeto WHERE id = ? AND site_id = ?', [$paiId, $siteId]);
                if ($caminhoPai === '') {
                    throw new \InvalidArgumentException("Pai {$paiId} não existe no site {$siteId}");
                }
                $this->exigirQueOPaiAceita($paiId, $classe);
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
                'criado_por' => $meta['criado_por'] ?? null,
                'no_menu' => empty($meta['no_menu']) ? 0 : 1,
                'rotulo_menu' => ($meta['rotulo_menu'] ?? '') === '' ? null : $meta['rotulo_menu'],
                'criado_em' => $agora,
                'atualizado_em' => $agora,
            ]);
            $id = (int) $this->conexao->lastInsertId();
            $caminho = $caminhoPai . $id . '/';
            $this->conexao->update('objeto', ['caminho' => $caminho], ['id' => $id]);
            if ($aposInserir !== null) {
                // O id do objeto já existe (acima) mas a transação só termina no fim desta função: um upload que falhe aqui
                // desfaz o insert por completo (nada fica pela metade). O arquivo físico já gravado em disco não é
                // desfeito (não é transacional com o banco), mas fica sem nenhuma linha em `arquivo` apontando para ele —
                // órfão de fábrica, nunca referenciado, então nunca listado por `bin/pbl limpar-uploads`.
                $extra = $this->validar($siteId, $classe, $aposInserir($id));
                $dados = [...$dados, ...$extra];
                $this->exigirObrigatorios($classe, $dados);
                $this->conexao->update('objeto', ['dados' => json_encode($dados, JSON_THROW_ON_ERROR)], ['id' => $id]);
            }
            $this->indexar($id, $classe, $dados);
            $this->buscador->indexar($id, $classe->id, $titulo, (string) ($meta['descricao'] ?? ''), $dados);
            $this->registrarVersao($id, 'criação', $meta['criado_por'] ?? null);
            $this->auditar('objeto.criar', $meta['criado_por'] ?? null, $id, ['classe' => $classe->nome, 'estado' => $status]);

            return new Objeto($id, $siteId, $classe->id, $paiId, $caminho, $titulo, $urlAmigavel, $status, $dados, $meta['peso'] ?? 0, $meta['descricao'] ?? '', $meta['data_publicacao'] ?? null, $meta['data_validade'] ?? null, 1, $meta['criado_por'] ?? null, !empty($meta['no_menu']), (string) ($meta['rotulo_menu'] ?? ''));
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

    /** Quantos objetos o site tem (fora da lixeira definitiva). */
    public function contarDoSite(int $siteId): int
    {
        return (int) $this->conexao->fetchOne("SELECT COUNT(*) FROM objeto WHERE site_id = ? AND status <> 'apagado'", [$siteId]);
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

    /**
     * Um lote de objetos **em qualquer estado** (inclusive a lixeira), de qualquer site ou só de `$siteId`, ordenado por id a
     * partir de `$apartirDe` (exclusive). Feito para varreduras de todo o conteúdo sem carregar tudo na memória de uma vez: quem
     * chama guarda o maior id lido e pede o lote seguinte até vir vazio.
     *
     * @return list<Objeto>
     */
    public function loteOrdenadoPorId(?int $siteId, int $apartirDe, int $tamanho): array
    {
        if ($siteId !== null) {
            $linhas = $this->conexao->fetchAllAssociative(
                'SELECT * FROM objeto WHERE site_id = ? AND id > ? ORDER BY id LIMIT ?',
                [$siteId, $apartirDe, $tamanho],
                [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER],
            );
        } else {
            $linhas = $this->conexao->fetchAllAssociative(
                'SELECT * FROM objeto WHERE id > ? ORDER BY id LIMIT ?',
                [$apartirDe, $tamanho],
                [ParameterType::INTEGER, ParameterType::INTEGER],
            );
        }

        return array_map($this->hidratar(...), $linhas);
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
     * @param array{titulo?: string, url_amigavel?: string, status?: string, dados?: array<string, string|int|float|bool|null>, peso?: int, descricao?: string, no_menu?: bool, rotulo_menu?: string, data_publicacao?: string|null, data_validade?: string|null} $mudancas
     * @throws ConflitoDeVersao se a versão atual não for a esperada
     * @throws \InvalidArgumentException valor, estado ou URL inválidos (nada é gravado)
     */
    public function atualizar(int $id, array $mudancas, string $motivo = '', ?int $versaoEsperada = null, bool $liberarUrl = false, ?int $usuarioId = null): Objeto
    {
        return $this->conexao->transactional(function () use ($id, $mudancas, $motivo, $versaoEsperada, $liberarUrl, $usuarioId): Objeto {
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
            if (array_key_exists('no_menu', $mudancas)) {
                $linha['no_menu'] = empty($mudancas['no_menu']) ? 0 : 1;
            }
            if (array_key_exists('rotulo_menu', $mudancas)) {
                $linha['rotulo_menu'] = trim((string) $mudancas['rotulo_menu']) === '' ? null : trim((string) $mudancas['rotulo_menu']);
            }
            foreach (['data_publicacao', 'data_validade'] as $campo) {
                if (array_key_exists($campo, $mudancas)) {
                    $linha[$campo] = $this->dataDaVigencia($campo, $mudancas[$campo]);
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
                $this->exigirObrigatoriosTocados($classe, $novos, array_keys($mudancas['dados']));
                $this->exigirRegrasDoTipo($classe, $novos);
                $linha['dados'] = json_encode($novos, JSON_THROW_ON_ERROR);
                $this->conexao->delete('objeto_indice', ['objeto_id' => $id]);
                $this->indexar($id, $classe, $novos);
            }
            $this->conexao->update('objeto', $linha, ['id' => $id]);
            if (isset($linha['titulo']) || array_key_exists('descricao', $linha) || isset($linha['dados'])) {
                $novo = $this->porId($id) ?? throw new \RuntimeException('Objeto sumiu durante a atualização');
                $this->buscador->indexar($id, $novo->classeId, $novo->titulo, $novo->descricao, $novo->dados);
            }
            $this->registrarVersao($id, $motivo, $usuarioId);
            // Só os NOMES do que mudou, nunca o conteúdo (título, texto, dados pessoais ficam fora da auditoria).
            $campos = array_values(array_diff(array_keys($linha), ['versao', 'atualizado_em', 'titulo_busca', 'titulo_norm', 'dados']));
            foreach (array_keys($mudancas['dados'] ?? []) as $propriedade) {
                $campos[] = 'dados.' . $propriedade;
            }
            $this->auditar('objeto.atualizar', $usuarioId, $id, ['versao' => $atual->versao + 1, 'campos' => implode(',', $campos), 'motivo' => $motivo === '' ? null : mb_substr($motivo, 0, 120)]);
            if (isset($linha['status']) && $linha['status'] !== $atual->status) {
                $this->auditar('objeto.estado', $usuarioId, $id, ['de' => $atual->status, 'para' => (string) $linha['status']]);
            }

            return $this->porId($id) ?? throw new \RuntimeException('Objeto sumiu durante a atualização');
        });
    }

    /**
     * Muda o objeto de pai (e a subárvore junto). A URL amigável não depende da árvore, então nenhum endereço muda.
     *
     * @throws ConflitoDeVersao
     * @throws ConflitoDeArvore com `$destinoForaDaLixeira`, se o destino está na lixeira
     * @throws ViolacaoDeRegra se o tipo do novo pai não aceita o tipo deste conteúdo
     */
    public function mover(int $id, ?int $novoPaiId, string $motivo = 'mover', ?int $usuarioId = null, ?int $versaoEsperada = null, bool $destinoForaDaLixeira = false): Objeto
    {
        return $this->conexao->transactional(function () use ($id, $novoPaiId, $motivo, $usuarioId, $versaoEsperada, $destinoForaDaLixeira): Objeto {
            // Com `$destinoForaDaLixeira`, o destino fica travado e é recusado se estiver na lixeira (ConflitoDeArvore).
            if ($destinoForaDaLixeira && $novoPaiId !== null) {
                $this->travar($novoPaiId);
                if ($this->conexao->fetchOne('SELECT status FROM objeto WHERE id = ?', [$novoPaiId]) === 'apagado') {
                    throw new ConflitoDeArvore('O destino está na lixeira');
                }
            }
            $o = $this->porId($id) ?? throw new \InvalidArgumentException("Objeto {$id} não existe");
            if ($versaoEsperada !== null && $versaoEsperada !== $o->versao) {
                throw new ConflitoDeVersao($versaoEsperada, $o->versao);
            }
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
                if ($novoPaiId !== $o->paiId) {
                    $this->exigirQueOPaiAceita($novoPaiId, $this->classeDoObjeto($o));
                }
            }
            $novo = $caminhoPai . $id . '/';
            foreach ($this->conexao->fetchAllAssociative("SELECT id, caminho FROM objeto WHERE site_id = ? AND caminho LIKE ? ESCAPE '!'", [$o->siteId, $this->escaparLike($o->caminho) . '%']) as $d) {
                $this->conexao->update('objeto', ['caminho' => $novo . substr((string) $d['caminho'], strlen($o->caminho))], ['id' => $d['id']]);
            }
            $this->conexao->update('objeto', ['pai_id' => $novoPaiId, 'versao' => $o->versao + 1, 'atualizado_em' => gmdate('c')], ['id' => $id]);
            $this->registrarVersao($id, $motivo, $usuarioId);
            $this->auditar('objeto.mover', $usuarioId, $id, ['de' => $o->paiId, 'para' => $novoPaiId]);

            return $this->porId($id) ?? throw new \RuntimeException('Objeto sumiu durante a movimentação');
        });
    }

    /**
     * Histórico do objeto, da versão mais nova para a mais antiga.
     *
     * @return list<array{versao: int, titulo: string, status: string, motivo: string, criado_em: string, usuario_id: int|null}>
     */
    public function versoes(int $id): array
    {
        $linhas = $this->conexao->fetchAllAssociative('SELECT versao, titulo, status, motivo, criado_em, usuario_id FROM objeto_versao WHERE objeto_id = ? ORDER BY versao DESC', [$id]);

        return array_map(static fn (array $l): array => [
            'versao' => (int) $l['versao'], 'titulo' => (string) $l['titulo'], 'status' => (string) $l['status'],
            'motivo' => (string) ($l['motivo'] ?? ''), 'criado_em' => (string) $l['criado_em'],
            'usuario_id' => $l['usuario_id'] === null ? null : (int) $l['usuario_id'],
        ], $linhas);
    }

    /**
     * Volta o conteúdo de uma versão antiga **como uma nova versão** (o histórico nunca é apagado). O pai atual é mantido.
     * `$manterEstado` = true mantém o estado e as datas de publicação/validade atuais (restaurar o texto não pode, sozinho,
     * publicar nem tirar do ar: isso é outra permissão).
     *
     * @throws ConflitoDeVersao se `$versaoEsperada` não for a versão atual
     * @throws \InvalidArgumentException versão inexistente, ou a URL/os dados dela não são mais aceitos
     */
    public function restaurar(int $id, int $versao, string $motivo = '', ?int $usuarioId = null, ?int $versaoEsperada = null, bool $manterEstado = false): Objeto
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

        $mudancas = [
            'titulo' => (string) $v['titulo'],
            'url_amigavel' => (string) $v['url_amigavel'],
            'dados' => $dados,
            'peso' => (int) $v['peso'],
            'descricao' => $v['descricao'] === null ? '' : (string) $v['descricao'],
        ];
        if (!$manterEstado) {
            $mudancas['status'] = (string) $v['status'];
            $mudancas['data_publicacao'] = $v['data_publicacao'] === null ? null : (string) $v['data_publicacao'];
            $mudancas['data_validade'] = $v['data_validade'] === null ? null : (string) $v['data_validade'];
        }

        return $this->atualizar($id, $mudancas, $motivo !== '' ? $motivo : "restaurada a versão {$versao}", $versaoEsperada, false, $usuarioId);
    }

    /**
     * Cria um filho **só se o pai não estiver na lixeira**, com a linha do pai travada até o fim da transação: um "mandar o pai
     * para a lixeira" simultâneo (que trava a mesma linha) espera ou acontece antes e é visto aqui.
     *
     * @param array<string, string|int|float|bool> $dados
     * @param array{peso?: int, descricao?: string, no_menu?: bool, rotulo_menu?: string, data_publicacao?: string|null, data_validade?: string|null, criado_por?: int|null} $meta
     * @param (\Closure(int): array<string, string|int|float|bool>)|null $aposInserir ver `criar()`
     * @throws ConflitoDeArvore se o pai está na lixeira (ou não existe)
     * @throws \InvalidArgumentException endereço, estado ou dados inválidos
     * @throws \Doctrine\DBAL\Exception\UniqueConstraintViolationException endereço tomado por outro no mesmo instante
     */
    public function criarDentro(int $siteId, Classe $classe, int $paiId, string $titulo, string $urlAmigavel, array $dados, string $status = 'privado', array $meta = [], ?\Closure $aposInserir = null): Objeto
    {
        return $this->conexao->transactional(function () use ($siteId, $classe, $paiId, $titulo, $urlAmigavel, $dados, $status, $meta, $aposInserir): Objeto {
            $this->travar($paiId);
            $statusDoPai = $this->conexao->fetchOne('SELECT status FROM objeto WHERE id = ? AND site_id = ?', [$paiId, $siteId]);
            if ($statusDoPai === false || $statusDoPai === 'apagado') {
                throw new ConflitoDeArvore('O conteúdo onde ele ficaria está na lixeira');
            }

            return $this->criar($siteId, $classe, $paiId, $titulo, $urlAmigavel, $dados, $status, $meta, false, $aposInserir);
        });
    }

    /**
     * Manda para a lixeira (estado `apagado`) **só se não houver filhos fora dela**, com a linha travada até o fim da transação
     * (criar, mover para dentro ou tirar da lixeira um filho travam a mesma linha do pai).
     *
     * @throws ConflitoDeVersao
     * @throws ConflitoDeArvore se há filhos fora da lixeira
     */
    public function mandarParaLixeira(int $id, int $versaoEsperada, string $motivo = '', ?int $usuarioId = null): Objeto
    {
        return $this->conexao->transactional(function () use ($id, $versaoEsperada, $motivo, $usuarioId): Objeto {
            $this->travar($id);
            if ($this->filhosForaDaLixeira($id) > 0) {
                throw new ConflitoDeArvore('Há conteúdo fora da lixeira dentro dele');
            }

            return $this->atualizar($id, ['status' => 'apagado'], $motivo, $versaoEsperada, false, $usuarioId);
        });
    }

    /**
     * Tira da lixeira (volta como `$status`) **só se o pai não estiver nela**, com a linha do pai travada.
     *
     * @throws ConflitoDeVersao
     * @throws ConflitoDeArvore se o pai está na lixeira
     */
    public function tirarDaLixeira(int $id, int $versaoEsperada, string $motivo = '', ?int $usuarioId = null, string $status = 'privado'): Objeto
    {
        return $this->conexao->transactional(function () use ($id, $versaoEsperada, $motivo, $usuarioId, $status): Objeto {
            $o = $this->porId($id) ?? throw new \InvalidArgumentException("Objeto {$id} não existe");
            if ($o->paiId !== null) {
                $this->travar($o->paiId);
                if ($this->conexao->fetchOne('SELECT status FROM objeto WHERE id = ?', [$o->paiId]) === 'apagado') {
                    throw new ConflitoDeArvore('O conteúdo onde ele está também está na lixeira');
                }
            }

            return $this->atualizar($id, ['status' => $status], $motivo, $versaoEsperada, false, $usuarioId);
        });
    }

    /** Quantos filhos diretos não estão na lixeira. */
    public function filhosForaDaLixeira(int $id): int
    {
        return (int) $this->conexao->fetchOne("SELECT COUNT(*) FROM objeto WHERE pai_id = ? AND status <> 'apagado'", [$id]);
    }

    /**
     * O tipo do pai aceita conteúdo desta classe dentro dele? (`aceita_filhos` e `filhas`.) Vale para criar e mover, venha o pedido
     * da administração, da linha de comando ou de um importador.
     *
     * @throws ViolacaoDeRegra
     */
    private function exigirQueOPaiAceita(int $paiId, Classe $filha): void
    {
        $classeId = $this->conexao->fetchOne('SELECT classe_id FROM objeto WHERE id = ?', [$paiId]);
        $tipoDoPai = $classeId === false ? null : (new ClasseRepositorio($this->conexao))->porId((int) $classeId);
        if ($tipoDoPai !== null && !$tipoDoPai->aceita($filha)) {
            throw new ViolacaoDeRegra(ViolacaoDeRegra::TIPO_NAO_ACEITO, "O tipo {$tipoDoPai->nome} não aceita conteúdo do tipo {$filha->nome} dentro dele.");
        }
    }

    private function classeDoObjeto(Objeto $o): Classe
    {
        return (new ClasseRepositorio($this->conexao))->porId($o->classeId) ?? throw new \RuntimeException("Classe {$o->classeId} não existe");
    }

    /**
     * Ao criar, todo campo obrigatório (e não arquivado) precisa ter valor. `$pularArquivo` adia a conferência das
     * propriedades `arquivo`/`imagem` (upload de arquivo na criação, ADR 0014 §4: o arquivo só existe depois do insert,
     * então a chamada inicial pula essas propriedades e uma segunda chamada, já com os dados mesclados, confere todas).
     *
     * @param array<string, string|int|float> $dados
     * @throws ViolacaoDeRegra
     */
    private function exigirObrigatorios(Classe $classe, array $dados, bool $pularArquivo = false): void
    {
        $this->exigirObrigatoriosTocados($classe, $dados, null, $pularArquivo);
    }

    /**
     * Regras que dependem do tipo, além das propriedades obrigatórias: hoje só o `link` (um destino, interno ou externo).
     *
     * @param array<string, mixed> $dados valores já validados
     * @throws ViolacaoDeRegra
     */
    private function exigirRegrasDoTipo(Classe $classe, array $dados): void
    {
        // Só no `link` do pacote padrão (tem `destino_interno`): um tipo homônimo criado à mão em outra instalação fica como está.
        if ($classe->nome === RegrasDeLink::CLASSE && in_array('destino_interno', array_column($classe->propriedades, 'nome'), true)) {
            RegrasDeLink::exigir($dados);
        }
    }

    /**
     * Campos obrigatórios (não arquivados) sem valor. Ao atualizar, só os campos **tocados** pela mudança contam (`$tocados`):
     * um conteúdo antigo que já não cumpria a regra continua podendo ser lido e editado em outros campos; o que não se pode é
     * apagar ou esvaziar um obrigatório. `null` = todos (criação).
     *
     * @param array<string, string|int|float> $dados valores já validados (após a mudança)
     * @param list<string|int>|null $tocados
     * @throws ViolacaoDeRegra
     */
    private function exigirObrigatoriosTocados(Classe $classe, array $dados, ?array $tocados, bool $pularArquivo = false): void
    {
        foreach ($classe->propriedades as $p) {
            if (!($p['obrigatorio'] ?? false) || ($p['arquivada'] ?? false)) {
                continue;
            }
            if ($pularArquivo && in_array($p['tipo'], ['arquivo', 'imagem'], true)) {
                continue;
            }
            if ($tocados !== null && !in_array($p['nome'], array_map('strval', $tocados), true)) {
                continue;
            }
            $valor = $dados[$p['nome']] ?? null;
            if ($valor === null || (is_string($valor) && trim($valor) === '')) {
                throw new ViolacaoDeRegra(ViolacaoDeRegra::CAMPO_OBRIGATORIO, 'O campo ' . Classe::rotuloDe($p) . ' é obrigatório.', $p['nome']);
            }
        }
    }

    /**
     * Data de publicação ou de validade, sempre gravada em UTC no formato ISO 8601 (`2026-12-31T21:30:00+00:00`): a comparação
     * "já chegou?" / "já passou?" é feita como texto, então todas precisam ter o mesmo formato. Vazio = sem data.
     *
     * @throws ViolacaoDeRegra data que não se entende
     */
    private function dataDaVigencia(string $campo, ?string $valor): ?string
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }
        try {
            $data = new \DateTimeImmutable(trim($valor), new \DateTimeZone('UTC'));
            // Data impossível (2026-02-31) não dá erro no construtor: o PHP "corrige" para 2026-03-03 e deixa só um aviso.
            $problemas = \DateTimeImmutable::getLastErrors();
            if ($problemas !== false && ($problemas['warning_count'] > 0 || $problemas['error_count'] > 0)) {
                throw new \Exception('data impossível');
            }

            return $data->setTimezone(new \DateTimeZone('UTC'))->format('c');
        } catch (\Exception) {
            throw new ViolacaoDeRegra(ViolacaoDeRegra::DATA_INVALIDA, 'A data de ' . ($campo === 'data_publicacao' ? 'publicação' : 'validade') . ' não é válida.', $campo);
        }
    }

    /**
     * Trava a linha até o fim da transação: um UPDATE que não muda nada pega o bloqueio de linha no MySQL e no PostgreSQL e o
     * de escrita no SQLite, então duas operações que travam o mesmo objeto ficam uma depois da outra.
     */
    private function travar(int $id): void
    {
        $this->conexao->executeStatement('UPDATE objeto SET versao = versao WHERE id = ?', [$id]);
    }

    /**
     * O endereço pode ser usado por `$exceto` (ou por um objeto novo, se null)? Não pode se já é de outro objeto do site nem
     * se hoje redireciona para outro objeto (histórico de URLs); o endereço antigo do próprio objeto volta a ser dele.
     */
    public function urlDisponivel(int $siteId, string $url, ?int $exceto = null): bool
    {
        $dono = $this->conexao->fetchOne('SELECT id FROM objeto WHERE site_id = ? AND url_amigavel = ?', [$siteId, $url]);
        if ($dono !== false && (int) $dono !== $exceto) {
            return false;
        }
        $antigo = $this->conexao->fetchOne('SELECT objeto_id FROM url_historico WHERE site_id = ? AND url_amigavel = ?', [$siteId, $url]);

        return $antigo === false || (int) $antigo === $exceto;
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

    /** @param array<string, scalar|null> $detalhe */
    private function auditar(string $acao, ?int $usuarioId, int $objetoId, array $detalhe): void
    {
        $this->auditoria?->registrar($acao, $usuarioId === null ? 'sistema' : 'usuario:' . $usuarioId, $usuarioId, $objetoId, $detalhe);
    }

    /** Grava o estado atual do objeto como uma linha de `objeto_versao`. */
    private function registrarVersao(int $id, string $motivo, ?int $usuarioId = null): void
    {
        $o = $this->conexao->fetchAssociative('SELECT * FROM objeto WHERE id = ?', [$id]);
        if ($o === false) {
            return;
        }
        $this->conexao->insert('objeto_versao', [
            'objeto_id' => $id, 'versao' => $o['versao'], 'titulo' => $o['titulo'], 'url_amigavel' => $o['url_amigavel'],
            'status' => $o['status'], 'dados' => $o['dados'], 'peso' => $o['peso'], 'descricao' => $o['descricao'],
            'data_publicacao' => $o['data_publicacao'], 'data_validade' => $o['data_validade'], 'pai_id' => $o['pai_id'],
            'motivo' => $motivo === '' ? null : mb_substr($motivo, 0, 300), 'usuario_id' => $usuarioId, 'criado_em' => gmdate('c'),
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
                'arquivo', 'imagem' => $this->arquivo($siteId, $chave, $valor, $p['tipo']),
                'html' => is_string($valor) ? HtmlSeguro::limpar($valor) : throw new \InvalidArgumentException("Propriedade {$chave} deve ser texto HTML"),
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

    /**
     * O valor de uma propriedade `arquivo`/`imagem` é o id de uma linha de `arquivo` (como uma `ref`, mas para `arquivo`, não
     * para `objeto`). Defesa em profundidade: o upload (`ServicoDeUpload`) já escolheu o tipo certo pela whitelist; aqui só
     * confere que o arquivo existe, é deste site e o tipo (`arquivo`/`imagem`) bate com o da propriedade — um formulário
     * forjado não pode trocar o id por um arquivo de outro site ou de outra propriedade.
     */
    private function arquivo(int $siteId, string $chave, string|int|float|bool $valor, string $tipoPropriedade): int
    {
        $id = is_int($valor) ? $valor : (is_string($valor) && ctype_digit($valor) ? (int) $valor : 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException("Propriedade {$chave} deve ser o id de um arquivo enviado");
        }
        $linha = $this->conexao->fetchAssociative('SELECT site_id, extensao FROM arquivo WHERE id = ?', [$id]);
        if ($linha === false || (int) $linha['site_id'] !== $siteId) {
            throw new \InvalidArgumentException("Propriedade {$chave} aponta para um arquivo que não existe neste site: {$id}");
        }
        $ehImagem = (TiposDeArquivo::da((string) $linha['extensao'])['imagem'] ?? false) === true;
        if ($ehImagem !== ($tipoPropriedade === 'imagem')) {
            throw new \InvalidArgumentException("Propriedade {$chave} deve apontar para um arquivo do tipo {$tipoPropriedade}");
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
            isset($linha['criado_por']) ? (int) $linha['criado_por'] : null,
            (int) ($linha['no_menu'] ?? 0) === 1,
            (string) ($linha['rotulo_menu'] ?? ''),
        );
    }
}
