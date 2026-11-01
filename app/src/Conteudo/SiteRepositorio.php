<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

use Doctrine\DBAL\Connection;

final class SiteRepositorio
{
    public function __construct(private readonly Connection $conexao)
    {
    }

    public function criar(string $nome, string $host, string $pele = 'basica'): Site
    {
        $this->conexao->insert('site', ['nome' => $nome, 'pele' => $pele]);
        $id = (int) $this->conexao->lastInsertId();
        $this->conexao->insert('dominio', ['host' => strtolower($host), 'site_id' => $id]);

        return new Site($id, $nome, null, $pele);
    }

    public function definirRaiz(int $siteId, int $objetoId): void
    {
        $this->conexao->update('site', ['objeto_raiz_id' => $objetoId], ['id' => $siteId]);
    }

    public function porHost(string $host): ?Site
    {
        $linha = $this->conexao->fetchAssociative(
            'SELECT s.* FROM site s JOIN dominio d ON d.site_id = s.id WHERE d.host = ?',
            [strtolower($host)],
        );

        return $linha === false ? null : $this->hidratar($linha);
    }

    /** @return list<Site> */
    public function todos(): array
    {
        return array_map($this->hidratar(...), $this->conexao->fetchAllAssociative('SELECT * FROM site ORDER BY id'));
    }

    /** @param array<string, mixed> $l */
    private function hidratar(array $l): Site
    {
        return new Site((int) $l['id'], (string) $l['nome'], $l['objeto_raiz_id'] === null ? null : (int) $l['objeto_raiz_id'], (string) $l['pele']);
    }
}
