<?php

declare(strict_types=1);

namespace Pbl\Cron\Tarefas;

use Pbl\Arquivos\ArmazenamentoDeArquivos;
use Pbl\Arquivos\LimpadorDeUploads;
use Pbl\Cron\Resultado;
use Pbl\Cron\Tarefa;
use Pbl\Servicos;

/** Arquivos enviados que nenhum objeto (nem versão antiga) referencia mais (`bin/pbl limpar-uploads`, ADR 0014), uma vez por dia. */
final class UploadsOrfaosTarefa implements Tarefa
{
    public function id(): string
    {
        return 'uploads-orfaos';
    }

    public function nome(): string
    {
        return 'Limpeza de arquivos órfãos';
    }

    public function descricao(): string
    {
        return 'Apaga arquivos enviados que nenhum conteúdo, nem versão antiga, usa mais.';
    }

    public function intervalo(): int
    {
        return 86400;
    }

    public function leve(): bool
    {
        return false; // varre todos os objetos e versões: só no cron de verdade
    }

    public function temTrabalho(Servicos $servicos): bool
    {
        // A varredura É o trabalho: simulá-la aqui dobraria o custo da tarefa mais pesada. Roda no máximo uma vez por dia e só no cron real.
        return true;
    }

    public function executar(Servicos $servicos, float $prazo): Resultado
    {
        $r = $this->limpador($servicos)->executar(false);
        $mb = round($r['bytes'] / (1024 * 1024), 2);

        return new Resultado("{$r['apagados']} arquivo(s) órfão(s) apagado(s) ({$mb} MB liberados)");
    }

    private function limpador(Servicos $servicos): LimpadorDeUploads
    {
        return new LimpadorDeUploads($servicos->conexao(), $servicos->classes, $servicos->arquivos, new ArmazenamentoDeArquivos($servicos->caminhos));
    }
}
