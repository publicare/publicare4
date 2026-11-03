<?php

declare(strict_types=1);

namespace Pbl\Cron\Tarefas;

use Pbl\Atualizacao\Chaves;
use Pbl\Atualizacao\Configuracao;
use Pbl\Atualizacao\EstadoDoPainel;
use Pbl\Console\VerificarAtualizacaoComando;
use Pbl\Cron\Resultado;
use Pbl\Cron\Tarefa;
use Pbl\Servicos;
use Pbl\Versao;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Só consulta: atualiza o cache de 24 h de "há versão nova?" que a página inicial e a tela "Atualização" leem (ADR 0006). Nunca
 * aplica nada. Reaproveita o comando `verificar-atualizacao`, que já cuida de rede, assinatura e falhas. "Tem trabalho?": a
 * verificação está ligada, há endereço e chave de confiança, e o cache venceu.
 */
final class VerificarAtualizacaoTarefa implements Tarefa
{
    public function __construct(private readonly ?VerificarAtualizacaoComando $comando = null)
    {
    }

    public function id(): string
    {
        return 'verificar-atualizacao';
    }

    public function nome(): string
    {
        return 'Verificação de atualização';
    }

    public function descricao(): string
    {
        return 'Consulta o canal de atualizações (uma vez por dia) para avisar no painel que há versão nova. Não aplica nada.';
    }

    public function intervalo(): int
    {
        return 3600;
    }

    public function leve(): bool
    {
        return false; // usa a rede
    }

    public function temTrabalho(Servicos $servicos): bool
    {
        $c = Configuracao::ler($servicos->caminhos);
        if ($c->url === '' || !$c->verificar || Chaves::semConfianca(Chaves::PUBLICAS, $c->canal)) {
            return false;
        }

        return (new EstadoDoPainel($servicos->caminhos))->verificacaoRecente($c->url, $c->canal, Versao::ATUAL) === null;
    }

    public function executar(Servicos $servicos, float $prazo): Resultado
    {
        $comando = $this->comando ?? new VerificarAtualizacaoComando($servicos->caminhos);
        $saida = new BufferedOutput();
        $codigo = $comando->run(new ArrayInput([]), $saida);
        $texto = trim(strip_tags($saida->fetch()));
        if ($codigo !== 0) {
            throw new \RuntimeException($texto === '' ? 'Não foi possível consultar o canal de atualizações.' : $texto);
        }

        return new Resultado($texto === '' ? 'Consultado: sem versão nova.' : $texto);
    }
}
