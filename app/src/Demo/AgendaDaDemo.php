<?php

declare(strict_types=1);

namespace Pbl\Demo;

use Pbl\Caminhos;

/**
 * Quando a demo reinicia. O estado é `dados/demo/estado.json` (`{"ultimo": <unix>}`: o último reinício ou, na primeira leitura, o
 * momento em que a agenda passou a existir, para ligar a flag numa instalação antiga nunca reiniciar de surpresa na hora). O
 * próximo reinício é `dias` dias depois do último, à `hora` (Brasília) desse dia. `dados/demo/` não é apagada pelo reinício.
 */
final class AgendaDaDemo
{
    /** Depois de um reinício que falhou e foi desfeito, o cron espera isto antes de tentar de novo (não martela o servidor a cada minuto). */
    public const ESPERA_APOS_FALHA = 3600;

    /** @var \Closure(): int */
    private readonly \Closure $relogio;

    /** @param (\Closure(): int)|null $relogio só para os testes */
    public function __construct(private readonly Caminhos $caminhos, ?\Closure $relogio = null)
    {
        $this->relogio = $relogio ?? static fn (): int => time();
    }

    public function agora(): int
    {
        return ($this->relogio)();
    }

    public function pasta(): string
    {
        return $this->caminhos->dados() . '/demo';
    }

    /** O instante do último reinício (ou da criação da agenda). Grava o ponto de partida na primeira vez. */
    public function ultimo(): int
    {
        $d = $this->ler();
        if (isset($d['ultimo']) && is_int($d['ultimo']) && $d['ultimo'] > 0) {
            return $d['ultimo'];
        }
        $agora = $this->agora();
        $this->registrar($agora);

        return $agora;
    }

    /** Marca o último reinício (apaga a marca de falha). */
    public function registrar(int $quando): void
    {
        $this->gravar(['ultimo' => $quando]);
    }

    /** Marca que o último reinício falhou (e foi desfeito): a tarefa só tenta de novo daqui a {@see ESPERA_APOS_FALHA} segundos. */
    public function registrarFalha(): void
    {
        $this->gravar(['ultimo' => $this->ultimo(), 'falha_em' => $this->agora()]);
    }

    /** @return array<string, mixed> */
    private function ler(): array
    {
        $arquivo = $this->pasta() . '/estado.json';
        $d = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;

        return is_array($d) ? $d : [];
    }

    /** @param array<string, int> $dados */
    private function gravar(array $dados): void
    {
        $pasta = $this->pasta();
        if (!is_dir($pasta) && !@mkdir($pasta, 0775, true) && !is_dir($pasta)) {
            return; // sem permissão: a agenda só não avança; o aviso e a tarefa seguem com o que há
        }
        @file_put_contents($pasta . '/estado.json', json_encode($dados, JSON_THROW_ON_ERROR), LOCK_EX);
    }

    /** Instante do próximo reinício. */
    public function proximo(): int
    {
        return self::calcular($this->ultimo(), ConfigDaDemo::dias($this->caminhos), ConfigDaDemo::hora($this->caminhos));
    }

    /** A hora chegou (e o último reinício não falhou há pouco)? */
    public function vencido(): bool
    {
        $falha = $this->ler()['falha_em'] ?? null;
        if (is_int($falha) && $this->agora() - $falha < self::ESPERA_APOS_FALHA) {
            return false;
        }

        return $this->agora() >= $this->proximo();
    }

    /** `$dias` dias depois do dia (em Brasília) de `$ultimo`, à `$hora`. */
    public static function calcular(int $ultimo, int $dias, string $hora): int
    {
        $fuso = new \DateTimeZone(ConfigDaDemo::FUSO);
        $dia = (new \DateTimeImmutable('@' . $ultimo))->setTimezone($fuso)->setTime(0, 0)->modify("+{$dias} days");
        [$h, $m] = array_map('intval', explode(':', $hora));

        return $dia->setTime($h, $m)->getTimestamp();
    }

    /** `DD/MM às HH:MM` (Brasília). */
    public static function rotulo(int $instante): string
    {
        $d = (new \DateTimeImmutable('@' . $instante))->setTimezone(new \DateTimeZone(ConfigDaDemo::FUSO));

        return $d->format('d/m') . ' às ' . $d->format('H:i');
    }
}
