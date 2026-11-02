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
        $host = strtolower($host);
        if (!Dominio::hostValido($host)) {
            throw new \InvalidArgumentException("Domínio inválido: {$host}");
        }
        $this->conexao->insert('dominio', ['host' => $host, 'site_id' => $id, 'modo' => Dominio::PRINCIPAL]);

        return new Site($id, $nome, null, $pele);
    }

    public function definirRaiz(int $siteId, int $objetoId): void
    {
        $this->conexao->update('site', ['objeto_raiz_id' => $objetoId], ['id' => $siteId]);
    }

    /** Troca a pele ativa do site. Quem chama confere que a pele existe em `dados/peles/`. */
    public function definirPele(int $siteId, string $pele): void
    {
        if ($this->conexao->update('site', ['pele' => $pele], ['id' => $siteId]) === 0 && $this->porId($siteId) === null) {
            throw new \InvalidArgumentException("Site {$siteId} não existe");
        }
    }

    /** Liga ou desliga a sugestão de grafia parecida na busca sem resultado (nível C, ADR 0010; desligada por padrão). */
    public function definirBuscaTolerancia(int $siteId, bool $ligado): void
    {
        if ($this->conexao->update('site', ['busca_tolerancia' => $ligado ? 1 : 0], ['id' => $siteId]) === 0 && $this->porId($siteId) === null) {
            throw new \InvalidArgumentException("Site {$siteId} não existe");
        }
    }

    public function porId(int $id): ?Site
    {
        $linha = $this->conexao->fetchAssociative('SELECT * FROM site WHERE id = ?', [$id]);

        return $linha === false ? null : $this->hidratar($linha);
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
        return new Site(
            (int) $l['id'],
            (string) $l['nome'],
            $l['objeto_raiz_id'] === null ? null : (int) $l['objeto_raiz_id'],
            (string) $l['pele'],
            ((int) ($l['busca_tolerancia'] ?? 0)) === 1,
        );
    }
}
