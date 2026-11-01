<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

use Doctrine\DBAL\Connection;

/** Domínios de cada site. Cada site tem exatamente um `principal` (o endereço canônico). */
final class DominioRepositorio
{
    public function __construct(private readonly Connection $conexao)
    {
    }

    public function adicionar(int $siteId, string $host, string $modo = Dominio::SERVIR): Dominio
    {
        $host = strtolower(trim($host));
        if (!Dominio::hostValido($host)) {
            throw new \InvalidArgumentException("Domínio inválido: {$host}");
        }
        if (!in_array($modo, Dominio::MODOS, true)) {
            throw new \InvalidArgumentException("Modo de domínio inválido: {$modo}");
        }
        if ($this->conexao->fetchOne('SELECT 1 FROM site WHERE id = ?', [$siteId]) === false) {
            throw new \InvalidArgumentException("Site {$siteId} não existe");
        }
        if ($this->porHost($host) !== null) {
            throw new \InvalidArgumentException("O domínio {$host} já está configurado");
        }
        if ($modo === Dominio::PRINCIPAL && $this->principalDe($siteId) !== null) {
            throw new \InvalidArgumentException('O site já tem um domínio principal; use definirPrincipal para trocar');
        }
        $this->conexao->insert('dominio', ['host' => $host, 'site_id' => $siteId, 'modo' => $modo]);

        return new Dominio($host, $siteId, $modo);
    }

    /** Troca o domínio principal do site; o anterior passa para `$antigoComo` (padrão: redireciona para o novo). */
    public function definirPrincipal(int $siteId, string $host, string $antigoComo = Dominio::REDIRECIONAR): void
    {
        if (!in_array($antigoComo, [Dominio::SERVIR, Dominio::REDIRECIONAR], true)) {
            throw new \InvalidArgumentException("O domínio antigo só pode passar a servir ou redirecionar: {$antigoComo}");
        }
        $this->conexao->transactional(function () use ($siteId, $host, $antigoComo): void {
            $novo = $this->porHost(strtolower($host));
            if ($novo === null || $novo->siteId !== $siteId) {
                throw new \InvalidArgumentException("O domínio {$host} não pertence ao site {$siteId}");
            }
            $this->conexao->update('dominio', ['modo' => $antigoComo], ['site_id' => $siteId, 'modo' => Dominio::PRINCIPAL]);
            $this->conexao->update('dominio', ['modo' => Dominio::PRINCIPAL], ['host' => $novo->host]);
        });
    }

    public function remover(string $host): void
    {
        $d = $this->porHost(strtolower($host));
        if ($d === null) {
            throw new \InvalidArgumentException("O domínio {$host} não está configurado");
        }
        if ($d->modo === Dominio::PRINCIPAL) {
            throw new \InvalidArgumentException('O domínio principal não pode ser removido; defina outro como principal antes');
        }
        $this->conexao->delete('dominio', ['host' => $d->host]);
    }

    public function porHost(string $host): ?Dominio
    {
        $l = $this->conexao->fetchAssociative('SELECT host, site_id, modo FROM dominio WHERE host = ?', [strtolower($host)]);

        return $l === false ? null : new Dominio((string) $l['host'], (int) $l['site_id'], (string) $l['modo']);
    }

    public function principalDe(int $siteId): ?Dominio
    {
        $l = $this->conexao->fetchAssociative('SELECT host, site_id, modo FROM dominio WHERE site_id = ? AND modo = ? ORDER BY host LIMIT 1', [$siteId, Dominio::PRINCIPAL]);

        return $l === false ? null : new Dominio((string) $l['host'], (int) $l['site_id'], (string) $l['modo']);
    }

    /** @return list<Dominio> */
    public function doSite(int $siteId): array
    {
        return array_map(
            static fn (array $l): Dominio => new Dominio((string) $l['host'], (int) $l['site_id'], (string) $l['modo']),
            $this->conexao->fetchAllAssociative('SELECT host, site_id, modo FROM dominio WHERE site_id = ? ORDER BY host', [$siteId]),
        );
    }
}
