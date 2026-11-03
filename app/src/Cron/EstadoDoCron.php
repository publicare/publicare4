<?php

declare(strict_types=1);

namespace Pbl\Cron;

use Pbl\Caminhos;

/**
 * O que o cron central guarda em `dados/cron/` (ADR 0023): `estado.json` (por tarefa: última verificação, última execução,
 * resultado, duração e erro; e os "batimentos" de cada origem) e `config.json` (o pseudo-cron ligado ou não). A trava do ciclo
 * (`cron.lock`) fica na mesma pasta. Só números, datas e textos curtos e saneados; nada é lido como código e JSON com tipo
 * errado vira "sem dado".
 *
 * Origens: `cron` (o cron do servidor, `bin/pbl cron`), `pseudo` (acesso ao painel quando o cron está atrasado) e `manual`
 * (botão "Executar agora"). Só `cron` conta para o aviso de atraso.
 *
 * @phpstan-type EstadoTarefa array{verificada_em: int, executada_em: int, resultado: string, resumo: string, duracao_ms: int, erro: string}
 */
final class EstadoDoCron
{
    /** Passado este tempo sem o cron real rodar, o painel avisa e o pseudo-cron (se ligado) assume. */
    public const ATRASO_MAXIMO = 300;

    public const OK = 'ok';
    public const ERRO = 'erro';
    public const PARCIAL = 'parcial';

    /** @var \Closure(): int */
    private readonly \Closure $relogio;

    /** @param (\Closure(): int)|null $relogio */
    public function __construct(private readonly Caminhos $caminhos, ?\Closure $relogio = null)
    {
        $this->relogio = $relogio ?? static fn (): int => time();
    }

    public function pasta(): string
    {
        return $this->caminhos->dados() . '/cron';
    }

    public function agora(): int
    {
        return ($this->relogio)();
    }

    /** @return EstadoTarefa */
    public function tarefa(string $id): array
    {
        $t = $this->ler()['tarefas'][$id] ?? null;
        $n = static fn (string $k): int => is_array($t) && isset($t[$k]) && is_int($t[$k]) && $t[$k] >= 0 ? $t[$k] : 0;
        $s = static fn (string $k, int $max): string => is_array($t) && isset($t[$k]) && is_string($t[$k]) ? mb_substr($t[$k], 0, $max) : '';

        return [
            'verificada_em' => $n('verificada_em'), 'executada_em' => $n('executada_em'), 'resultado' => $s('resultado', 12),
            'resumo' => $s('resumo', 300), 'duracao_ms' => $n('duracao_ms'), 'erro' => $s('erro', 300),
        ];
    }

    /** @param array<string, mixed> $campos campos de EstadoTarefa a trocar */
    public function gravarTarefa(string $id, array $campos): void
    {
        $e = $this->ler();
        $e['tarefas'][$id] = array_merge($this->tarefa($id), $campos);
        $this->gravar('estado.json', $e);
    }

    /** Registra o fim de uma rodada da origem (o "batimento" que a tela e o aviso de atraso leem). */
    public function registrarCiclo(string $origem): void
    {
        $e = $this->ler();
        $e['batimentos'][$origem] = $this->agora();
        $this->gravar('estado.json', $e);
    }

    public function ultimoCiclo(string $origem): ?int
    {
        $v = $this->ler()['batimentos'][$origem] ?? null;

        return is_int($v) && $v > 0 ? $v : null;
    }

    /** O cron real está atrasado (nunca rodou ou passou de {@see ATRASO_MAXIMO})? */
    public function cronAtrasado(): bool
    {
        $u = $this->ultimoCiclo('cron');

        return $u === null || $this->agora() - $u > self::ATRASO_MAXIMO;
    }

    public function pseudoLigado(): bool
    {
        $env = getenv('PBL_PSEUDO_CRON');
        if ($env === '0') {
            return false;
        }
        if ($env === '1') {
            return true;
        }
        $c = $this->lerArquivo('config.json');

        return ($c['pseudo'] ?? false) === true;
    }

    /** O pseudo-cron está travado por variável de ambiente (a tela não pode mudar)? */
    public function pseudoPorAmbiente(): bool
    {
        return in_array(getenv('PBL_PSEUDO_CRON'), ['0', '1'], true);
    }

    public function definirPseudo(bool $ligado): void
    {
        $c = $this->lerArquivo('config.json');
        $c['pseudo'] = $ligado;
        $this->gravar('config.json', $c);
    }

    /** @return array{tarefas: array<string, mixed>, batimentos: array<string, mixed>} */
    private function ler(): array
    {
        $d = $this->lerArquivo('estado.json');
        $t = $d['tarefas'] ?? null;
        $b = $d['batimentos'] ?? null;

        return ['tarefas' => is_array($t) ? $t : [], 'batimentos' => is_array($b) ? $b : []];
    }

    /** @return array<string, mixed> */
    private function lerArquivo(string $nome): array
    {
        $arquivo = $this->pasta() . '/' . $nome;
        $bruto = is_file($arquivo) ? @file_get_contents($arquivo) : false;
        $d = $bruto === false ? null : json_decode($bruto, true);

        return is_array($d) ? $d : [];
    }

    /** @param array<string, mixed> $dados */
    private function gravar(string $nome, array $dados): void
    {
        $pasta = $this->pasta();
        if (!is_dir($pasta) && !@mkdir($pasta, 0775, true) && !is_dir($pasta)) {
            throw new \RuntimeException('Não foi possível criar a pasta dados/cron/ (falta permissão de escrita em dados/).');
        }
        $arquivo = $pasta . '/' . $nome;
        $tmp = $arquivo . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($dados, JSON_THROW_ON_ERROR)) === false || !@rename($tmp, $arquivo)) {
            @unlink($tmp);
            throw new \RuntimeException('Não foi possível gravar dados/cron/' . $nome . '.');
        }
    }
}
