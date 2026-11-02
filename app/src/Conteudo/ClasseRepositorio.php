<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

use Doctrine\DBAL\Connection;
use Pbl\Arquivos\TiposDeArquivo;

/**
 * Classes (tipos de conteúdo). Valem para a instalação inteira: o mesmo tipo serve a todos os sites.
 *
 * Regra de segurança dos dados: nada aqui apaga valor guardado em objeto. Remover uma propriedade ou mudar o tipo, a classe
 * apontada ou a indexação só é aceito se nenhum objeto (nem versão antiga) tem valor nela; com valores, a saída é **arquivar**
 * a propriedade (`arquivada`), que a tira dos formulários e mantém os dados. Apagar a classe só sem objetos e sem outra classe
 * que dependa dela.
 *
 * @phpstan-import-type Propriedade from Classe
 */
final class ClasseRepositorio
{
    public const TIPOS = ['texto', 'numero', 'html', 'data', 'booleano', 'ref', 'arquivo', 'imagem'];
    /** Nomes que o contexto do molde já usa (`#id`, `#titulo`...). */
    public const RESERVADOS = ['id', 'titulo', 'url', 'url_amigavel', 'classe', 'classe_id', 'site', 'pai', 'filhos', 'peso', 'descricao', 'data_publicacao', 'data_validade', 'status', 'apagado', 'temfilhos'];
    public const MAX_NOME = 64;
    public const MAX_ROTULO = 100;
    public const MAX_DESCRICAO = 2000;
    public const MAX_PROPRIEDADES = 100;

    public function __construct(private readonly Connection $conexao)
    {
    }

    /**
     * @param list<array{nome: string, tipo: string, indexavel?: bool, classe?: string, rotulo?: string, obrigatorio?: bool, arquivada?: bool, tipos?: list<string>, tamanho_max_mb?: int}> $propriedades
     * @param array{descricao?: string, aceita_filhos?: bool, filhas?: list<string>, fixa?: bool} $meta
     */
    public function criar(string $nome, array $propriedades, array $meta = []): Classe
    {
        if (!self::nomeValido($nome)) {
            throw new \InvalidArgumentException("Nome de classe inválido: {$nome}");
        }
        $normalizadas = $this->normalizarPropriedades($propriedades, $nome);
        [$descricao, $aceita, $filhas] = $this->normalizarMeta($meta, $nome);
        $this->conexao->insert('classe', [
            'nome' => $nome,
            'propriedades' => json_encode($normalizadas, JSON_THROW_ON_ERROR),
            'descricao' => $descricao === '' ? null : $descricao,
            'aceita_filhos' => $aceita ? 1 : 0,
            'filhas' => $filhas === [] ? null : json_encode($filhas, JSON_THROW_ON_ERROR),
            'versao' => 1,
            'fixa' => ($meta['fixa'] ?? false) ? 1 : 0,
        ]);

        return new Classe((int) $this->conexao->lastInsertId(), $nome, $normalizadas, $descricao, $aceita, $filhas, 1, $meta['fixa'] ?? false);
    }

    public function porNome(string $nome): ?Classe
    {
        $linha = $this->conexao->fetchAssociative('SELECT * FROM classe WHERE nome = ?', [$nome]);

        return $linha === false ? null : $this->hidratar($linha);
    }

    public function porId(int $id): ?Classe
    {
        $linha = $this->conexao->fetchAssociative('SELECT * FROM classe WHERE id = ?', [$id]);

        return $linha === false ? null : $this->hidratar($linha);
    }

    /** @return list<Classe> por nome */
    public function todas(): array
    {
        return array_map($this->hidratar(...), $this->conexao->fetchAllAssociative('SELECT * FROM classe ORDER BY nome'));
    }

    /** @return array<int, int> id da classe => quantos objetos a usam (em qualquer estado, inclusive `apagado`) */
    public function objetosPorClasse(): array
    {
        $saida = [];
        foreach ($this->conexao->fetchAllAssociative('SELECT classe_id, COUNT(*) AS n FROM objeto GROUP BY classe_id') as $l) {
            $saida[(int) $l['classe_id']] = (int) $l['n'];
        }

        return $saida;
    }

    public function contarObjetos(int $classeId): int
    {
        return (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM objeto WHERE classe_id = ?', [$classeId]);
    }

    /**
     * Quantos objetos da classe têm valor em cada propriedade: `objetos` olha o estado atual, `historico` conta os objetos com
     * valor em qualquer versão guardada (restaurar uma versão antiga traria o valor de volta). Lido em PHP, sem função de JSON
     * do banco, para dar o mesmo resultado em SQLite, MySQL e PostgreSQL.
     *
     * @return array<string, array{objetos: int, historico: int}> por nome de propriedade (todas as da classe aparecem)
     */
    public function usoDasPropriedades(Classe $classe): array
    {
        $atuaisPorChave = [];
        foreach ($classe->propriedades as $p) {
            $atuaisPorChave[$p['nome']] = 0;
        }
        $atuais = $this->conexao->executeQuery('SELECT id, dados FROM objeto WHERE classe_id = ?', [$classe->id]);
        $comValor = []; // nome => [objetoId => true]
        while (($l = $atuais->fetchAssociative()) !== false) {
            foreach ($this->chavesComValor((string) $l['dados']) as $chave) {
                if (isset($atuaisPorChave[$chave])) {
                    $atuaisPorChave[$chave]++;
                    $comValor[$chave][(int) $l['id']] = true;
                }
            }
        }
        $versoes = $this->conexao->executeQuery(
            'SELECT v.objeto_id, v.dados FROM objeto_versao v JOIN objeto o ON o.id = v.objeto_id WHERE o.classe_id = ?',
            [$classe->id],
        );
        while (($l = $versoes->fetchAssociative()) !== false) {
            foreach ($this->chavesComValor((string) $l['dados']) as $chave) {
                if (isset($atuaisPorChave[$chave])) {
                    $comValor[$chave][(int) $l['objeto_id']] = true;
                }
            }
        }
        $uso = [];
        foreach ($atuaisPorChave as $chave => $n) {
            $uso[(string) $chave] = ['objetos' => $n, 'historico' => count($comValor[$chave] ?? [])];
        }

        return $uso;
    }

    /** @return list<string> nomes das outras classes que dependem desta (referência restrita a ela ou lista de filhas) */
    public function dependentes(Classe $classe): array
    {
        $saida = [];
        foreach ($this->todas() as $outra) {
            if ($outra->id === $classe->id) {
                continue;
            }
            $depende = in_array($classe->nome, $outra->filhas, true);
            foreach ($outra->propriedades as $p) {
                $depende = $depende || ($p['tipo'] === 'ref' && ($p['classe'] ?? null) === $classe->nome);
            }
            if ($depende) {
                $saida[] = $outra->nome;
            }
        }

        return $saida;
    }

    /**
     * Grava a nova definição inteira da classe (o nome técnico não muda). Propriedades são casadas pelo nome técnico.
     *
     * @param list<array{nome: string, tipo: string, indexavel?: bool, classe?: string, rotulo?: string, obrigatorio?: bool, arquivada?: bool, tipos?: list<string>, tamanho_max_mb?: int}> $propriedades
     * @param array{descricao?: string, aceita_filhos?: bool, filhas?: list<string>} $meta
     * @throws ConflitoDeVersao se outra pessoa salvou a classe depois de `$versaoEsperada`
     * @throws \InvalidArgumentException se a mudança apagaria ou invalidaria valores guardados, ou se algo é inválido
     */
    public function atualizar(int $id, int $versaoEsperada, array $propriedades, array $meta): Classe
    {
        return $this->conexao->transactional(function () use ($id, $versaoEsperada, $propriedades, $meta): Classe {
            $atual = $this->porId($id) ?? throw new \InvalidArgumentException("Classe {$id} não existe");
            if ($atual->versao !== $versaoEsperada) {
                throw new ConflitoDeVersao($versaoEsperada, $atual->versao);
            }
            $novas = $this->normalizarPropriedades($propriedades, $atual->nome);
            [$descricao, $aceita, $filhas] = $this->normalizarMeta($meta, $atual->nome);

            $porNome = [];
            foreach ($novas as $p) {
                $porNome[$p['nome']] = $p;
            }
            if ($atual->fixa) {
                $this->exigirBaseDaClasseFixa($atual, $novas);
            }
            $uso = null;
            foreach ($atual->propriedades as $antiga) {
                $nova = $porNome[$antiga['nome']] ?? null;
                $estrutural = $nova === null || $nova['tipo'] !== $antiga['tipo'] || ($nova['classe'] ?? null) !== ($antiga['classe'] ?? null)
                    || $nova['indexavel'] !== $antiga['indexavel'];
                if (!$estrutural) {
                    continue;
                }
                $uso ??= $this->usoDasPropriedades($atual);
                $n = max($uso[$antiga['nome']]['objetos'] ?? 0, $uso[$antiga['nome']]['historico'] ?? 0);
                if ($n > 0) {
                    throw new \InvalidArgumentException($nova === null
                        ? "A propriedade {$antiga['nome']} tem valores em {$n} conteúdo(s): arquive em vez de remover"
                        : "A propriedade {$antiga['nome']} tem valores em {$n} conteúdo(s): o tipo, a classe apontada e a indexação não podem mudar");
                }
            }

            $alteradas = $this->conexao->executeStatement(
                'UPDATE classe SET propriedades = ?, descricao = ?, aceita_filhos = ?, filhas = ?, versao = ? WHERE id = ? AND versao = ?',
                [
                    json_encode($novas, JSON_THROW_ON_ERROR), $descricao === '' ? null : $descricao, $aceita ? 1 : 0,
                    $filhas === [] ? null : json_encode($filhas, JSON_THROW_ON_ERROR), $versaoEsperada + 1, $id, $versaoEsperada,
                ],
            );
            if ($alteradas !== 1) {
                throw new ConflitoDeVersao($versaoEsperada, (int) $this->conexao->fetchOne('SELECT versao FROM classe WHERE id = ?', [$id]));
            }

            return new Classe($id, $atual->nome, $novas, $descricao, $aceita, $filhas, $versaoEsperada + 1, $atual->fixa);
        });
    }

    /**
     * Apaga a classe. Só se nenhum objeto a usa (em qualquer estado) e nenhuma outra classe depende dela.
     *
     * @throws ConflitoDeVersao
     * @throws \InvalidArgumentException com o motivo do bloqueio
     */
    public function apagar(int $id, int $versaoEsperada): void
    {
        $this->conexao->transactional(function () use ($id, $versaoEsperada): void {
            $atual = $this->porId($id) ?? throw new \InvalidArgumentException("Classe {$id} não existe");
            if ($atual->versao !== $versaoEsperada) {
                throw new ConflitoDeVersao($versaoEsperada, $atual->versao);
            }
            if ($atual->fixa) {
                throw new \InvalidArgumentException("A classe {$atual->nome} é fixa (essencial ao sistema) e não pode ser apagada");
            }
            $n = $this->contarObjetos($id);
            if ($n > 0) {
                throw new \InvalidArgumentException("A classe {$atual->nome} é usada por {$n} conteúdo(s)");
            }
            $dependentes = $this->dependentes($atual);
            if ($dependentes !== []) {
                throw new \InvalidArgumentException("Outras classes dependem de {$atual->nome}: " . implode(', ', $dependentes));
            }
            if ($this->conexao->executeStatement('DELETE FROM classe WHERE id = ? AND versao = ?', [$id, $versaoEsperada]) !== 1) {
                throw new ConflitoDeVersao($versaoEsperada, (int) $this->conexao->fetchOne('SELECT versao FROM classe WHERE id = ?', [$id]));
            }
        });
    }

    /**
     * Classe fixa não perde as propriedades da base (as de `ClassesPadrao::nomesDaBase`): nem removidas, nem arquivadas.
     * Propriedades acrescentadas depois seguem as regras de sempre.
     *
     * @param list<Propriedade> $novas
     */
    private function exigirBaseDaClasseFixa(Classe $atual, array $novas): void
    {
        $porNome = [];
        foreach ($novas as $p) {
            $porNome[$p['nome']] = $p;
        }
        $existentes = array_map(static fn (array $p): string => $p['nome'], $atual->propriedades);
        foreach (ClassesPadrao::nomesDaBase($atual->nome) as $nome) {
            if (!in_array($nome, $existentes, true)) {
                continue; // base que este banco nunca teve (classe criada à mão antes): nada a proteger
            }
            if (!isset($porNome[$nome])) {
                throw new \InvalidArgumentException("A classe {$atual->nome} é fixa: a propriedade {$nome} faz parte da base e não pode ser removida");
            }
            if ($porNome[$nome]['arquivada'] ?? false) {
                throw new \InvalidArgumentException("A classe {$atual->nome} é fixa: a propriedade {$nome} faz parte da base e não pode ser arquivada");
            }
        }
    }

    public static function nomeValido(string $nome): bool
    {
        return strlen($nome) <= self::MAX_NOME && preg_match('/^[a-z][a-z0-9_]*$/D', $nome) === 1;
    }

    /**
     * @param list<array{nome: string, tipo: string, indexavel?: bool, classe?: string, rotulo?: string, obrigatorio?: bool, arquivada?: bool, tipos?: list<string>, tamanho_max_mb?: int}> $propriedades
     * @param string $propria nome da classe sendo gravada (uma referência pode apontar para a própria classe)
     * @return list<Propriedade>
     */
    private function normalizarPropriedades(array $propriedades, string $propria): array
    {
        if (count($propriedades) > self::MAX_PROPRIEDADES) {
            throw new \InvalidArgumentException('Uma classe pode ter até ' . self::MAX_PROPRIEDADES . ' propriedades');
        }
        $normalizadas = [];
        $vistos = [];
        foreach ($propriedades as $p) {
            if (!self::nomeValido($p['nome']) || in_array($p['nome'], self::RESERVADOS, true)) {
                throw new \InvalidArgumentException("Nome de propriedade inválido: {$p['nome']}");
            }
            if (isset($vistos[$p['nome']])) {
                throw new \InvalidArgumentException("Propriedade repetida: {$p['nome']}");
            }
            $vistos[$p['nome']] = true;
            if (!in_array($p['tipo'], self::TIPOS, true)) {
                throw new \InvalidArgumentException("Tipo de propriedade inválido: {$p['tipo']}");
            }
            $normalizada = ['nome' => $p['nome'], 'tipo' => $p['tipo'], 'indexavel' => $p['indexavel'] ?? false];
            if ($p['tipo'] === 'ref') {
                // Referência sem índice não serve para ordenar nem filtrar pelo objeto apontado: fica sempre indexada.
                $normalizada['indexavel'] = true;
                if (isset($p['classe'])) {
                    if ($p['classe'] !== $propria && $this->porNome($p['classe']) === null) {
                        throw new \InvalidArgumentException("A referência {$p['nome']} aponta para uma classe que não existe: {$p['classe']}");
                    }
                    $normalizada['classe'] = $p['classe'];
                }
            }
            if (in_array($p['tipo'], ['arquivo', 'imagem'], true)) {
                // Não há como indexar (ordenar/filtrar pelo) um arquivo: fica sempre fora do índice.
                $normalizada['indexavel'] = false;
                [$tipos, $tamanhoMaxMb] = $this->normalizarRestricoesDeArquivo($p);
                if ($tipos !== null) {
                    $normalizada['tipos'] = $tipos;
                }
                if ($tamanhoMaxMb !== null) {
                    $normalizada['tamanho_max_mb'] = $tamanhoMaxMb;
                }
            }
            // Campos de apresentação: só gravados quando têm valor, para o JSON das classes antigas continuar igual.
            $rotulo = trim($p['rotulo'] ?? '');
            if ($rotulo !== '') {
                if (mb_strlen($rotulo) > self::MAX_ROTULO || !mb_check_encoding($rotulo, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $rotulo) === 1) {
                    throw new \InvalidArgumentException("Rótulo inválido na propriedade {$p['nome']}");
                }
                $normalizada['rotulo'] = $rotulo;
            }
            if ($p['obrigatorio'] ?? false) {
                $normalizada['obrigatorio'] = true;
            }
            if ($p['arquivada'] ?? false) {
                $normalizada['arquivada'] = true;
            }
            $normalizadas[] = $normalizada;
        }

        return $normalizadas;
    }

    /**
     * `tipos`: subconjunto das extensões que a propriedade aceita (das de `imagem` ou das de documento, conforme o tipo da
     * propriedade); vazio ou ausente = todas as da categoria. `tamanho_max_mb`: teto de tamanho desta propriedade (1 a
     * `TiposDeArquivo::TETO_MB`); ausente = `TiposDeArquivo::PADRAO_MB`.
     *
     * `$p` chega com o tipo largo de `array<string, mixed>` (não a forma estreita de `Propriedade`) de propósito: vem de
     * fora (tela ou chamador direto), então `tipos`/`tamanho_max_mb` não têm garantia de tipo até serem conferidos aqui.
     *
     * @param array<string, mixed> $p
     * @return array{0: list<string>|null, 1: int|null}
     */
    private function normalizarRestricoesDeArquivo(array $p): array
    {
        $base = $p['tipo'] === 'imagem' ? TiposDeArquivo::imagens() : TiposDeArquivo::documentos();
        $tipos = null;
        if (isset($p['tipos']) && is_array($p['tipos']) && $p['tipos'] !== []) {
            $tipos = array_values(array_unique(array_map('strval', $p['tipos'])));
            foreach ($tipos as $extensao) {
                if (!in_array($extensao, $base, true)) {
                    throw new \InvalidArgumentException("Extensão não aceita na propriedade {$p['nome']}: {$extensao}");
                }
            }
            sort($tipos);
        }
        $tamanhoMaxMb = null;
        if (isset($p['tamanho_max_mb'])) {
            $tamanhoMaxMb = is_int($p['tamanho_max_mb']) ? $p['tamanho_max_mb'] : (int) $p['tamanho_max_mb'];
            if ($tamanhoMaxMb < 1 || $tamanhoMaxMb > TiposDeArquivo::TETO_MB) {
                throw new \InvalidArgumentException("Tamanho máximo inválido na propriedade {$p['nome']}: até " . TiposDeArquivo::TETO_MB . ' MB');
            }
        }

        return [$tipos, $tamanhoMaxMb];
    }

    /**
     * @param array{descricao?: string, aceita_filhos?: bool, filhas?: list<string>} $meta
     * @return array{0: string, 1: bool, 2: list<string>}
     */
    private function normalizarMeta(array $meta, string $propria): array
    {
        $descricao = trim(str_replace(["\r\n", "\r"], "\n", $meta['descricao'] ?? ''));
        if (mb_strlen($descricao) > self::MAX_DESCRICAO || !mb_check_encoding($descricao, 'UTF-8') || preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $descricao) === 1) {
            throw new \InvalidArgumentException('Descrição de classe inválida');
        }
        $aceita = $meta['aceita_filhos'] ?? true;
        $filhas = [];
        if ($aceita) {
            foreach ($meta['filhas'] ?? [] as $f) {
                if ($f !== $propria && $this->porNome($f) === null) {
                    throw new \InvalidArgumentException("Classe filha que não existe: {$f}");
                }
                $filhas[$f] = true;
            }
        }
        $filhas = array_keys($filhas);
        sort($filhas);

        return [$descricao, $aceita, array_map('strval', $filhas)];
    }

    /** @return list<string> chaves do JSON de dados com valor não nulo */
    private function chavesComValor(string $json): array
    {
        $dados = json_decode($json, true);
        if (!is_array($dados)) {
            return [];
        }

        return array_map('strval', array_keys(array_filter($dados, static fn (mixed $v): bool => $v !== null)));
    }

    /** @param array<string, mixed> $linha */
    private function hidratar(array $linha): Classe
    {
        /** @var list<Propriedade> $props */
        $props = json_decode((string) $linha['propriedades'], true, 512, JSON_THROW_ON_ERROR);
        $filhas = isset($linha['filhas']) && $linha['filhas'] !== '' ? json_decode((string) $linha['filhas'], true, 512, JSON_THROW_ON_ERROR) : [];

        return new Classe(
            (int) $linha['id'],
            (string) $linha['nome'],
            $props,
            (string) ($linha['descricao'] ?? ''),
            (int) ($linha['aceita_filhos'] ?? 1) === 1,
            is_array($filhas) ? array_values(array_map('strval', $filhas)) : [],
            (int) ($linha['versao'] ?? 1),
            (int) ($linha['fixa'] ?? 0) === 1,
        );
    }
}
