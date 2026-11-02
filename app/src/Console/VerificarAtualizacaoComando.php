<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Atualizacao\Atualizador;
use Pbl\Atualizacao\Baixador;
use Pbl\Atualizacao\BaixadorHttps;
use Pbl\Atualizacao\Chaves;
use Pbl\Atualizacao\Configuracao;
use Pbl\Atualizacao\ErroDeAtualizacao;
use Pbl\Atualizacao\EstadoDoPainel;
use Pbl\Atualizacao\Verificacao;
use Pbl\Caminhos;
use Pbl\Versao;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Para o cron (ADR 0006, fluxo 11): atualiza o cache de 24 h que a página inicial do painel e a tela "Atualização" leem — nenhuma
 * das duas faz consulta de rede ao renderizar. **Idempotente**: se o cache ainda vale, não consulta nada (mesma regra de
 * `EstadoDoPainel::verificacaoRecente`); **silencioso quando não há versão nova**, para não poluir o log do cron. Sem endereço
 * configurado, verificação desligada ou sem chave de assinatura embutida (`Chaves::PUBLICAS` vazia): não consulta nada.
 */
#[AsCommand(name: 'verificar-atualizacao', description: 'Atualiza o cache de 24h da verificação de versão nova (para cron); idempotente e silencioso se nada mudou')]
final class VerificarAtualizacaoComando extends Command
{
    /**
     * Os parâmetros opcionais existem para os testes (baixador local, chave de teste, versão e relógio falsos); em produção
     * valem os padrões: HTTPS, as chaves embutidas em `Chaves::PUBLICAS` e a versão do produto.
     *
     * @param list<string>|null $chaves
     * @param (\Closure(): int)|null $relogio
     */
    public function __construct(
        private readonly Caminhos $caminhos,
        private readonly ?Baixador $baixador = null,
        private readonly ?array $chaves = null,
        private readonly string $versaoInstalada = Versao::ATUAL,
        private readonly ?\Closure $relogio = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $c = Configuracao::ler($this->caminhos);
        $chaves = $this->chaves ?? Chaves::PUBLICAS;
        if ($c->url === '' || !$c->verificar || $chaves === []) {
            return Command::SUCCESS;
        }
        $estado = new EstadoDoPainel($this->caminhos, $this->relogio);
        if ($estado->verificacaoRecente($c->url, $c->canal, $this->versaoInstalada) !== null) {
            return Command::SUCCESS; // cache ainda vale: nada a fazer
        }
        $atualizador = new Atualizador($this->caminhos, $this->baixador ?? new BaixadorHttps(), $chaves, $this->versaoInstalada);
        try {
            $v = $atualizador->verificar($c->url, $c->canal);
        } catch (ErroDeAtualizacao $e) {
            $this->guardar($estado, $c, null, $e->getMessage());
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        } catch (\Throwable) {
            $erro = 'Não foi possível consultar o canal de atualizações.';
            $this->guardar($estado, $c, null, $erro);
            $output->writeln('<error>' . $erro . '</error>');

            return Command::FAILURE;
        }
        $this->guardar($estado, $c, $v, '');
        if ($v->disponivel && $v->manifesto !== null) {
            $output->writeln("Nova versão disponível: {$v->manifesto->versao}." . ($v->manifesto->seguranca ? ' Inclui correção de segurança.' : ''));
        }
        // sem versão nova: nada escrito na saída (silencioso para o cron)

        return Command::SUCCESS;
    }

    private function guardar(EstadoDoPainel $estado, Configuracao $c, ?Verificacao $v, string $erro): void
    {
        try {
            $estado->guardarVerificacao($c->url, $c->canal, $this->versaoInstalada, $v, $erro);
        } catch (ErroDeAtualizacao) {
            // sem permissão em dados/: a próxima execução do cron tenta de novo
        }
    }
}
