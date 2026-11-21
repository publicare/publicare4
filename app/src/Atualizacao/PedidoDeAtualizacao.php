<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

use Pbl\Caminhos;

/**
 * O pedido agendado em `dados/atualizacao/pedido.json` (0600, atômico). Um de cada vez: pedido repetido é recusado. As mudanças
 * (agendar, cancelar, marcar como iniciado) passam por um `flock` próprio (`dados/atualizacao-pedido.lock`), para o cancelamento
 * do painel e o início pelo cron nunca se cruzarem: o cancelamento só vale se o pedido ainda não começou.
 */
final class PedidoDeAtualizacao
{
    public const LIMITE = 4096;

    /** @var \Closure(): int */
    private readonly \Closure $relogio;

    /** @param (\Closure(): int)|null $relogio só para os testes */
    public function __construct(private readonly Caminhos $caminhos, ?\Closure $relogio = null)
    {
        $this->relogio = $relogio ?? static fn (): int => time();
    }

    public static function arquivo(Caminhos $caminhos): string
    {
        return $caminhos->dados() . '/atualizacao/pedido.json';
    }

    public function agora(): int
    {
        return ($this->relogio)();
    }

    /** Há um arquivo de pedido (válido ou não)? Consulta barata, sem ler o conteúdo. */
    public function existe(): bool
    {
        return is_file(self::arquivo($this->caminhos)) || is_link(self::arquivo($this->caminhos));
    }

    /** @return PedidoAgendado|null null se não há ou se o arquivo está fora do esquema (corrompido, adulterado, grande demais) */
    public function ler(): ?PedidoAgendado
    {
        $t = ArquivoAtomico::ler(self::arquivo($this->caminhos), self::LIMITE);

        return $t === null ? null : PedidoAgendado::deArray(json_decode($t, true));
    }

    /**
     * @param string $tipo `atualizar` ou `reverter`
     * @param string $versaoAlvo a versão que o manifesto assinado (ou o registro da última atualização, na volta atrás) indicou
     * @throws ErroDeAtualizacao se já existe pedido ou o arquivo não pôde ser gravado
     */
    public function agendar(string $tipo, string $versaoAlvo, int $usuarioId, string $usuarioNome): PedidoAgendado
    {
        if (!in_array($tipo, ['atualizar', 'reverter'], true) || !Semver::valida($versaoAlvo)) {
            throw new ErroDeAtualizacao('Pedido inválido.');
        }

        return $this->travado(function () use ($tipo, $versaoAlvo, $usuarioId, $usuarioNome): PedidoAgendado {
            $agora = $this->agora();
            $atual = $this->ler();
            if ($atual !== null && ($atual->emAndamento() || !$atual->vencido($agora))) {
                throw new ErroDeAtualizacao($atual->emAndamento()
                    ? 'A atualização agendada já está em andamento. Espere terminar.'
                    : 'Já existe uma atualização agendada. Cancele-a antes de pedir outra.');
            }
            $p = new PedidoAgendado($tipo, $versaoAlvo, $usuarioId, mb_substr($usuarioNome, 0, 100), $agora, $agora + PedidoAgendado::VALIDADE, bin2hex(random_bytes(16)));
            $this->gravar($p);

            return $p;
        });
    }

    /**
     * Cancela um pedido que ainda não começou.
     *
     * @throws ErroDeAtualizacao se não há pedido ou ele já está em andamento
     */
    public function cancelar(): PedidoAgendado
    {
        return $this->travado(function (): PedidoAgendado {
            $p = $this->ler();
            if ($p === null) {
                throw new ErroDeAtualizacao('Não há atualização agendada para cancelar.');
            }
            if ($p->emAndamento()) {
                throw new ErroDeAtualizacao('A atualização já começou e não dá mais para cancelar. Acompanhe o andamento abaixo.');
            }
            ArquivoAtomico::apagar(self::arquivo($this->caminhos));

            return $p;
        });
    }

    /**
     * O cron marca o pedido como iniciado. Só vale se ainda é o MESMO pedido (mesmo nonce), ainda aguardando e dentro da validade.
     *
     * @return PedidoAgendado|null o pedido já marcado; null se foi cancelado, trocado ou venceu entre a leitura e agora
     */
    public function iniciar(PedidoAgendado $visto): ?PedidoAgendado
    {
        return $this->travado(function () use ($visto): ?PedidoAgendado {
            $p = $this->ler();
            if ($p === null || !hash_equals($visto->nonce, $p->nonce) || $p->emAndamento() || $p->vencido($this->agora())) {
                return null;
            }
            $novo = $p->comEstadoEmAndamento($this->agora());
            $this->gravar($novo);

            return $novo;
        });
    }

    /** Apaga o pedido (executado, vencido ou inválido). */
    public function consumir(): void
    {
        $this->travado(function (): null {
            ArquivoAtomico::apagar(self::arquivo($this->caminhos));

            return null;
        });
    }

    private function gravar(PedidoAgendado $p): void
    {
        ArquivoAtomico::gravar(self::arquivo($this->caminhos), json_encode($p->paraArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @template T
     * @param \Closure(): T $f
     * @return T
     */
    private function travado(\Closure $f): mixed
    {
        $dados = $this->caminhos->dados();
        if (!is_dir($dados) && !@mkdir($dados, 0750, true) && !is_dir($dados)) {
            throw new ErroDeAtualizacao('Não foi possível criar a pasta dados/.');
        }
        $l = @fopen($dados . '/atualizacao-pedido.lock', 'c');
        if ($l === false || !flock($l, LOCK_EX)) {
            throw new ErroDeAtualizacao('Não foi possível criar dados/atualizacao-pedido.lock (falta permissão de escrita em dados/).');
        }
        try {
            return $f();
        } finally {
            flock($l, LOCK_UN);
            fclose($l);
        }
    }
}
