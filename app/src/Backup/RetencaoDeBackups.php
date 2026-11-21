<?php

declare(strict_types=1);

namespace Pbl\Backup;

/**
 * Política de retenção (ADR 0032). Entre as cópias manuais/agendadas, apaga só a que viola as duas regras: está além das N mais
 * recentes **e** é mais velha que X dias (0 desliga a regra). Nunca apaga: a cópia pré-atualização (o "voltar atrás"), a cópia
 * mais recente, a única íntegra, nem a pré-restauração dos últimos 7 dias.
 */
final class RetencaoDeBackups
{
    public const DIAS_PRE_RESTAURACAO = 7;

    public function __construct(private readonly int $ultimas, private readonly int $dias)
    {
    }

    /**
     * @param list<InfoDeBackup> $copias qualquer ordem
     * @return list<InfoDeBackup> as que a política apagaria
     */
    public function planejar(array $copias, int $agora): array
    {
        $regulares = array_values(array_filter($copias, static fn (InfoDeBackup $c): bool => in_array($c->tipo, ['manual', 'agendada'], true) && !$c->protegida));
        usort($regulares, static fn (InfoDeBackup $a, InfoDeBackup $b): int => $b->criadoEm <=> $a->criadoEm);
        $limite = $this->dias > 0 ? $agora - $this->dias * 86400 : null;
        $apagar = [];
        foreach ($regulares as $posicao => $c) {
            if ($posicao === 0) {
                continue; // a mais recente nunca sai
            }
            $alemDoNumero = $this->ultimas === 0 || $posicao >= $this->ultimas;
            $velha = $limite === null || $c->criadoEm < $limite;
            if ($this->ultimas === 0 && $limite === null) {
                continue; // retenção desligada
            }
            if ($alemDoNumero && $velha) {
                $apagar[] = $c;
            }
        }
        foreach ($copias as $c) {
            if ($c->tipo === 'pre-restauracao' && !$c->protegida && $this->dias > 0 && $c->criadoEm < $agora - max($this->dias, self::DIAS_PRE_RESTAURACAO) * 86400) {
                $apagar[] = $c;
            }
        }

        return $this->preservarUmaIntegra($copias, $apagar);
    }

    /**
     * @param list<InfoDeBackup> $copias
     * @param list<InfoDeBackup> $apagar
     * @return list<InfoDeBackup>
     */
    private function preservarUmaIntegra(array $copias, array $apagar): array
    {
        $nomes = array_map(static fn (InfoDeBackup $c): string => $c->arquivo, $apagar);
        $sobra = array_filter($copias, static fn (InfoDeBackup $c): bool => !in_array($c->arquivo, $nomes, true) && $c->integro !== false && !$c->legado);
        if ($sobra !== []) {
            return $apagar;
        }
        // nada íntegro sobraria: poupa a mais recente íntegra do plano
        $candidatas = array_filter($apagar, static fn (InfoDeBackup $c): bool => $c->integro !== false);
        usort($candidatas, static fn (InfoDeBackup $a, InfoDeBackup $b): int => $b->criadoEm <=> $a->criadoEm);
        $poupar = $candidatas[0]->arquivo ?? null;

        return array_values(array_filter($apagar, static fn (InfoDeBackup $c): bool => $c->arquivo !== $poupar));
    }

    /**
     * @param list<InfoDeBackup> $apagar
     * @return int quantas apagou
     */
    public static function aplicar(CatalogoDeBackups $catalogo, array $apagar): int
    {
        $n = 0;
        foreach ($apagar as $c) {
            if ($c->protegida || $c->legado) {
                continue;
            }
            $arquivo = $catalogo->caminhoDe($c->arquivo);
            if (@unlink($arquivo)) {
                @unlink($arquivo . '.sha256');
                ++$n;
            }
        }

        return $n;
    }
}
