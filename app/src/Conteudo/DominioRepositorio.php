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

    /** Muda um domínio que não é o principal entre `servir` (mesmo conteúdo, com canônico) e `redirecionar` (301 para o principal). */
    public function definirModo(string $host, string $modo): void
    {
        if (!in_array($modo, [Dominio::SERVIR, Dominio::REDIRECIONAR], true)) {
            throw new \InvalidArgumentException("Modo inválido para um domínio que não é o principal: {$modo}");
        }
        $d = $this->porHost(strtolower($host));
        if ($d === null) {
            throw new \InvalidArgumentException("O domínio {$host} não está configurado");
        }
        if ($d->modo === Dominio::PRINCIPAL) {
            throw new \InvalidArgumentException('O domínio principal não muda de modo; defina outro como principal antes');
        }
        $this->conexao->update('dominio', ['modo' => $modo], ['host' => $d->host]);
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

    /** @param array<string, mixed> $l */
    private static function dePadrao(array $l): Dominio
    {
        return new Dominio((string) $l['host'], (int) $l['site_id'], (string) $l['modo'], isset($l['inicio_objeto_id']) ? (int) $l['inicio_objeto_id'] : null);
    }

    /**
     * Define (ou limpa, com null) o conteúdo que abre em `/` neste domínio. O conteúdo tem de ser do mesmo site e não pode estar
     * apagado; se depois ele sair do ar, o domínio volta a abrir a página inicial do site.
     */
    public function definirInicio(string $host, ?int $objetoId): void
    {
        $d = $this->porHost(strtolower($host));
        if ($d === null) {
            throw new \InvalidArgumentException("O domínio {$host} não está configurado");
        }
        if ($objetoId !== null) {
            $l = $this->conexao->fetchAssociative('SELECT site_id, status FROM objeto WHERE id = ?', [$objetoId]);
            if ($l === false || (int) $l['site_id'] !== $d->siteId) {
                throw new \InvalidArgumentException('Esse conteúdo não existe neste site');
            }
            if ($l['status'] === 'apagado') {
                throw new \InvalidArgumentException('Esse conteúdo está na lixeira');
            }
        }
        $this->conexao->update('dominio', ['inicio_objeto_id' => $objetoId], ['host' => $d->host]);
    }

    public function porHost(string $host): ?Dominio
    {
        $l = $this->conexao->fetchAssociative('SELECT host, site_id, modo, inicio_objeto_id FROM dominio WHERE host = ?', [strtolower($host)]);

        return $l === false ? null : self::dePadrao($l);
    }

    public function principalDe(int $siteId): ?Dominio
    {
        $l = $this->conexao->fetchAssociative('SELECT host, site_id, modo, inicio_objeto_id FROM dominio WHERE site_id = ? AND modo = ? ORDER BY host LIMIT 1', [$siteId, Dominio::PRINCIPAL]);

        return $l === false ? null : self::dePadrao($l);
    }

    /** @return list<Dominio> */
    public function doSite(int $siteId): array
    {
        return array_map(
            static fn (array $l): Dominio => self::dePadrao($l),
            $this->conexao->fetchAllAssociative('SELECT host, site_id, modo, inicio_objeto_id FROM dominio WHERE site_id = ? ORDER BY host', [$siteId]),
        );
    }
}
