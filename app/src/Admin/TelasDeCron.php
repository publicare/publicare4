<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Acesso\Usuario;
use Pbl\Cron\Catalogo;
use Pbl\Cron\EstadoDoCron;
use Pbl\Cron\Executor;
use Pbl\Cron\Tarefa;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tela "Tarefas agendadas" (ADR 0023): o catálogo do cron central com a última e a próxima execução, o resultado e o botão
 * "Executar agora"; o aviso de que o cron não roda; e o interruptor do pseudo-cron. Só o administrador geral
 * (`podeAdministrarContas`), conferido em toda rota; todo POST confere origem e CSRF antes de qualquer outra coisa.
 */
final class TelasDeCron
{
    use AuxiliaresDeTela;

    /** Orçamento (segundos) de "Executar agora": cabe numa requisição web. */
    private const ORCAMENTO_MANUAL = 25.0;

    private readonly EstadoDoCron $estado;

    /**
     * @param list<Tarefa>|null $tarefas só para os testes
     * @param (\Closure(): int)|null $relogio só para os testes
     */
    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
        private readonly ?array $tarefas = null,
        ?\Closure $relogio = null,
    ) {
        $this->estado = new EstadoDoCron($servicos->caminhos, $relogio);
    }

    /** @return string|null `ver`, `executar` ou `pseudo`; null = o `Painel` responde 404 */
    public static function rota(string $prefixo, string $caminho, string $metodo): ?string
    {
        return match (true) {
            $caminho === $prefixo . '/tarefas' && $metodo === 'GET' => 'ver',
            $caminho === $prefixo . '/tarefas/executar' && $metodo === 'POST' => 'executar',
            $caminho === $prefixo . '/tarefas/pseudo' && $metodo === 'POST' => 'pseudo',
            default => null,
        };
    }

    /**
     * Aviso da página inicial (só administrador): o cron não roda há mais de 5 minutos e o pseudo-cron não está cobrindo.
     * Nunca lança: uma falha aqui não derruba a tela inicial.
     *
     * @param (\Closure(): int)|null $relogio só para os testes
     */
    public static function avisoDaHome(Servicos $servicos, Usuario $usuario, ?\Closure $relogio = null): string
    {
        try {
            if (!$servicos->permissoes->podeAdministrarContas($usuario)) {
                return '';
            }
            $estado = new EstadoDoCron($servicos->caminhos, $relogio);
            if (!$estado->cronAtrasado() || $estado->pseudoLigado()) {
                return '';
            }
            $ultimo = $estado->ultimoCiclo('cron');
            $texto = $ultimo === null
                ? 'O cron do servidor nunca rodou: publicação e saída por data, republicação e limpezas não acontecem sozinhas.'
                : 'O cron do servidor não roda há mais de ' . max(5, intdiv($estado->agora() - $ultimo, 60)) . ' minutos: publicação e saída por data, republicação e limpezas estão paradas.';

            return '<div class="alert alert-warning" role="alert">' . Pagina::h($texto) . ' <a href="' . Painel::PREFIXO . '/tarefas">Ver como configurar</a>.</div>';
        } catch (\Throwable) {
            return '';
        }
    }

    public function tratar(SessaoAtiva $sessao, string $tela, Request $requisicao): Response
    {
        $post = $requisicao->getMethod() === 'POST';
        if ($post && !$this->postConfere($sessao, $requisicao)) {
            return $this->recusado();
        }
        $u = $sessao->usuario;
        if (!$this->servicos->permissoes->podeAdministrarContas($u)) {
            if ($post) {
                $this->servicos->auditoria->registrar('cron.negado', 'usuario:' . $u->id, $u->id);
            }

            return Pagina::resposta('Sem acesso', '<div class="alert alert-warning" role="alert">Só administradores podem ver e rodar as tarefas agendadas.</div>', Response::HTTP_FORBIDDEN, false, $this->barra($sessao));
        }

        return match ($tela) {
            'executar' => $this->executar($sessao, $requisicao),
            'pseudo' => $this->pseudo($sessao, $requisicao),
            default => $this->pagina($sessao, ''),
        };
    }

    private function executar(SessaoAtiva $sessao, Request $requisicao): Response
    {
        $id = $this->texto($requisicao, 'tarefa');
        $tarefas = $this->tarefas ?? Catalogo::padrao();
        $tarefa = $id === null ? null : Catalogo::porId($tarefas, $id);
        if ($tarefa === null) {
            return $this->pagina($sessao, Pagina::alerta('danger', 'Tarefa desconhecida.'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $r = (new Executor($this->servicos->caminhos, $this->servicos, $this->estado, $tarefas))->rodar('manual', self::ORCAMENTO_MANUAL, [$tarefa->id()], true);
        $this->servicos->auditoria->registrar('cron.executar', 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, null, ['tarefa' => $tarefa->id()]);
        if ($r['ocupado']) {
            $aviso = Pagina::alerta('warning', 'Outra rodada das tarefas está em andamento. Tente de novo em instantes.');
        } elseif ($r['linhas'] === []) {
            $aviso = Pagina::alerta('info', $tarefa->nome() . ': nada a fazer agora.');
        } else {
            $l = $r['linhas'][0];
            $aviso = $l['resultado'] === EstadoDoCron::ERRO
                ? Pagina::alerta('danger', $tarefa->nome() . ': falhou. ' . $l['resumo'])
                : Pagina::alerta('success', $tarefa->nome() . ': ' . $l['resumo']);
        }

        return $this->pagina($sessao, $aviso);
    }

    private function pseudo(SessaoAtiva $sessao, Request $requisicao): Response
    {
        if ($this->estado->pseudoPorAmbiente()) {
            return $this->pagina($sessao, Pagina::alerta('warning', 'O pseudo-cron está fixado pelo servidor (variável PBL_PSEUDO_CRON) e não pode ser mudado aqui.'), Response::HTTP_CONFLICT);
        }
        $ligar = $this->texto($requisicao, 'ligar') === '1';
        $this->estado->definirPseudo($ligar);
        $this->servicos->auditoria->registrar('cron.pseudo', 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, null, ['ligado' => $ligar]);

        return $this->pagina($sessao, Pagina::alerta('success', $ligar ? 'Pseudo-cron ligado.' : 'Pseudo-cron desligado.'));
    }

    private function pagina(SessaoAtiva $sessao, string $aviso, int $status = Response::HTTP_OK): Response
    {
        $agora = $this->estado->agora();
        $linhas = '';
        foreach ($this->tarefas ?? Catalogo::padrao() as $t) {
            $e = $this->estado->tarefa($t->id());
            $proxima = $e['verificada_em'] > 0 ? max($agora, $e['verificada_em'] + $t->intervalo()) : $agora;
            $badge = match ($e['resultado']) {
                EstadoDoCron::OK => '<span class="badge text-bg-success">ok</span>',
                EstadoDoCron::PARCIAL => '<span class="badge text-bg-info">em andamento</span>',
                EstadoDoCron::ERRO => '<span class="badge text-bg-danger">erro</span>',
                default => '<span class="badge text-bg-secondary">ainda sem trabalho</span>',
            };
            $linhas .= '<tr><td><strong>' . Pagina::h($t->nome()) . '</strong><div class="form-text mt-0">' . Pagina::h($t->descricao()) . '</div></td>'
                . '<td>' . Pagina::h(self::frequencia($t->intervalo())) . '</td>'
                . '<td>' . ($e['executada_em'] > 0 ? self::data($e['executada_em']) : 'nunca') . '</td>'
                . '<td>' . ($e['verificada_em'] > 0 ? self::data($proxima) : 'na próxima rodada') . '</td>'
                . '<td>' . $badge . ($e['resumo'] === '' ? '' : '<div class="form-text mt-0">' . Pagina::h($e['resumo']) . ($e['duracao_ms'] > 0 ? ' (' . $e['duracao_ms'] . ' ms)' : '') . '</div>') . '</td>'
                . '<td><form method="post" action="' . Painel::PREFIXO . '/tarefas/executar">' . $this->campoCsrf($sessao)
                . '<input type="hidden" name="tarefa" value="' . Pagina::h($t->id()) . '"><button class="btn btn-sm btn-outline-primary" type="submit">Executar agora</button></form></td></tr>';
        }
        $tabela = '<div class="table-responsive"><table class="table align-middle"><caption class="visually-hidden">Tarefas agendadas</caption>'
            . '<thead><tr><th scope="col">Tarefa</th><th scope="col">Frequência</th><th scope="col">Última execução</th><th scope="col">Próxima</th><th scope="col">Resultado</th><th scope="col"><span class="visually-hidden">Ação</span></th></tr></thead>'
            . '<tbody>' . $linhas . '</tbody></table></div>';

        return Pagina::resposta('Tarefas agendadas', $aviso . $this->situacaoDoCron() . $tabela . $this->cartaoPseudo($sessao) . $this->cartaoDeConfiguracao(), $status, false, $this->barra($sessao), [], 'O que o PBL4 faz sozinho, de tempos em tempos: publicar por data, republicar, limpar e conferir atualizações.');
    }

    private function situacaoDoCron(): string
    {
        $ultimo = $this->estado->ultimoCiclo('cron');
        if ($ultimo !== null && !$this->estado->cronAtrasado()) {
            return Pagina::alerta('success', 'O cron do servidor está rodando (última rodada: ' . self::data($ultimo) . ').');
        }
        if ($this->estado->pseudoLigado()) {
            return Pagina::alerta('info', 'O cron do servidor não está rodando; o pseudo-cron cobre as tarefas leves enquanto alguém usa o painel.');
        }

        return Pagina::alerta('warning', ($ultimo === null ? 'O cron do servidor nunca rodou. ' : 'O cron do servidor não roda desde ' . self::data($ultimo) . '. ')
            . 'Configure a linha abaixo no servidor (ou, sem acesso ao cron, ligue o pseudo-cron).');
    }

    private function cartaoPseudo(SessaoAtiva $sessao): string
    {
        $fixo = $this->estado->pseudoPorAmbiente();
        $ligado = $this->estado->pseudoLigado();
        $form = $fixo
            ? '<p class="mb-0">Fixado pelo servidor (PBL_PSEUDO_CRON): <strong>' . ($ligado ? 'ligado' : 'desligado') . '</strong>.</p>'
            : '<form method="post" action="' . Painel::PREFIXO . '/tarefas/pseudo">' . $this->campoCsrf($sessao)
                . '<input type="hidden" name="ligar" value="' . ($ligado ? '0' : '1') . '"><p>Estado: <strong>' . ($ligado ? 'ligado' : 'desligado') . '</strong>.</p>'
                . '<button class="btn btn-outline-secondary" type="submit">' . ($ligado ? 'Desligar' : 'Ligar') . ' o pseudo-cron</button></form>';

        return $this->card('Pseudo-cron (hospedagem sem cron)', $form, 'Quando ligado e o cron do servidor está parado, um acesso ao painel roda as tarefas leves (sem rede, sem varrer arquivos), com limite de poucos segundos. Não roda nas páginas públicas. O cron de verdade é sempre melhor: use só se o servidor não tiver cron.', 'section');
    }

    private function cartaoDeConfiguracao(): string
    {
        $raiz = $this->servicos->caminhos->raiz;
        $linha = '* * * * * php ' . $raiz . '/bin/pbl cron >/dev/null 2>&1';
        $corpo = '<p class="mb-1">Uma única linha, a cada minuto (Hestia, cPanel e crontab comum):</p><pre class="bg-body-secondary p-2 rounded"><code>' . Pagina::h($linha) . '</code></pre>'
            . '<p class="mb-1">Em Docker: <code>docker compose exec -T app php bin/pbl cron</code> (a cada minuto, no cron do host). '
            . 'Para conferir: <code>php bin/pbl cron --listar</code> mostra cada tarefa e a última execução, e esta página passa a mostrar "O cron do servidor está rodando".</p>'
            . '<p class="form-text mb-0">O comando é silencioso quando não há nada a fazer e não sobrepõe duas rodadas. As linhas antigas de <code>bin/pbl agendados</code>, <code>bin/pbl publicar</code> e <code>bin/pbl verificar-atualizacao</code> podem ser removidas.</p>';

        return $this->card('Como configurar o cron', $corpo, '', 'section');
    }

    private static function data(int $t): string
    {
        return gmdate('d/m/Y H:i:s', $t) . ' UTC';
    }

    private static function frequencia(int $s): string
    {
        return match (true) {
            $s >= 86400 => 'a cada ' . intdiv($s, 86400) . ' dia(s)',
            $s >= 3600 => 'a cada ' . intdiv($s, 3600) . ' h',
            default => 'a cada ' . max(1, intdiv($s, 60)) . ' min',
        };
    }
}
