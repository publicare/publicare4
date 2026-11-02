<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

use Pbl\Caminhos;

/**
 * O que a tela "Atualização" do painel guarda entre uma requisição e outra, **só em `dados/atualizacao-painel/`** (o atualizador
 * apaga `dados/atualizacao/` a cada execução, então o estado não pode ficar lá):
 *
 *  - `verificacao.json`: resultado da última consulta ao canal (cache de 24 h; falha, 1 h);
 *  - `estado.json`: a atualização iniciada pelo painel (de, para, quando); a existência dele sem `codigo` é a **trava**;
 *  - `saida.txt` e `codigo`: o que o processo de segundo plano escreveu e o código de saída (o arquivo `codigo` só aparece quando acabou);
 *  - `trava.lock`: `flock` curto, só para duas pessoas clicando juntas não iniciarem duas atualizações.
 *
 * Nada aqui é lido como código; JSON com tipo errado vira "sem dado".
 */
final class EstadoDoPainel
{
    public const VALIDADE_CACHE = 86400;
    public const VALIDADE_FALHA = 3600;
    /** Depois disto sem terminar, a trava é considerada solta (o processo morreu): permite tentar de novo. */
    public const LIMITE_EXECUCAO = 3600;
    private const MAX_SAIDA = 6000;

    /** @var \Closure(): int */
    private readonly \Closure $relogio;

    /** @param (\Closure(): int)|null $relogio */
    public function __construct(private readonly Caminhos $caminhos, ?\Closure $relogio = null)
    {
        $this->relogio = $relogio ?? static fn (): int => time();
    }

    public function pasta(): string
    {
        return $this->caminhos->dados() . '/atualizacao-painel';
    }

    // ---------------------------------------------------------------- cache da consulta

    /**
     * @return array{consultado_em: int, ok: bool, erro: string, disponivel: bool, motivo: string, manifesto: array{versao: string, data: string, resumo: string, notas: string, seguranca: bool, migracoes: bool, php_minimo: string}|null}|null
     *         null se não há cache, se venceu ou se foi feito para outra URL, canal ou versão instalada
     */
    public function verificacaoRecente(string $url, string $canal, string $instalada): ?array
    {
        $d = $this->ler('verificacao.json');
        if ($d === null || ($d['url'] ?? null) !== $url || ($d['canal'] ?? null) !== $canal || ($d['instalada'] ?? null) !== $instalada) {
            return null;
        }
        $quando = $d['consultado_em'] ?? null;
        $ok = ($d['ok'] ?? null) === true;
        if (!is_int($quando) || $quando > $this->agora() || $this->agora() - $quando >= ($ok ? self::VALIDADE_CACHE : self::VALIDADE_FALHA)) {
            return null;
        }
        $m = $d['manifesto'] ?? null;
        $manifesto = null;
        if (is_array($m)) {
            $texto = static fn (string $k): string => isset($m[$k]) && is_string($m[$k]) ? $m[$k] : '';
            $manifesto = [
                'versao' => $texto('versao'), 'data' => $texto('data'), 'resumo' => $texto('resumo'), 'notas' => $texto('notas'),
                'seguranca' => ($m['seguranca'] ?? false) === true, 'migracoes' => ($m['migracoes'] ?? false) === true, 'php_minimo' => $texto('php_minimo'),
            ];
            if (!Semver::valida($manifesto['versao'])) {
                return null;
            }
        }

        return [
            'consultado_em' => $quando, 'ok' => $ok, 'erro' => is_string($d['erro'] ?? null) ? $d['erro'] : '',
            'disponivel' => ($d['disponivel'] ?? false) === true && $manifesto !== null,
            'motivo' => is_string($d['motivo'] ?? null) ? $d['motivo'] : '', 'manifesto' => $manifesto,
        ];
    }

    public function guardarVerificacao(string $url, string $canal, string $instalada, ?Verificacao $v, string $erro = ''): void
    {
        $m = $v?->manifesto;
        $this->gravar('verificacao.json', [
            'url' => $url, 'canal' => $canal, 'instalada' => $instalada, 'consultado_em' => $this->agora(),
            'ok' => $v !== null, 'erro' => $erro, 'disponivel' => $v?->disponivel === true, 'motivo' => $v->motivo ?? '',
            'manifesto' => $m === null ? null : [
                'versao' => $m->versao, 'data' => $m->data, 'resumo' => $m->resumo, 'notas' => $m->notas,
                'seguranca' => $m->seguranca, 'migracoes' => $m->migracoes, 'php_minimo' => $m->phpMinimo,
            ],
        ]);
    }

    // ---------------------------------------------------------------- execução em segundo plano

    /**
     * @return array{estado: string, de: string, para: string, iniciado: int, saida: string, codigo: int|null, auditado: bool}
     *         `estado`: `ociosa`, `andamento`, `concluida`, `falhou` ou `sem_resposta` (passou do limite sem terminar)
     */
    public function situacao(): array
    {
        $e = $this->ler('estado.json');
        $vazio = ['estado' => 'ociosa', 'de' => '', 'para' => '', 'iniciado' => 0, 'saida' => '', 'codigo' => null, 'auditado' => false];
        if ($e === null || !is_int($e['iniciado'] ?? null)) {
            return $vazio;
        }
        $r = ['de' => is_string($e['de'] ?? null) ? $e['de'] : '', 'para' => is_string($e['para'] ?? null) ? $e['para'] : '',
            'iniciado' => $e['iniciado'], 'auditado' => ($e['auditado'] ?? false) === true, 'saida' => $this->saida(), 'codigo' => null];
        $codigo = is_file($this->pasta() . '/codigo') ? trim((string) file_get_contents($this->pasta() . '/codigo')) : null;
        if ($codigo !== null && preg_match('/^[0-9]{1,3}$/D', $codigo) === 1) {
            $r['codigo'] = (int) $codigo;

            return ['estado' => $r['codigo'] === 0 ? 'concluida' : 'falhou'] + $r;
        }

        return ['estado' => $this->agora() - $e['iniciado'] >= self::LIMITE_EXECUCAO ? 'sem_resposta' : 'andamento'] + $r;
    }

    /**
     * Tenta iniciar a atualização, uma de cada vez. Devolve false se já há uma em andamento (a trava).
     *
     * @throws ErroDeAtualizacao se o processo não pôde ser iniciado (a trava não fica presa)
     */
    public function iniciar(string $de, string $para, Lancador $lancador): bool
    {
        $this->garantirPasta();
        $trava = @fopen($this->pasta() . '/trava.lock', 'c');
        if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) {
            return false;
        }
        try {
            if ($this->situacao()['estado'] === 'andamento') {
                return false;
            }
            foreach (['estado.json', 'saida.txt', 'codigo', 'codigo.tmp'] as $arquivo) {
                @unlink($this->pasta() . '/' . $arquivo);
            }
            $this->gravar('estado.json', ['de' => $de, 'para' => $para, 'iniciado' => $this->agora(), 'auditado' => false]);
            try {
                $lancador->iniciar($this->pasta() . '/saida.txt', $this->pasta() . '/codigo');
            } catch (\Throwable $e) {
                @unlink($this->pasta() . '/estado.json');
                throw $e;
            }

            return true;
        } finally {
            flock($trava, LOCK_UN);
            fclose($trava);
        }
    }

    /** Marca que a conclusão já foi para a auditoria (para não registrar a cada recarga). */
    public function marcarAuditado(): void
    {
        $e = $this->ler('estado.json');
        if ($e !== null) {
            $e['auditado'] = true;
            $this->gravar('estado.json', $e);
        }
    }

    private function saida(): string
    {
        $arquivo = $this->pasta() . '/saida.txt';
        if (!is_file($arquivo)) {
            return '';
        }
        $texto = (string) file_get_contents($arquivo, false, null, max(0, (int) filesize($arquivo) - self::MAX_SAIDA), self::MAX_SAIDA);
        $texto = mb_scrub($texto, 'UTF-8');
        // sem códigos de controle (cores do terminal etc.), só quebra de linha e tabulação
        $texto = (string) preg_replace('/\e\[[0-9;]*[A-Za-z]|[\x00-\x08\x0B-\x1F\x7F]/', '', $texto);

        return trim($texto);
    }

    // ---------------------------------------------------------------- arquivos

    /** @return array<string, mixed>|null */
    private function ler(string $nome): ?array
    {
        $arquivo = $this->pasta() . '/' . $nome;
        if (!is_file($arquivo) || is_link($arquivo) || filesize($arquivo) > 65536) {
            return null;
        }
        $d = json_decode((string) file_get_contents($arquivo), true);

        return is_array($d) ? $d : null;
    }

    /** @param array<string, mixed> $dados */
    private function gravar(string $nome, array $dados): void
    {
        $this->garantirPasta();
        $arquivo = $this->pasta() . '/' . $nome;
        $tmp = $arquivo . '.tmp' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, json_encode($dados, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) === false || !@rename($tmp, $arquivo)) {
            @unlink($tmp);
            throw new ErroDeAtualizacao('Não foi possível gravar em dados/atualizacao-painel/ (falta permissão de escrita em dados/).');
        }
    }

    private function garantirPasta(): void
    {
        $p = $this->pasta();
        if (!is_dir($p) && !@mkdir($p, 0750, true) && !is_dir($p)) {
            throw new ErroDeAtualizacao('Não foi possível criar dados/atualizacao-painel/ (falta permissão de escrita em dados/).');
        }
    }

    private function agora(): int
    {
        return ($this->relogio)();
    }
}
