<?php

declare(strict_types=1);

namespace Pbl\Cron;

use Pbl\Servicos;

/**
 * Uma tarefa do cron central (ADR 0023). O executor só chama {@see executar} depois de a frequência vencer **e** de
 * {@see temTrabalho} dizer que há o que fazer: tarefa sem trabalho custa uma consulta barata, não uma execução.
 */
interface Tarefa
{
    /** Identificador estável (`[a-z0-9-]`), usado no estado em disco, na linha de comando e na tela. */
    public function id(): string;

    public function nome(): string;

    /** Uma frase para a tela "Tarefas agendadas". */
    public function descricao(): string;

    /** Segundos entre uma verificação e a seguinte. */
    public function intervalo(): int;

    /** Pode rodar no "pseudo-cron" (nos acessos ao painel)? Só tarefas curtas e sem rede. */
    public function leve(): bool;

    /** Há trabalho a fazer agora? Deve ser barato e não gravar nada. */
    public function temTrabalho(Servicos $servicos): bool;

    /**
     * Executa. `$prazo` é um instante (`microtime(true)`) que a tarefa não deve ultrapassar: ao se aproximar dele, para e devolve
     * um resultado `parcial` (o executor repete na próxima rodada). Exceção = a tarefa falhou.
     */
    public function executar(Servicos $servicos, float $prazo): Resultado;
}
