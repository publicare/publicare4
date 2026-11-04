<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Pbl\Busca\Texto;
use Pbl\Busca\Visibilidade;

/**
 * Tags dos objetos (fase 5r, ADR 0024). A tag é por site e se identifica pelo slug (sem acento, minúsculo, com hífens):
 * "Eventos 2026" e "eventos-2026" são a mesma tag. Criada sob demanda ao salvar o objeto; a que fica sem objeto é removida.
 */
final class TagRepositorio
{
    public const MAX_POR_OBJETO = 20;
    public const MAX_NOME = 40;

    public function __construct(private readonly Connection $conexao)
    {
    }

    public static function slug(string $nome): string
    {
        $s = Texto::normalizar($nome);
        $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);

        return trim($s, '-');
    }

    /**
     * Lista digitada (vírgula ou quebra de linha) -> nomes limpos, sem repetição por slug, na ordem digitada.
     *
     * @return list<string>
     */
    public static function interpretar(string|array $entrada): array
    {
        $partes = is_array($entrada) ? $entrada : (preg_split('/[,\n;]+/u', $entrada) ?: []);
        $nomes = [];
        foreach ($partes as $p) {
            $nome = trim((string) preg_replace('/\s+/u', ' ', (string) $p));
            if ($nome === '') {
                continue;
            }
            $slug = self::slug($nome);
            if ($slug === '') {
                continue;
            }
            $nomes[$slug] ??= $nome;
        }

        return array_values($nomes);
    }

    /**
     * Valida a lista já interpretada. Devolve a mensagem de erro, ou null se estiver boa.
     *
     * @param list<string> $nomes
     */
    public static function validar(array $nomes): ?string
    {
        if (count($nomes) > self::MAX_POR_OBJETO) {
            return 'Use no máximo ' . self::MAX_POR_OBJETO . ' tags por objeto.';
        }
        foreach ($nomes as $n) {
            if (mb_strlen($n) > self::MAX_NOME) {
                return 'Cada tag pode ter no máximo ' . self::MAX_NOME . ' caracteres: "' . mb_substr($n, 0, 20) . '…" é longa demais.';
            }
        }

        return null;
    }

    /** @return list<string> nomes, na ordem alfabética */
    public function nomesDoObjeto(int $objetoId): array
    {
        return array_column($this->porObjeto($objetoId), 'nome');
    }

    /** @return list<array{id:int, nome:string, slug:string}> */
    public function porObjeto(int $objetoId): array
    {
        return $this->porObjetos([$objetoId])[$objetoId] ?? [];
    }

    /**
     * @param list<int> $objetoIds
     * @return array<int, list<array{id:int, nome:string, slug:string}>>
     */
    public function porObjetos(array $objetoIds): array
    {
        if ($objetoIds === []) {
            return [];
        }
        $linhas = $this->conexao->executeQuery(
            'SELECT ot.objeto_id, t.id, t.nome, t.slug FROM objeto_tag ot JOIN tag t ON t.id = ot.tag_id WHERE ot.objeto_id IN (?) ORDER BY t.slug',
            [array_values($objetoIds)],
            [ArrayParameterType::INTEGER],
        )->fetchAllAssociative();
        $saida = [];
        foreach ($linhas as $l) {
            $saida[(int) $l['objeto_id']][] = ['id' => (int) $l['id'], 'nome' => (string) $l['nome'], 'slug' => (string) $l['slug']];
        }

        return $saida;
    }

    /** @return array{id:int, nome:string, slug:string}|null */
    public function porSlug(int $siteId, string $slug): ?array
    {
        $l = $this->conexao->fetchAssociative('SELECT id, nome, slug FROM tag WHERE site_id = ? AND slug = ?', [$siteId, self::slug($slug)]);

        return $l === false ? null : ['id' => (int) $l['id'], 'nome' => (string) $l['nome'], 'slug' => (string) $l['slug']];
    }

    /**
     * Substitui as tags do objeto. Devolve true se o conjunto mudou. Chamar dentro da transação do objeto.
     *
     * @param list<string> $nomes já interpretados e validados
     */
    public function definir(int $siteId, int $objetoId, array $nomes): bool
    {
        $atuais = $this->porObjeto($objetoId);
        $queremSlugs = [];
        foreach ($nomes as $n) {
            $queremSlugs[self::slug($n)] = $n;
        }
        $atuaisPorSlug = [];
        foreach ($atuais as $a) {
            $atuaisPorSlug[$a['slug']] = $a['id'];
        }
        if (count($queremSlugs) === count($atuaisPorSlug) && array_diff_key($queremSlugs, $atuaisPorSlug) === []) {
            return false;
        }
        foreach ($atuaisPorSlug as $slug => $tagId) {
            if (!isset($queremSlugs[$slug])) {
                $this->conexao->delete('objeto_tag', ['objeto_id' => $objetoId, 'tag_id' => $tagId]);
            }
        }
        foreach ($queremSlugs as $slug => $nome) {
            if (isset($atuaisPorSlug[$slug])) {
                continue;
            }
            $this->conexao->insert('objeto_tag', ['objeto_id' => $objetoId, 'tag_id' => $this->obterOuCriar($siteId, $nome, (string) $slug)]);
        }
        $this->removerOrfas($siteId);

        return true;
    }

    /** Apaga os vínculos do objeto (exclusão definitiva) e as tags que sobrarem sem uso. */
    public function esquecerObjeto(int $siteId, int $objetoId): void
    {
        $this->conexao->delete('objeto_tag', ['objeto_id' => $objetoId]);
        $this->removerOrfas($siteId);
    }

    /**
     * Nuvem: tags com a contagem de objetos visíveis (publicado e na vigência), mais usadas primeiro, depois por nome.
     *
     * @return list<array{nome:string, slug:string, total:int}>
     */
    public function nuvem(int $siteId, int $limite = 30): array
    {
        $valores = ['site' => $siteId];
        $tipos = ['site' => \Doctrine\DBAL\ParameterType::INTEGER];
        $visivel = Visibilidade::condicao('o', $valores, $tipos);
        $sql = 'SELECT t.nome, t.slug, COUNT(*) AS total FROM tag t JOIN objeto_tag ot ON ot.tag_id = t.id JOIN objeto o ON o.id = ot.objeto_id'
            . ' WHERE t.site_id = :site AND ' . $visivel . ' GROUP BY t.id, t.nome, t.slug ORDER BY total DESC, t.slug LIMIT ' . max(1, min(200, $limite));
        $linhas = $this->conexao->executeQuery($sql, $valores, $tipos)->fetchAllAssociative();

        return array_map(static fn (array $l): array => ['nome' => (string) $l['nome'], 'slug' => (string) $l['slug'], 'total' => (int) $l['total']], $linhas);
    }

    private function obterOuCriar(int $siteId, string $nome, string $slug): int
    {
        $id = $this->conexao->fetchOne('SELECT id FROM tag WHERE site_id = ? AND slug = ?', [$siteId, $slug]);
        if ($id !== false) {
            return (int) $id;
        }
        $this->conexao->insert('tag', ['site_id' => $siteId, 'nome' => $nome, 'slug' => $slug, 'criado_em' => gmdate('Y-m-d H:i:s')]);

        return (int) $this->conexao->lastInsertId();
    }

    private function removerOrfas(int $siteId): void
    {
        $this->conexao->executeStatement('DELETE FROM tag WHERE site_id = ? AND id NOT IN (SELECT tag_id FROM objeto_tag)', [$siteId]);
    }
}
