<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

use Pbl\Acesso\Auditoria;
use Pbl\Caminhos;
use Pbl\Servicos;

/**
 * Executa, dentro do processo do cron (`bin/pbl cron`), o pedido que o painel deixou em `dados/atualizacao/pedido.json`: a MESMA
 * rotina de `bin/pbl atualizar` (`Atualizador::verificar` + `aplicar`) ou de `bin/pbl reverter --confirmar` (`Reversor`), sob a
 * mesma trava `dados/atualizacao.lock`. Nada do pedido vira linha de comando, e ele nunca substitui a verificação: o manifesto é
 * baixado e a assinatura conferida de novo, a versão do manifesto precisa ser IGUAL à do pedido, e só então `aplicar` refaz tamanho,
 * SHA-256 e extração. Na volta atrás o `Reversor` continua conferindo o registro, o código guardado e a cópia do banco.
 *
 * Decisão sobre a trava: esta classe só a SONDA (pega e solta na hora). Quem a segura de verdade é `aplicar`/`reverter`, e como
 * `flock` não é reentrante a tarefa não pode segurá-la junto. A janela entre sondar e aplicar é inofensiva: se outra operação
 * entrar nela, `aplicar` falha com "Outra atualização em andamento" e o pedido é descartado com esse motivo (peça de novo).
 *
 * Pedido vencido, ilegível, adulterado (fora do esquema ou com nonce que não confere) ou já "em andamento" sem ninguém rodando
 * é apagado e vira `falhou` no andamento, sem aplicar nada. Trava ocupada: o pedido fica para o próximo ciclo.
 */
final class ExecutorDePedido
{
    /** @var \Closure(): int */
    private readonly \Closure $relogio;

    /**
     * @param \Closure(RegistroDeProgresso): Atualizador $atualizador
     * @param \Closure(RegistroDeProgresso): Reversor $reversor
     * @param \Closure(string, int, array<string, mixed>): void $auditar ação, id de quem pediu, detalhe
     * @param (\Closure(): int)|null $relogio só para os testes
     */
    public function __construct(
        private readonly Caminhos $caminhos,
        private readonly PedidoDeAtualizacao $pedidos,
        private readonly \Closure $atualizador,
        private readonly \Closure $reversor,
        private readonly \Closure $auditar,
        ?\Closure $relogio = null,
    ) {
        $this->relogio = $relogio ?? static fn (): int => time();
    }

    /** O executor de produção: HTTPS, chaves embutidas, versão do código em execução. */
    public static function padrao(Servicos $servicos): self
    {
        $c = $servicos->caminhos;

        return new self(
            $c,
            new PedidoDeAtualizacao($c),
            static fn (RegistroDeProgresso $p): Atualizador => new Atualizador($c, new BaixadorHttps(), progresso: $p),
            static fn (RegistroDeProgresso $p): Reversor => new Reversor($c, progresso: $p),
            static function (string $acao, int $usuarioId, array $detalhe) use ($servicos): void {
                $servicos->auditoria->registrar($acao, 'cron', $usuarioId, null, $detalhe);
            },
        );
    }

    public function temTrabalho(): bool
    {
        return $this->pedidos->existe();
    }

    /**
     * @return string o que foi feito, numa linha
     * @throws ErroDeAtualizacao se o pedido foi recusado ou a operação falhou (o motivo já está em `progresso.json`)
     */
    public function executar(): string
    {
        self::precarregar();
        $progresso = new ProgressoEmArquivo($this->caminhos, 'cron', $this->relogio);
        try {
            Trava::soltar(Trava::obter($this->caminhos));
        } catch (ErroDeAtualizacao) {
            return 'Há outra atualização em andamento; o pedido espera o próximo ciclo.';
        }
        $pedido = $this->pedidos->ler();
        if ($pedido === null) {
            $this->recusar($progresso, 'atualizar', 'O pedido agendado estava ilegível ou fora do formato e foi descartado. Peça de novo pelo painel.');
        }
        if ($pedido->emAndamento()) {
            $this->recusar($progresso, $pedido->tipo, 'O pedido agendado ficou "em andamento" sem ninguém executando (o processo pode ter sido interrompido). Confira o site e peça de novo.');
        }
        if ($pedido->vencido(($this->relogio)())) {
            $this->recusar($progresso, $pedido->tipo, 'O pedido venceu antes de o cron executá-lo (vale ' . intdiv(PedidoAgendado::VALIDADE, 60) . ' minutos). Peça de novo pelo painel.');
        }
        $pedido = $this->pedidos->iniciar($pedido);
        if ($pedido === null) {
            return 'O pedido foi cancelado ou trocado antes de começar.';
        }
        try {
            $resumo = $pedido->tipo === 'reverter' ? $this->reverter($pedido, $progresso) : $this->atualizar($pedido, $progresso);
        } catch (\Throwable $e) {
            $progresso->falhar($e->getMessage());
            $this->auditar('atualizacao.agendada.falhou', $pedido->porId, ['tipo' => $pedido->tipo, 'para' => $pedido->versaoAlvo]);
            $this->pedidos->consumir();
            throw $e instanceof ErroDeAtualizacao ? $e : new ErroDeAtualizacao(SaneadorDeMensagem::sanear($e->getMessage(), $this->caminhos->raiz), 0, $e);
        }
        $this->auditar('atualizacao.agendada.executar', $pedido->porId, ['tipo' => $pedido->tipo, 'para' => $pedido->versaoAlvo]);
        $this->pedidos->consumir();

        return $resumo;
    }

    private function atualizar(PedidoAgendado $pedido, RegistroDeProgresso $progresso): string
    {
        $c = Configuracao::ler($this->caminhos);
        if ($c->url === '') {
            throw new ErroDeAtualizacao('Nenhum endereço de atualização configurado em dados/config/atualizacao.json.');
        }
        $progresso->iniciar('atualizar');
        $atualizador = ($this->atualizador)($progresso);
        $v = $atualizador->verificar($c->url, $c->canal); // refaz a assinatura do manifesto, o canal e a versão
        if (!$v->disponivel || $v->manifesto === null) {
            throw new ErroDeAtualizacao($v->motivo);
        }
        if (!hash_equals($pedido->versaoAlvo, $v->manifesto->versao)) {
            throw new ErroDeAtualizacao("A versão disponível mudou de {$pedido->versaoAlvo} para {$v->manifesto->versao} depois do pedido; nada foi aplicado. Leia as mudanças e peça de novo.");
        }
        $r = $atualizador->aplicar($v, $c->url); // refaz tamanho, SHA-256 e a conferência de cada arquivo

        return "Atualizado de {$r->de} para {$r->para}.";
    }

    private function reverter(PedidoAgendado $pedido, RegistroDeProgresso $progresso): string
    {
        $progresso->iniciar('reverter');
        $reversor = ($this->reversor)($progresso);
        $a = $reversor->avaliar();
        if (!$a->possivel) {
            throw new ErroDeAtualizacao($a->motivo);
        }
        if (!hash_equals($pedido->versaoAlvo, $a->de)) {
            throw new ErroDeAtualizacao("A versão para a qual voltar mudou de {$pedido->versaoAlvo} para {$a->de} depois do pedido; nada foi alterado. Peça de novo.");
        }
        $r = $reversor->reverter('cron', $pedido->porId);

        return "Revertido de {$r->de} para {$r->para}.";
    }

    /** @return never */
    private function recusar(ProgressoEmArquivo $progresso, string $tipo, string $motivo): void
    {
        $progresso->iniciar($tipo);
        $progresso->falhar($motivo);
        $this->pedidos->consumir();
        throw new ErroDeAtualizacao($motivo);
    }

    /** @param array<string, mixed> $detalhe */
    private function auditar(string $acao, int $usuarioId, array $detalhe): void
    {
        try {
            ($this->auditar)($acao, $usuarioId, $detalhe);
        } catch (\Throwable) {
            // a auditoria não pode desfazer nem esconder o que o cron já fez
        }
    }

    /** Carrega agora o que será usado depois da troca de código (o autoload passa a apontar para outra versão). */
    private static function precarregar(): void
    {
        foreach ([ErroDeAtualizacao::class, PedidoAgendado::class, PedidoDeAtualizacao::class, ProgressoEmArquivo::class, Progresso::class, ArquivoAtomico::class, SaneadorDeMensagem::class, Trava::class, Etapa::class, Auditoria::class, Aplicada::class, Revertida::class] as $classe) {
            class_exists($classe) || enum_exists($classe);
        }
    }
}
