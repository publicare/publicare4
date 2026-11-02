<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Doctrine\DBAL\Connection;
use Pbl\Acesso\Auditoria;
use Pbl\Caminhos;
use Pbl\Conteudo\ConfigDoSiteRepositorio;
use Pbl\Conteudo\RegrasDeLink;
use Pbl\Conteudo\Site;

/**
 * @phpstan-type Arvore list<array<string, mixed>>
 * @phpstan-type Snapshot array{hash: string, profundidade: int, cortes: int, arvore: Arvore, subarvores: array<int, array{url: string, arvore: Arvore}>}
 *
 * A árvore do menu de um site (ADR 0016): montada com **uma** consulta, guardada em memória e em um snapshot em
 * `dados/cache/menu/<site>.json`, com impressão digital (hash). As páginas só leem o snapshot; o banco não é consultado
 * por página. Quando uma edição muda o hash, o site inteiro fica marcado como pendente de republicação.
 *
 * Peles por objeto (ADR 0022): quem tem pele própria é a raiz de uma subárvore (`subarvores[id]`, com a sua `url`), e o menu
 * das páginas dela parte dali. Nas outras árvores, o item de uma raiz de pele aparece mas não é percorrido (o menu do Portal não
 * lista as seções do Onepage).
 *
 * Item: `id`, `rotulo`, `url`, `externo`, `nova_aba`, `filhos` (lista de itens). Profundidade e itens por nível têm teto;
 * o que passa do teto é cortado e contado em `cortes` (o painel avisa).
 */
final class MenuDoSite
{
    public const PROFUNDIDADE_PADRAO = 3;
    public const PROFUNDIDADE_MAXIMA = 5;
    public const ITENS_POR_NIVEL = 50;

    /** @var array<int, Snapshot> */
    private array $memoria = [];

    /** Quantas consultas ao banco esta instância fez (para os testes de desempenho). */
    public int $consultas = 0;

    private readonly int $profundidade;

    public function __construct(
        private readonly Connection $conexao,
        private readonly Caminhos $caminhos,
        private readonly Auditoria $auditoria,
        int $profundidade = self::PROFUNDIDADE_PADRAO,
        private readonly ?ConfigDoSiteRepositorio $config = null,
    ) {
        $this->profundidade = max(1, min(self::PROFUNDIDADE_MAXIMA, $profundidade));
    }

    /** O teto global (`PBL_MENU_PROFUNDIDADE`). */
    public function profundidade(): int
    {
        return $this->profundidade;
    }

    /** A profundidade do site: a configurada (`menu_profundidade`, ADR 0017) limitada pelo teto global; 0 ou ausente = o teto. */
    public function profundidadeDe(Site $site): int
    {
        $propria = $this->config?->valor($site->id, 'menu_profundidade');

        return is_int($propria) && $propria >= 1 ? min($propria, $this->profundidade) : $this->profundidade;
    }

    /** @return Snapshot */
    public function snapshot(Site $site): array
    {
        if (isset($this->memoria[$site->id])) {
            return $this->memoria[$site->id];
        }
        $lido = $this->ler($site->id);
        if ($lido !== null && $lido['profundidade'] === $this->profundidadeDe($site)) {
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
     * A subárvore cuja raiz de pele é `$raizId` (ADR 0022): `url` da raiz e os itens. Null se não houver (o chamador usa a árvore do site).
     *
     * @return array{url: string, arvore: Arvore}|null
     */
    public function subarvore(Site $site, int $raizId): ?array
    {
        return $this->snapshot($site)['subarvores'][$raizId] ?? null;
    }

    /**
     * Monta de novo a partir do banco (uma consulta), grava o snapshot e devolve-o. Não mexe no pendente.
     *
     * @return Snapshot
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

    /** @return Snapshot */
    private function construir(Site $site): array
    {
        $profundidade = $this->profundidadeDe($site);
        if ($site->objetoRaizId === null) {
            return ['hash' => hash('sha256', '[]'), 'profundidade' => $profundidade, 'cortes' => 0, 'arvore' => [], 'subarvores' => []];
        }
        $agora = gmdate('c');
        $this->consultas++;
        $linhas = $this->conexao->fetchAllAssociative(
            "SELECT o.id, o.pai_id, o.titulo, o.rotulo_menu, o.url_amigavel, o.dados, o.pele, c.nome AS classe,
                    d.url_amigavel AS destino_url
               FROM objeto o
               JOIN classe c ON c.id = o.classe_id
               LEFT JOIN objeto d ON c.nome = 'link' AND d.site_id = o.site_id AND d.status = 'publicado'
                    AND (d.data_publicacao IS NULL OR d.data_publicacao <= ?)
                    AND (d.data_validade IS NULL OR d.data_validade >= ?)
                    AND d.id = CAST(json_extract(o.dados, '\$.destino_interno') AS INTEGER)
              WHERE o.site_id = ? AND o.no_menu = 1 AND o.status = 'publicado'
                AND (o.data_publicacao IS NULL OR o.data_publicacao <= ?)
                AND (o.data_validade IS NULL OR o.data_validade >= ?)
              ORDER BY o.peso, o.titulo, o.id",
            [$agora, $agora, $site->id, $agora, $agora],
        );
        $porPai = [];
        foreach ($linhas as $l) {
            $porPai[(int) ($l['pai_id'] ?? 0)][] = $l;
        }
        $cortes = 0;
        $arvore = $this->nivel($porPai, $site->objetoRaizId, 1, $cortes, $profundidade);
        // Uma subárvore por objeto com pele própria (não precisa estar no menu); a raiz do site não entra (é a árvore principal).
        $subarvores = [];
        $this->consultas++;
        $raizes = $this->conexao->fetchAllAssociative(
            "SELECT id, url_amigavel FROM objeto WHERE site_id = ? AND pele IS NOT NULL AND pele != '' AND status <> 'apagado' ORDER BY id LIMIT 200",
            [$site->id],
        );
        foreach ($raizes as $r) {
            $id = (int) $r['id'];
            if ($id === $site->objetoRaizId) {
                continue;
            }
            $subarvores[$id] = ['url' => '/' . $r['url_amigavel'], 'arvore' => $this->nivel($porPai, $id, 1, $cortes, $profundidade)];
        }

        return ['hash' => hash('sha256', json_encode([$arvore, $subarvores], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 'profundidade' => $profundidade, 'cortes' => $cortes, 'arvore' => $arvore, 'subarvores' => $subarvores];
    }

    /**
     * @param array<int, list<array<string, mixed>>> $porPai
     * @return list<array<string, mixed>>
     */
    private function nivel(array $porPai, int $paiId, int $nivel, int &$cortes, int $profundidade): array
    {
        $filhos = $porPai[$paiId] ?? [];
        if ($nivel > $profundidade) {
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
            // Quem tem pele própria é raiz de outra subárvore: aparece aqui, mas o que há dentro é do menu dele.
            $item['filhos'] = ($l['pele'] ?? '') !== '' ? [] : $this->nivel($porPai, (int) $l['id'], $nivel + 1, $cortes, $profundidade);
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

    /** @return Snapshot|null */
    private function ler(int $siteId): ?array
    {
        $arquivo = $this->arquivo($siteId);
        if (!is_file($arquivo)) {
            return null;
        }
        $json = json_decode((string) @file_get_contents($arquivo), true);
        if (!is_array($json) || !is_string($json['hash'] ?? null) || !is_array($json['arvore'] ?? null) || !is_array($json['subarvores'] ?? null)) {
            return null;
        }

        /** @var list<array<string, mixed>> $arvore */
        $arvore = $json['arvore'];

        /** @var array<int, array{url: string, arvore: Arvore}> $subarvores */
        $subarvores = $json['subarvores'];

        return ['hash' => $json['hash'], 'profundidade' => (int) ($json['profundidade'] ?? 0), 'cortes' => (int) ($json['cortes'] ?? 0), 'arvore' => $arvore, 'subarvores' => $subarvores];
    }

    /** @param Snapshot $snap */
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
