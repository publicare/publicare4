<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Pbl\Caminhos;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Conteudo\Site;

/**
 * Fila de republicação em lotes (ADR 0023). Substitui o antigo "até N objetos na requisição, senão rode `bin/pbl publicar`":
 * toda origem (painel com ou sem JavaScript, `bin/pbl publicar`, cron) usa a mesma fila, que guarda em
 * `dados/republicacao/site-<id>.json` a posição (último id tratado), o total, as falhas e as datas. Por isso a republicação é
 * **retomável**: se a aba fechar ou o processo morrer, o próximo lote continua de onde parou, de qualquer origem.
 *
 * Duas travas, ambas `flock` (o sistema solta sozinho se o processo morrer):
 *  - `site-<id>.inicio.lock`, curta, só para duas pessoas não iniciarem duas republicações do mesmo site: iniciar com uma
 *    republicação em andamento devolve a que já existe (retomada), não cria outra;
 *  - `site-<id>.lote.lock`, durante o lote, para dois processos não tratarem o mesmo trecho ao mesmo tempo. Quem não consegue a
 *    trava recebe `ocupado` e tenta de novo depois.
 *
 * O estado é só números, datas e ids (nada de caminho nem de conteúdo). Mensagens de erro passam por {@see mensagemSegura}.
 *
 * @phpstan-type Estado array{site_id: int, status: string, total: int, feitas: int, publicadas: int, falhas: int, ids_com_falha: list<int>, ultimo: int, iniciado_em: int, atualizado_em: int, concluido_em: int, origem: string, erros_seguidos: int, erro: string}
 */
final class FilaDeRepublicacao
{
    public const ANDANDO = 'andando';
    public const CONCLUIDO = 'concluido';
    public const FALHOU = 'falhou';

    /** Páginas por lote na requisição do painel. */
    public const LOTE_PAINEL = 25;
    /** Sem avanço por este tempo, a republicação "andando" é dada como abandonada e uma nova pode começar. */
    public const ABANDONO = 3600;
    /** Lotes seguidos que estouram exceção antes de a fila desistir (estado `falhou`). */
    private const ERROS_PARA_DESISTIR = 5;

    /** @var \Closure(): int */
    private readonly \Closure $relogio;

    /** @param (\Closure(): int)|null $relogio */
    public function __construct(
        private readonly Caminhos $caminhos,
        private readonly Publicador $publicador,
        private readonly ObjetoRepositorio $objetos,
        ?\Closure $relogio = null,
    ) {
        $this->relogio = $relogio ?? static fn (): int => time();
    }

    public function pasta(): string
    {
        return $this->caminhos->dados() . '/republicacao';
    }

    /** @return Estado|null */
    public function estado(int $siteId): ?array
    {
        $arquivo = $this->arquivo($siteId);
        $bruto = is_file($arquivo) ? @file_get_contents($arquivo) : false;
        $d = $bruto === false ? null : json_decode($bruto, true);
        if (!is_array($d)) {
            return null;
        }
        $n = static fn (string $k): int => isset($d[$k]) && is_int($d[$k]) && $d[$k] >= 0 ? $d[$k] : 0;
        $status = $d['status'] ?? null;
        $ids = [];
        foreach (is_array($d['ids_com_falha'] ?? null) ? $d['ids_com_falha'] : [] as $id) {
            if (is_int($id) && count($ids) < 20) {
                $ids[] = $id;
            }
        }

        return [
            'site_id' => $siteId,
            'status' => in_array($status, [self::ANDANDO, self::CONCLUIDO, self::FALHOU], true) ? (string) $status : self::FALHOU,
            'total' => $n('total'), 'feitas' => $n('feitas'), 'publicadas' => $n('publicadas'), 'falhas' => $n('falhas'),
            'ids_com_falha' => $ids, 'ultimo' => $n('ultimo'), 'iniciado_em' => $n('iniciado_em'), 'atualizado_em' => $n('atualizado_em'),
            'concluido_em' => $n('concluido_em'), 'origem' => is_string($d['origem'] ?? null) ? mb_substr($d['origem'], 0, 20) : '',
            'erros_seguidos' => $n('erros_seguidos'), 'erro' => is_string($d['erro'] ?? null) ? mb_substr($d['erro'], 0, 300) : '',
        ];
    }

    /** Há uma republicação em andamento (e não abandonada) para o site? */
    public function ativa(int $siteId): bool
    {
        $e = $this->estado($siteId);

        return $e !== null && $e['status'] === self::ANDANDO && $this->agora() - $e['atualizado_em'] < self::ABANDONO;
    }

    /**
     * Inicia a republicação do site ou, se já há uma em andamento, devolve a que existe (retomada). Nunca reinicia do zero uma que
     * está andando: é isso que impede duas republicações simultâneas.
     *
     * @return array{estado: Estado, retomada: bool}
     */
    public function iniciar(Site $site, string $origem): array
    {
        $trava = $this->travar($site->id, 'inicio', true);
        try {
            if ($this->ativa($site->id)) {
                return ['estado' => $this->estado($site->id) ?? $this->vazio($site->id), 'retomada' => true];
            }
            $raiz = $site->objetoRaizId === null ? null : $this->objetos->porId($site->objetoRaizId);
            $agora = $this->agora();
            $estado = [
                'site_id' => $site->id, 'status' => self::ANDANDO,
                'total' => $raiz === null ? 0 : $this->objetos->contarDaSubarvore($site->id, $raiz->caminho),
                'feitas' => 0, 'publicadas' => 0, 'falhas' => 0, 'ids_com_falha' => [], 'ultimo' => 0,
                'iniciado_em' => $agora, 'atualizado_em' => $agora, 'concluido_em' => 0, 'origem' => $origem, 'erros_seguidos' => 0, 'erro' => '',
            ];
            $this->gravar($estado);

            return ['estado' => $estado, 'retomada' => false];
        } finally {
            $this->soltar($trava);
        }
    }

    /**
     * Trata um lote da republicação em andamento. Sem republicação andando devolve o estado como está (ou null se nunca houve).
     * `ocupado` = outro processo está tratando um lote deste site agora; `terminou_agora` = este lote foi o último (quem recebe
     * isto registra a auditoria uma única vez).
     *
     * @return array{estado: Estado|null, ocupado: bool, terminou_agora: bool}
     */
    public function processar(Site $site, int $tamanho = self::LOTE_PAINEL, float $segundos = 8.0): array
    {
        $trava = $this->travar($site->id, 'lote', false);
        if ($trava === null) {
            return ['estado' => $this->estado($site->id), 'ocupado' => true, 'terminou_agora' => false];
        }
        try {
            $estado = $this->estado($site->id);
            if ($estado === null || $estado['status'] !== self::ANDANDO) {
                return ['estado' => $estado, 'ocupado' => false, 'terminou_agora' => false];
            }
            try {
                $r = $this->publicador->republicarLote($site, $estado['ultimo'], max(1, $tamanho), $segundos);
            } catch (\Throwable $e) {
                $estado['erros_seguidos']++;
                $estado['erro'] = self::mensagemSegura($e, $this->caminhos->raiz);
                $estado['atualizado_em'] = $this->agora();
                if ($estado['erros_seguidos'] >= self::ERROS_PARA_DESISTIR) {
                    $estado['status'] = self::FALHOU;
                }
                $this->gravar($estado);

                return ['estado' => $estado, 'ocupado' => false, 'terminou_agora' => false];
            }
            $estado['feitas'] += $r['processadas'];
            $estado['publicadas'] += $r['publicadas'];
            $estado['falhas'] += $r['falhas'];
            $estado['ids_com_falha'] = array_slice(array_merge($estado['ids_com_falha'], $r['ids_com_falha']), 0, 20);
            $estado['ultimo'] = $r['ultimo'];
            $estado['erros_seguidos'] = 0;
            $estado['erro'] = '';
            $estado['atualizado_em'] = $this->agora();
            // Objetos criados durante a republicação aumentam o total: o que importa é nunca mostrar "feitas" maior que "total".
            $estado['total'] = max($estado['total'], $estado['feitas']);
            if ($r['concluido']) {
                $estado['status'] = self::CONCLUIDO;
                $estado['concluido_em'] = $estado['atualizado_em'];
                $estado['total'] = $estado['feitas'];
            }
            $this->gravar($estado);

            return ['estado' => $estado, 'ocupado' => false, 'terminou_agora' => $r['concluido']];
        } finally {
            $this->soltar($trava);
        }
    }

    /**
     * Segundos entre o início e o fim (ou o último avanço, se ainda anda).
     *
     * @param Estado $estado
     */
    public function duracao(array $estado): int
    {
        $fim = $estado['concluido_em'] > 0 ? $estado['concluido_em'] : $estado['atualizado_em'];

        return max(0, $fim - $estado['iniciado_em']);
    }

    /** Mensagem de exceção sem o caminho da instalação e curta; nunca o rastreamento. */
    public static function mensagemSegura(\Throwable $e, string $raiz): string
    {
        $m = str_replace($raiz, '…', $e->getMessage());
        $m = preg_replace('/\s+/', ' ', $m) ?? '';

        return mb_substr(trim($m) === '' ? 'Erro inesperado.' : trim($m), 0, 300);
    }

    private function arquivo(int $siteId): string
    {
        return $this->pasta() . '/site-' . $siteId . '.json';
    }

    private function agora(): int
    {
        return ($this->relogio)();
    }

    /** @return Estado */
    private function vazio(int $siteId): array
    {
        $agora = $this->agora();

        return ['site_id' => $siteId, 'status' => self::ANDANDO, 'total' => 0, 'feitas' => 0, 'publicadas' => 0, 'falhas' => 0, 'ids_com_falha' => [], 'ultimo' => 0,
            'iniciado_em' => $agora, 'atualizado_em' => $agora, 'concluido_em' => 0, 'origem' => '', 'erros_seguidos' => 0, 'erro' => ''];
    }

    /** @param Estado $estado */
    private function gravar(array $estado): void
    {
        $pasta = $this->pasta();
        if (!is_dir($pasta) && !@mkdir($pasta, 0775, true) && !is_dir($pasta)) {
            throw new \RuntimeException('Não foi possível criar a pasta dados/republicacao/.');
        }
        $arquivo = $this->arquivo($estado['site_id']);
        $tmp = $arquivo . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($estado, JSON_THROW_ON_ERROR)) === false || !@rename($tmp, $arquivo)) {
            @unlink($tmp);
            throw new \RuntimeException('Não foi possível gravar o estado da republicação (dados/republicacao/).');
        }
    }

    /**
     * @return resource|null null se `$esperar` é falso e a trava está com outro processo
     */
    private function travar(int $siteId, string $nome, bool $esperar)
    {
        $pasta = $this->pasta();
        if (!is_dir($pasta) && !@mkdir($pasta, 0775, true) && !is_dir($pasta)) {
            throw new \RuntimeException('Não foi possível criar a pasta dados/republicacao/.');
        }
        $f = @fopen($pasta . '/site-' . $siteId . '.' . $nome . '.lock', 'c');
        if ($f === false) {
            throw new \RuntimeException('Não foi possível criar a trava da republicação (falta permissão de escrita em dados/).');
        }
        if (!flock($f, $esperar ? LOCK_EX : LOCK_EX | LOCK_NB)) {
            fclose($f);

            return null;
        }

        return $f;
    }

    /** @param resource|null $trava */
    private function soltar($trava): void
    {
        if ($trava !== null) {
            flock($trava, LOCK_UN);
            fclose($trava);
        }
    }
}
