<?php

declare(strict_types=1);

namespace Pbl\Cron\Tarefas;

use Pbl\Cron\ComProgramacao;
use Pbl\Cron\Resultado;
use Pbl\Cron\Tarefa;
use Pbl\Demo\AgendaDaDemo;
use Pbl\Demo\ConfigDaDemo;
use Pbl\Demo\ReiniciadorDaDemo;
use Pbl\Servicos;

/**
 * Reinicia a demonstração na hora marcada (padrão: a cada 7 dias, às 04:00 de Brasília). Só entra no catálogo em instalação de demo
 * (`Catalogo::padrao`) e, por garantia, recusa-se a rodar fora dela. "Tem trabalho?": a demo está ligada e a hora chegou. O botão
 * "Executar agora" da tela não força: só o comando `bin/pbl demo-resetar --agora` reinicia fora de hora.
 */
final class ReinicioDaDemoTarefa implements Tarefa, ComProgramacao
{
    public function id(): string
    {
        return 'demo-reset';
    }

    public function nome(): string
    {
        return 'Reinício da demonstração';
    }

    public function descricao(): string
    {
        return 'Apaga as alterações dos visitantes e recria o conteúdo de exemplo, as contas e os arquivos da demo (faz backup antes; o site fica em manutenção por alguns instantes).';
    }

    public function intervalo(): int
    {
        return 60; // de quanto em quanto tempo se confere (barato: um arquivo) se a hora chegou; o horário em si vem da agenda
    }

    public function leve(): bool
    {
        return false; // apaga e recria tudo: só no cron de verdade
    }

    public function temTrabalho(Servicos $servicos): bool
    {
        return ConfigDaDemo::ativa($servicos->caminhos) && (new AgendaDaDemo($servicos->caminhos))->vencido();
    }

    public function executar(Servicos $servicos, float $prazo): Resultado
    {
        // A conexão desta rodada aponta para o banco que vai ser apagado; as tarefas seguintes (se houver) reabrem a conexão.
        $servicos->conexao()->close();
        $passos = (new ReiniciadorDaDemo($servicos->caminhos))->reiniciar();

        return new Resultado('Demo reiniciada (' . count($passos) . ' passos). Próximo: ' . AgendaDaDemo::rotulo((new AgendaDaDemo($servicos->caminhos))->proximo()));
    }

    public function frequenciaTexto(Servicos $servicos): string
    {
        return 'a cada ' . ConfigDaDemo::dias($servicos->caminhos) . ' dia(s), às ' . ConfigDaDemo::hora($servicos->caminhos) . ' (Brasília)';
    }

    public function proximaExecucao(Servicos $servicos): int
    {
        return (new AgendaDaDemo($servicos->caminhos))->proximo();
    }
}
