<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

/**
 * O pedido que o painel deixa em `dados/atualizacao/pedido.json` para o cron executar. Só descreve O QUE fazer (atualizar ou voltar
 * atrás), PARA QUAL versão, quem pediu e até quando vale; nunca guarda senha nem nada que vire linha de comando. O esquema é
 * fechado: campo a mais, tipo errado ou nonce que não seja 32 caracteres hexadecimais invalida o arquivo inteiro.
 */
final class PedidoAgendado
{
    public const AGUARDANDO = 'aguardando';
    public const EM_ANDAMENTO = 'em_andamento';
    public const VALIDADE = 1800;

    private const CAMPOS = ['tipo', 'versao_alvo', 'por_id', 'por_nome', 'em', 'valido_ate', 'nonce', 'estado', 'iniciada_em'];

    public function __construct(
        public readonly string $tipo,
        public readonly string $versaoAlvo,
        public readonly int $porId,
        public readonly string $porNome,
        public readonly int $em,
        public readonly int $validoAte,
        public readonly string $nonce,
        public readonly string $estado = self::AGUARDANDO,
        public readonly int $iniciadaEm = 0,
    ) {
    }

    public function vencido(int $agora): bool
    {
        return $agora >= $this->validoAte;
    }

    public function emAndamento(): bool
    {
        return $this->estado === self::EM_ANDAMENTO;
    }

    public function comEstadoEmAndamento(int $agora): self
    {
        return new self($this->tipo, $this->versaoAlvo, $this->porId, $this->porNome, $this->em, $this->validoAte, $this->nonce, self::EM_ANDAMENTO, $agora);
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return [
            'tipo' => $this->tipo, 'versao_alvo' => $this->versaoAlvo, 'por_id' => $this->porId, 'por_nome' => $this->porNome, 'em' => $this->em,
            'valido_ate' => $this->validoAte, 'nonce' => $this->nonce, 'estado' => $this->estado, 'iniciada_em' => $this->iniciadaEm,
        ];
    }

    /** @param mixed $d o JSON lido; qualquer desvio do esquema devolve null */
    public static function deArray(mixed $d): ?self
    {
        if (!is_array($d) || array_diff(array_keys($d), self::CAMPOS) !== [] || array_diff(self::CAMPOS, array_keys($d)) !== []) {
            return null;
        }
        if (!in_array($d['tipo'], ['atualizar', 'reverter'], true) || !is_string($d['versao_alvo']) || !Semver::valida($d['versao_alvo'])
            || !is_int($d['por_id']) || !is_string($d['por_nome']) || mb_strlen($d['por_nome']) > 100
            || !is_int($d['em']) || !is_int($d['valido_ate']) || $d['valido_ate'] <= $d['em'] || $d['valido_ate'] - $d['em'] > self::VALIDADE
            || !is_string($d['nonce']) || preg_match('/^[0-9a-f]{32}$/D', $d['nonce']) !== 1
            || !in_array($d['estado'], [self::AGUARDANDO, self::EM_ANDAMENTO], true) || !is_int($d['iniciada_em'])) {
            return null;
        }

        return new self($d['tipo'], $d['versao_alvo'], $d['por_id'], $d['por_nome'], $d['em'], $d['valido_ate'], $d['nonce'], $d['estado'], $d['iniciada_em']);
    }
}
