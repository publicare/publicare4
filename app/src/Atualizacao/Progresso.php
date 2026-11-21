<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

/** O andamento gravado em `dados/atualizacao/progresso.json`: uma foto da última atualização (ou volta atrás), em andamento ou terminada. */
final class Progresso
{
    public const ORIGENS = ['terminal', 'cron', 'painel'];
    public const TIPOS = ['atualizar', 'reverter'];

    public function __construct(
        public readonly string $origem,
        public readonly string $tipo,
        public readonly Etapa $etapa,
        public readonly int $percentual,
        public readonly string $mensagem,
        public readonly int $inicio,
        public readonly ?int $fim,
        public readonly string $falha,
        public readonly int $atualizado,
    ) {
    }

    public function terminou(): bool
    {
        return $this->fim !== null;
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return [
            'origem' => $this->origem, 'tipo' => $this->tipo, 'etapa' => $this->etapa->value, 'percentual' => $this->percentual,
            'mensagem' => $this->mensagem, 'inicio' => $this->inicio, 'fim' => $this->fim, 'falha' => $this->falha, 'atualizado' => $this->atualizado,
        ];
    }

    /** @param mixed $d o JSON lido; fora do esquema devolve null */
    public static function deArray(mixed $d): ?self
    {
        if (!is_array($d)) {
            return null;
        }
        $etapa = is_string($d['etapa'] ?? null) ? Etapa::tryFrom($d['etapa']) : null;
        $fim = $d['fim'] ?? null;
        if ($etapa === null || !in_array($d['origem'] ?? null, self::ORIGENS, true) || !in_array($d['tipo'] ?? null, self::TIPOS, true)
            || !is_int($d['percentual'] ?? null) || !is_string($d['mensagem'] ?? null) || !is_int($d['inicio'] ?? null)
            || !($fim === null || is_int($fim)) || !is_string($d['falha'] ?? null) || !is_int($d['atualizado'] ?? null)) {
            return null;
        }

        return new self(
            $d['origem'], $d['tipo'], $etapa, max(0, min(100, $d['percentual'])), mb_substr($d['mensagem'], 0, 200), $d['inicio'], $fim,
            mb_substr($d['falha'], 0, SaneadorDeMensagem::MAXIMO), $d['atualizado'],
        );
    }
}
