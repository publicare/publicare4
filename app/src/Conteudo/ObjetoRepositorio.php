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
