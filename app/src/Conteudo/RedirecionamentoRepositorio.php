<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

use Doctrine\DBAL\Connection;

/**
 * Regras manuais de redirecionamento por site, para endereços antigos que não correspondem a nenhum conteúdo atual (por
 * exemplo, endereços de sistemas que deixaram de existir). Valem **depois** da página publicada e do histórico de URLs.
 * A origem é um caminho; com `prefixo`, vale para tudo abaixo dele (`/antigo/x` → `<destino>/x`). O destino é um caminho do
 * próprio site ou um endereço `https://`: nunca outro esquema.
 */
final class RedirecionamentoRepositorio
{
    public const MAX_PREFIXOS = 200;
    public const MAX_REGRAS = 5000;

    public function __construct(private readonly Connection $conexao)
    {
    }

    public function adicionar(int $siteId, string $origem, string $destino, int $codigo = 301, bool $prefixo = false): int
    {
        $origem = self::normalizar($origem);
        if (!self::origemValida($origem)) {
            throw new \InvalidArgumentException("Origem inválida: {$origem}");
        }
        if (!self::destinoValido($destino)) {
            throw new \InvalidArgumentException("Destino inválido (use um caminho do site ou https://): {$destino}");
        }
        if (!in_array($codigo, [301, 302], true)) {
            throw new \InvalidArgumentException("Código inválido (301 ou 302): {$codigo}");
        }
        if ($this->conexao->fetchOne('SELECT 1 FROM site WHERE id = ?', [$siteId]) === false) {
            throw new \InvalidArgumentException("Site {$siteId} não existe");
        }
        if ((int) $this->conexao->fetchOne('SELECT COUNT(*) FROM redirecionamento WHERE site_id = ?', [$siteId]) >= self::MAX_REGRAS) {
            throw new \InvalidArgumentException('Limite de regras de redirecionamento do site atingido');
        }
        if ($prefixo && (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM redirecionamento WHERE site_id = ? AND prefixo = 1', [$siteId]) >= self::MAX_PREFIXOS) {
            throw new \InvalidArgumentException('Limite de regras por prefixo do site atingido');
        }
        if ($this->conexao->fetchOne('SELECT 1 FROM redirecionamento WHERE site_id = ? AND origem = ? AND prefixo = ?', [$siteId, $origem, $prefixo ? 1 : 0]) !== false) {
            throw new \InvalidArgumentException("Já existe uma regra para {$origem}");
        }
        $this->conexao->insert('redirecionamento', [
            'site_id' => $siteId, 'origem' => $origem, 'destino' => $destino, 'codigo' => $codigo,
            'prefixo' => $prefixo ? 1 : 0, 'criado_em' => gmdate('c'),
        ]);

        return (int) $this->conexao->lastInsertId();
    }

    public function remover(int $siteId, int $id): void
    {
        if ($this->conexao->delete('redirecionamento', ['id' => $id, 'site_id' => $siteId]) === 0) {
            throw new \InvalidArgumentException("Regra {$id} não existe neste site");
        }
    }

    /** @return list<array{id: int, origem: string, destino: string, codigo: int, prefixo: bool}> */
    public function doSite(int $siteId): array
    {
        return array_map(static fn (array $l): array => [
            'id' => (int) $l['id'], 'origem' => (string) $l['origem'], 'destino' => (string) $l['destino'],
            'codigo' => (int) $l['codigo'], 'prefixo' => (int) $l['prefixo'] === 1,
        ], $this->conexao->fetchAllAssociative('SELECT * FROM redirecionamento WHERE site_id = ? ORDER BY origem', [$siteId]));
    }

    /**
     * Para onde mandar o visitante que pediu `$caminho`, ou null. A regra exata vence a de prefixo; entre prefixos, o mais longo.
     *
     * @return array{destino: string, codigo: int}|null
     */
    public function resolver(int $siteId, string $caminho): ?array
    {
        $caminho = self::normalizar($caminho);
        $exata = $this->conexao->fetchAssociative('SELECT destino, codigo FROM redirecionamento WHERE site_id = ? AND origem = ? AND prefixo = 0', [$siteId, $caminho]);
        if ($exata !== false) {
            return ['destino' => (string) $exata['destino'], 'codigo' => (int) $exata['codigo']];
        }
        $melhor = null;
        foreach ($this->conexao->fetchAllAssociative('SELECT origem, destino, codigo FROM redirecionamento WHERE site_id = ? AND prefixo = 1', [$siteId]) as $r) {
            $origem = (string) $r['origem'];
            $dentro = $origem === '/' || $caminho === $origem || str_starts_with($caminho, $origem . '/');
            if ($dentro && ($melhor === null || strlen($origem) > strlen($melhor['origem']))) {
                $melhor = ['origem' => $origem, 'destino' => (string) $r['destino'], 'codigo' => (int) $r['codigo']];
            }
        }
        if ($melhor === null) {
            return null;
        }
        $resto = $melhor['origem'] === '/' ? $caminho : substr($caminho, strlen($melhor['origem']));

        return ['destino' => rtrim($melhor['destino'], '/') . ($resto === '/' ? '' : $resto), 'codigo' => $melhor['codigo']];
    }

    /** Caminho com `/` na frente e sem `/` no fim (exceto a raiz). */
    public static function normalizar(string $caminho): string
    {
        $caminho = '/' . trim($caminho, '/');

        return $caminho;
    }

    public static function origemValida(string $origem): bool
    {
        return strlen($origem) <= 300 && $origem[0] === '/' && !str_contains($origem, '..')
            && !str_contains($origem, '//') && preg_match('/^[A-Za-z0-9._~\-\/%+]++$/D', $origem) === 1;
    }

    public static function destinoValido(string $destino): bool
    {
        if ($destino === '' || strlen($destino) > 500 || preg_match('/^[A-Za-z0-9._~\-\/%+?=&#:@!$,;]++$/D', $destino) !== 1) {
            return false;
        }
        if ($destino[0] === '/') {
            return !str_starts_with($destino, '//') && !str_contains($destino, '..');
        }
        if (!str_starts_with($destino, 'https://')) {
            return false;
        }
        $host = parse_url($destino, PHP_URL_HOST);

        return is_string($host) && Dominio::hostValido(strtolower($host)) && parse_url($destino, PHP_URL_USER) === null;
    }
}
