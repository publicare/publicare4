<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Acesso\Usuario;
use Pbl\Cron\Catalogo;
use Pbl\Cron\ComProgramacao;
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
                ? 'O cron nunca rodou: publicação por data, republicação e limpezas não acontecem.'
                : 'O cron não roda há mais de ' . max(5, intdiv($estado->agora() - $ultimo, 60)) . ' minutos: publicação por data, republicação e limpezas estão paradas.';

            return '<div class="alert alert-warning" role="alert">' . Pagina::h($texto) . ' <a href="' . Painel::PREFIXO . '/tarefas">Configurar</a>.</div>';
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
        $tarefas = $this->tarefas ?? Catalogo::padrao($this->servicos->caminhos);
        $tarefa = $id === null ? null : Catalogo::porId($tarefas, $id);
        if ($tarefa === null) {
            return $this->pagina($sessao, Pagina::alerta('danger', 'Tarefa desconhecida.'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $r = (new Executor($this->servicos->caminhos, $this->servicos, $this->estado, $tarefas))->rodar('manual', self::ORCAMENTO_MANUAL, [$tarefa->id()], true);
        $this->servicos->auditoria->registrar('cron.executar', 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, null, ['tarefa' => $tarefa->id()]);
        if ($r['ocupado']) {
            $aviso = Pagina::alerta('warning', 'Outra rodada está em andamento. Tente de novo em instantes.');
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
            return $this->pagina($sessao, Pagina::alerta('warning', 'O servidor fixa o pseudo-cron (PBL_PSEUDO_CRON). Não dá para mudar aqui.'), Response::HTTP_CONFLICT);
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
        foreach ($this->tarefas ?? Catalogo::padrao($this->servicos->caminhos) as $t) {
            $e = $this->estado->tarefa($t->id());
            $programada = $t instanceof ComProgramacao;
            $proxima = $programada ? $t->proximaExecucao($this->servicos) : ($e['verificada_em'] > 0 ? max($agora, $e['verificada_em'] + $t->intervalo()) : $agora);
            $badge = match ($e['resultado']) {
                EstadoDoCron::OK => '<span class="badge text-bg-success">ok</span>',
                EstadoDoCron::PARCIAL => '<span class="badge text-bg-info">em andamento</span>',
                EstadoDoCron::ERRO => '<span class="badge text-bg-danger">erro</span>',
                default => '<span class="badge text-bg-secondary">ainda sem trabalho</span>',
            };
            $linhas .= '<tr><td><strong>' . Pagina::h($t->nome()) . '</strong><div class="form-text mt-0">' . Pagina::h($t->descricao()) . '</div></td>'
                . '<td>' . Pagina::h($t instanceof ComProgramacao ? $t->frequenciaTexto($this->servicos) : self::frequencia($t->intervalo())) . '</td>'
                . '<td>' . ($e['executada_em'] > 0 ? self::data($e['executada_em']) : 'nunca') . '</td>'
                . '<td>' . ($programada || $e['verificada_em'] > 0 ? self::data($proxima) : 'na próxima rodada') . '</td>'
                . '<td>' . $badge . ($e['resumo'] === '' ? '' : '<div class="form-text mt-0">' . Pagina::h($e['resumo']) . ($e['duracao_ms'] > 0 ? ' (' . $e['duracao_ms'] . ' ms)' : '') . '</div>') . '</td>'
                . '<td><form method="post" action="' . Painel::PREFIXO . '/tarefas/executar">' . $this->campoCsrf($sessao)
                . '<input type="hidden" name="tarefa" value="' . Pagina::h($t->id()) . '">' . Pagina::botaoIcone('executar', 'Executar agora', 'btn-outline-primary', ['item' => $t->id()]) . '</form></td></tr>';
        }
        $tabela = '<div class="table-responsive"><table class="table align-middle"><caption class="visually-hidden">Tarefas agendadas</caption>'
            . '<thead><tr><th scope="col">Tarefa</th><th scope="col">Frequência</th><th scope="col">Última execução</th><th scope="col">Próxima</th><th scope="col">Resultado</th><th scope="col"><span class="visually-hidden">Ação</span></th></tr></thead>'
            . '<tbody>' . $linhas . '</tbody></table></div>';

        $atalhos = ['<a href="' . Painel::PREFIXO . '/copias">Cópias de segurança</a>', '<a href="' . Painel::PREFIXO . '/atualizacao">Atualização</a>'];
        $corpo = $aviso . $this->situacaoDoCron() . Pagina::cabecalho('', '', $atalhos)
            . '<h2 class="h5" id="titulo-tarefas">Tarefas</h2>' . $tabela
            . '<div class="pbl-recolhiveis">' . Pagina::recolhivel('pseudo-cron', 'Pseudo-cron (hospedagem sem cron)', $this->cartaoPseudo($sessao))
            . Pagina::recolhivel('configurar-cron', 'Como configurar o cron', $this->cartaoDeConfiguracao(), $this->estado->ultimoCiclo('cron') === null && !$this->estado->pseudoLigado()) . '</div>';

        return Pagina::resposta('Tarefas agendadas', $corpo, $status, false, $this->barra($sessao), [['Mais', Painel::PREFIXO . '/mais'], ['Tarefas agendadas', null]], 'O que roda sozinho: publicar por data, republicar, limpar, conferir atualizações.');
    }

    private function situacaoDoCron(): string
    {
        $ultimo = $this->estado->ultimoCiclo('cron');
        if ($ultimo !== null && !$this->estado->cronAtrasado()) {
            return Pagina::cartaoDeEstado('ok', 'O cron do servidor está rodando', 'Última rodada: ' . self::data($ultimo) . '.');
        }
        if ($this->estado->pseudoLigado()) {
            return Pagina::cartaoDeEstado('atencao', 'O cron do servidor está parado', 'O pseudo-cron cobre as tarefas leves enquanto alguém usa o painel.');
        }

        return Pagina::cartaoDeEstado('erro', $ultimo === null ? 'O cron nunca rodou' : 'O cron parou em ' . self::data($ultimo),
            'Configure a linha em "Como configurar o cron" ou ligue o pseudo-cron.');
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

        return $this->card('Pseudo-cron', $form, 'Só para hospedagem sem cron: um acesso ao painel roda as tarefas leves.', 'section');
    }

    private function cartaoDeConfiguracao(): string
    {
        $raiz = $this->servicos->caminhos->raiz;
        $linha = '* * * * * php ' . $raiz . '/bin/pbl cron >/dev/null 2>&1';
        $corpo = '<p class="mb-1">Uma linha, a cada minuto:</p><pre class="bg-body-secondary p-2 rounded"><code>' . Pagina::h($linha) . '</code></pre>'
            . '<p class="mb-1">Docker: <code>docker compose exec -T app php bin/pbl cron</code></p>'
            . '<details class="mt-2"><summary>Saiba mais</summary><p class="form-text mb-0">Confira com <code>php bin/pbl cron --listar</code>. Pode remover as linhas antigas de <code>bin/pbl agendados</code>, <code>publicar</code> e <code>verificar-atualizacao</code>.</p></details>';

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
