<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Conteudo\Classe;

/** O que a linha de conteúdo mostra (ADR 0032). Textos crus: o escape é do componente, exceto `selo` (HTML já escapado da tela). */
final class DadosDaLinha
{
    /**
     * @param string $selo HTML do selo de estado (já escapado), '' se não houver
     * @param list<string> $extras textos curtos a mais na linha de metadados (ex.: nome do site), crus
     * @param string $aposAcoes HTML já escapado de controles irmãos do menu (ex.: "Devolver" em Pendências)
     */
    public function __construct(
        public readonly int $id,
        public readonly string $titulo,
        public readonly string $href,
        public readonly ?Classe $classe,
        public readonly string $nomeDaClasse,
        public readonly string $selo = '',
        public readonly string $dataIso = '',
        public readonly string $dataCurta = '',
        public readonly string $dataCompleta = '',
        public readonly int $filhos = 0,
        public readonly ?int $selecionavelVersao = null,
        public readonly array $extras = [],
        public readonly string $rotuloDaData = 'Alterado em',
        public readonly string $aposAcoes = '',
    ) {
    }

    /**
     * Data gravada → [ISO, "dd/mm hh:mm", "dd/mm/aaaa hh:mm"] no fuso dado; null se vazia ou ilegível.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    public static function datas(string $gravada, string $fuso): ?array
    {
        if ($gravada === '') {
            return null;
        }
        try {
            $d = (new \DateTimeImmutable($gravada))->setTimezone(new \DateTimeZone($fuso));
        } catch (\Exception) {
            return null;
        }

        return [$d->format('c'), $d->format('d/m H:i'), $d->format('d/m/Y H:i')];
    }
}
