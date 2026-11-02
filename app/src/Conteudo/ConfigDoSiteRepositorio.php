<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

use Doctrine\DBAL\Connection;
use Pbl\Arquivos\ArquivoRepositorio;

/**
 * Guarda e lê as configurações do site (tabela `site_config`, ADR 0017). Só chaves do catálogo (`ConfigDoSite`); valor
 * validado pelo tipo; arquivos (imagem padrão, ícone) precisam ser imagens deste mesmo site. A "versão" (hash do que está
 * gravado) serve ao controle otimista de edição. A leitura é guardada em memória e descartada a cada gravação.
 */
final class ConfigDoSiteRepositorio
{
    /** @var array<int, array<string, mixed>> */
    private array $memoria = [];

    public function __construct(private readonly Connection $conexao, private readonly ?ArquivoRepositorio $arquivos = null)
    {
    }

    /**
     * Só o que foi gravado (chave => valor), sem padrões.
     *
     * @return array<string, mixed>
     */
    public function gravadas(int $siteId): array
    {
        if (isset($this->memoria[$siteId])) {
            return $this->memoria[$siteId];
        }
        $saida = [];
        foreach ($this->conexao->fetchAllAssociative('SELECT chave, valor FROM site_config WHERE site_id = ? ORDER BY chave', [$siteId]) as $l) {
            $chave = (string) $l['chave'];
            if (!ConfigDoSite::existe($chave)) {
                continue; // chave removida do catálogo numa versão futura: ignora
            }
            $valor = json_decode((string) $l['valor'], true);
            if ($valor !== null) {
                $saida[$chave] = $valor;
            }
        }

        return $this->memoria[$siteId] = $saida;
    }

    /**
     * Todas as chaves do catálogo com o valor gravado ou o padrão.
     *
     * @return array<string, mixed>
     */
    public function efetivas(int $siteId): array
    {
        $gravadas = $this->gravadas($siteId);
        $saida = [];
        foreach (ConfigDoSite::chaves() as $chave) {
            $saida[$chave] = $gravadas[$chave] ?? ConfigDoSite::padrao($chave);
        }

        return $saida;
    }

    public function valor(int $siteId, string $chave): mixed
    {
        if (!ConfigDoSite::existe($chave)) {
            throw new \InvalidArgumentException("Configuração desconhecida: {$chave}");
        }

        return $this->gravadas($siteId)[$chave] ?? ConfigDoSite::padrao($chave);
    }

    /** O valor como o molde o lê; chave fora do catálogo é vazio (o parser já a recusa antes). */
    public function paraMolde(int $siteId, string $chave): string|int
    {
        return ConfigDoSite::existe($chave) ? ConfigDoSite::paraMolde($this->valor($siteId, $chave)) : '';
    }

    /** Impressão digital do que está gravado, para a edição otimista. */
    public function versao(int $siteId): string
    {
        return hash('sha256', json_encode($this->conexao->fetchAllAssociative('SELECT chave, valor, atualizado_em FROM site_config WHERE site_id = ? ORDER BY chave', [$siteId]), JSON_THROW_ON_ERROR));
    }

    /**
     * Grava as chaves de `$brutos` (as ausentes ficam como estão; valor vazio apaga e volta ao padrão). Valida tudo antes
     * de gravar qualquer coisa.
     *
     * @param array<string, mixed> $brutos chave => valor vindo da tela ou do código
     * @param string|null $versaoEsperada a de `versao()` quando a tela abriu; null: sem conferência (instalador, código)
     * @return list<string> chaves cujo valor mudou (para a auditoria: nunca os valores)
     * @throws ErroDeConfig
     * @throws ConflitoDeConfig
     */
    public function definir(int $siteId, array $brutos, ?string $versaoEsperada = null): array
    {
        $erros = [];
        $novos = [];
        foreach ($brutos as $chave => $bruto) {
            $chave = (string) $chave;
            if (!ConfigDoSite::existe($chave)) {
                $erros[$chave] = 'Configuração desconhecida.';
                continue;
            }
            try {
                $valor = ConfigDoSite::normalizar($chave, $bruto);
                if ($valor !== null && ConfigDoSite::catalogo()[$chave]['tipo'] === ConfigDoSite::ARQUIVO) {
                    $this->exigirImagem($siteId, (int) $valor, ConfigDoSite::catalogo()[$chave]['rotulo']);
                }
                $novos[$chave] = $valor;
            } catch (\InvalidArgumentException $e) {
                $erros[$chave] = rtrim($e->getMessage(), '.') . '.';
            }
        }
        if ($erros !== []) {
            throw new ErroDeConfig($erros);
        }

        return $this->conexao->transactional(function () use ($siteId, $novos, $versaoEsperada): array {
            if ($versaoEsperada !== null && !hash_equals($this->versao($siteId), $versaoEsperada)) {
                throw new ConflitoDeConfig();
            }
            unset($this->memoria[$siteId]);
            $antes = $this->gravadas($siteId);
            $mudou = [];
            foreach ($novos as $chave => $valor) {
                if ($valor === ($antes[$chave] ?? null)) {
                    continue;
                }
                $mudou[] = $chave;
                if ($valor === null) {
                    $this->conexao->delete('site_config', ['site_id' => $siteId, 'chave' => $chave]);
                    continue;
                }
                $json = json_encode($valor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $this->conexao->executeStatement(
                    'INSERT INTO site_config (site_id, chave, valor, atualizado_em) VALUES (?, ?, ?, ?)
                     ON CONFLICT (site_id, chave) DO UPDATE SET valor = excluded.valor, atualizado_em = excluded.atualizado_em',
                    [$siteId, $chave, $json, gmdate('c')],
                );
            }
            unset($this->memoria[$siteId]);

            return $mudou;
        });
    }

    private function exigirImagem(int $siteId, int $arquivoId, string $rotulo): void
    {
        $arquivo = $this->arquivos?->porId($arquivoId);
        if ($arquivo === null || $arquivo->siteId !== $siteId || !$arquivo->ehImagem()) {
            throw new \InvalidArgumentException("{$rotulo}: o arquivo {$arquivoId} não existe neste site ou não é uma imagem.");
        }
    }
}
