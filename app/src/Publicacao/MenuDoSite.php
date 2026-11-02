<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Doctrine\DBAL\Connection;
use Pbl\Acesso\Auditoria;
use Pbl\Caminhos;
use Pbl\Conteudo\RegrasDeLink;
use Pbl\Conteudo\Site;

/**
 * A árvore do menu de um site (ADR 0016): montada com **uma** consulta, guardada em memória e em um snapshot em
 * `dados/cache/menu/<site>.json`, com impressão digital (hash). As páginas só leem o snapshot; o banco não é consultado
 * por página. Quando uma edição muda o hash, o site inteiro fica marcado como pendente de republicação.
 *
 * Item: `id`, `rotulo`, `url`, `externo`, `nova_aba`, `filhos` (lista de itens). Profundidade e itens por nível têm teto;
 * o que passa do teto é cortado e contado em `cortes` (o painel avisa).
 */
final class MenuDoSite
{
    public const PROFUNDIDADE_PADRAO = 3;
    public const PROFUNDIDADE_MAXIMA = 5;
    public const ITENS_POR_NIVEL = 50;

    /** @var array<int, array{hash: string, profundidade: int, cortes: int, arvore: list<array<string, mixed>>}> */
    private array $memoria = [];

    /** Quantas consultas ao banco esta instância fez (para os testes de desempenho). */
    public int $consultas = 0;

    private readonly int $profundidade;

    public function __construct(
        private readonly Connection $conexao,
        private readonly Caminhos $caminhos,
        private readonly Auditoria $auditoria,
        int $profundidade = self::PROFUNDIDADE_PADRAO,
    ) {
        $this->profundidade = max(1, min(self::PROFUNDIDADE_MAXIMA, $profundidade));
    }

    public function profundidade(): int
    {
        return $this->profundidade;
    }

    /** @return array{hash: string, profundidade: int, cortes: int, arvore: list<array<string, mixed>>} */
    public function snapshot(Site $site): array
    {
        if (isset($this->memoria[$site->id])) {
            return $this->memoria[$site->id];
        }
        $lido = $this->ler($site->id);
        if ($lido !== null && $lido['profundidade'] === $this->profundidade) {
            return $this->memoria[$site->id] = $lido;
        }

        return $this->reconstruir($site);
    }

    /** @return list<array<string, mixed>> */
    public function arvore(Site $site): array
    {
        return $this->snapshot($site)['arvore'];
    }

    /**
     * Monta de novo a partir do banco (uma consulta), grava o snapshot e devolve-o. Não mexe no pendente.
     *
     * @return array{hash: string, profundidade: int, cortes: int, arvore: list<array<string, mixed>>}
     */
    public function reconstruir(Site $site): array
    {
        $snap = $this->construir($site);
        $this->gravar($site->id, $snap);

        return $this->memoria[$site->id] = $snap;
    }

    /**
     * Recalcula a árvore e compara com o snapshot guardado. Mudou: grava o novo snapshot, marca o site como pendente de
     * republicação e registra em auditoria. Devolve true se mudou.
     */
    public function verificar(Site $site, string $ator = 'sistema', ?int $usuarioId = null): bool
    {
        $antigo = $this->ler($site->id);
        $novo = $this->construir($site);
        $this->memoria[$site->id] = $novo;
        if ($antigo !== null && $antigo['hash'] === $novo['hash'] && $antigo['profundidade'] === $novo['profundidade']) {
            return false;
        }
        $this->gravar($site->id, $novo);
        $this->marcarPendente($site->id);
        $this->auditoria->registrar('menu.invalidado', $ator, $usuarioId, null, ['site' => $site->id, 'hash' => substr($novo['hash'], 0, 12)]);

        return true;
    }

    public function pendente(Site $site): bool
    {
        return is_file($this->arquivo($site->id) . '.pendente');
    }

    public function marcarPendente(int $siteId): void
    {
        $this->garantirPasta();
        @file_put_contents($this->arquivo($siteId) . '.pendente', gmdate('c'));
    }

    public function limparPendente(Site $site): void
    {
        @unlink($this->arquivo($site->id) . '.pendente');
    }

    /** @return array{hash: string, profundidade: int, cortes: int, arvore: list<array<string, mixed>>} */
    private function construir(Site $site): array
    {
        if ($site->objetoRaizId === null) {
            return ['hash' => hash('sha256', '[]'), 'profundidade' => $this->profundidade, 'cortes' => 0, 'arvore' => []];
        }
        $agora = gmdate('c');
        $this->consultas++;
        $linhas = $this->conexao->fetchAllAssociative(
            "SELECT o.id, o.pai_id, o.titulo, o.rotulo_menu, o.url_amigavel, o.dados, c.nome AS classe,
                    d.url_amigavel AS destino_url
               FROM objeto o
               JOIN classe c ON c.id = o.classe_id
               LEFT JOIN objeto d ON c.nome = 'link' AND d.site_id = o.site_id AND d.status = 'publicado'
                    AND d.id = CAST(json_extract(o.dados, '\$.destino_interno') AS INTEGER)
              WHERE o.site_id = ? AND o.no_menu = 1 AND o.status = 'publicado'
                AND (o.data_publicacao IS NULL OR o.data_publicacao <= ?)
                AND (o.data_validade IS NULL OR o.data_validade >= ?)
              ORDER BY o.peso, o.titulo, o.id",
            [$site->id, $agora, $agora],
        );
        $porPai = [];
        foreach ($linhas as $l) {
            $porPai[(int) ($l['pai_id'] ?? 0)][] = $l;
        }
        $cortes = 0;
        $arvore = $this->nivel($porPai, $site->objetoRaizId, 1, $cortes);

        return ['hash' => hash('sha256', json_encode($arvore, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 'profundidade' => $this->profundidade, 'cortes' => $cortes, 'arvore' => $arvore];
    }

    /**
     * @param array<int, list<array<string, mixed>>> $porPai
     * @return list<array<string, mixed>>
     */
    private function nivel(array $porPai, int $paiId, int $nivel, int &$cortes): array
    {
        $filhos = $porPai[$paiId] ?? [];
        if ($nivel > $this->profundidade) {
            $cortes += count($filhos);

            return [];
        }
        $itens = [];
        foreach ($filhos as $l) {
            $item = $this->item($l);
            if ($item === null) {
                continue;
            }
            if (count($itens) >= self::ITENS_POR_NIVEL) {
                $cortes++;
                continue;
            }
            $item['filhos'] = $this->nivel($porPai, (int) $l['id'], $nivel + 1, $cortes);
            $itens[] = $item;
        }

        return $itens;
    }

    /**
     * @param array<string, mixed> $l
     * @return array<string, mixed>|null null: link sem destino utilizável (não aparece no menu)
     */
    private function item(array $l): ?array
    {
        $rotulo = trim((string) ($l['rotulo_menu'] ?? ''));
        $item = ['id' => (int) $l['id'], 'rotulo' => $rotulo === '' ? (string) $l['titulo'] : $rotulo, 'url' => '/' . $l['url_amigavel'], 'externo' => false, 'nova_aba' => false];
        if ($l['classe'] !== 'link') {
            return $item;
        }
        $dados = json_decode((string) $l['dados'], true);
        $dados = is_array($dados) ? $dados : [];
        $item['nova_aba'] = !empty($dados['nova_aba']);
        $externo = $dados['destino_externo'] ?? null;
        if (is_string($externo) && $externo !== '') {
            if (!RegrasDeLink::externoValido($externo)) {
                return null;
            }
            $item['url'] = $externo;
            $item['externo'] = true;

            return $item;
        }
        if (!is_string($l['destino_url'] ?? null)) {
            return null;
        }
        $item['url'] = '/' . $l['destino_url'];

        return $item;
    }

    /** @return array{hash: string, profundidade: int, cortes: int, arvore: list<array<string, mixed>>}|null */
    private function ler(int $siteId): ?array
    {
        $arquivo = $this->arquivo($siteId);
        if (!is_file($arquivo)) {
            return null;
        }
        $json = json_decode((string) @file_get_contents($arquivo), true);
        if (!is_array($json) || !is_string($json['hash'] ?? null) || !is_array($json['arvore'] ?? null)) {
            return null;
        }

        /** @var list<array<string, mixed>> $arvore */
        $arvore = $json['arvore'];

        return ['hash' => $json['hash'], 'profundidade' => (int) ($json['profundidade'] ?? 0), 'cortes' => (int) ($json['cortes'] ?? 0), 'arvore' => $arvore];
    }

    /** @param array{hash: string, profundidade: int, cortes: int, arvore: list<array<string, mixed>>} $snap */
    private function gravar(int $siteId, array $snap): void
    {
        $this->garantirPasta();
        $arquivo = $this->arquivo($siteId);
        $tmp = $arquivo . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($snap, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) === false || !@rename($tmp, $arquivo)) {
            @unlink($tmp);
            throw new \RuntimeException("Não foi possível gravar {$arquivo}");
        }
    }

    private function garantirPasta(): void
    {
        $pasta = $this->caminhos->cache() . '/menu';
        if (!is_dir($pasta) && !@mkdir($pasta, 0775, true) && !is_dir($pasta)) {
            throw new \RuntimeException("Não foi possível criar {$pasta}");
        }
    }

    private function arquivo(int $siteId): string
    {
        return $this->caminhos->cache() . '/menu/' . $siteId . '.json';
    }
}
