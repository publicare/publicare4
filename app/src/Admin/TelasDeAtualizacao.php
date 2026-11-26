<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Acesso\Usuario;
use Pbl\Atualizacao\Atualizador;
use Pbl\Atualizacao\Baixador;
use Pbl\Atualizacao\BaixadorHttps;
use Pbl\Atualizacao\Chaves;
use Pbl\Atualizacao\Configuracao;
use Pbl\Atualizacao\ErroDeAtualizacao;
use Pbl\Atualizacao\EstadoDoPainel;
use Pbl\Atualizacao\Lancador;
use Pbl\Atualizacao\LancadorDeProcesso;
use Pbl\Atualizacao\PedidoAgendado;
use Pbl\Atualizacao\PedidoDeAtualizacao;
use Pbl\Atualizacao\Reversor;
use Pbl\Atualizacao\SituacaoDaAtualizacao;
use Pbl\Atualizacao\Trava;
use Pbl\Servicos;
use Pbl\Versao;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tela "Atualização" (ADR 0012, fase 4c; ADR 0006): versão instalada, canal, consulta ao manifesto assinado (cache de 24 h, botão
 * "verificar agora") e "Atualizar agora". Só o administrador geral (`podeAdministrarContas`), conferido em toda rota, também no POST;
 * todo POST confere origem e CSRF antes de qualquer outra coisa.
 *
 * Quem atualiza é o MESMO `Atualizador` da linha de comando, e quem reverte é o MESMO `Reversor`: os botões só iniciam
 * `bin/pbl atualizar` ou `bin/pbl reverter --confirmar` em outro processo (`Lancador`), porque a troca demora e muda o código em
 * execução. A requisição web grava apenas em `dados/atualizacao-painel/` (`EstadoDoPainel`: cache, trava e resultado — uma
 * operação de cada vez, atualizar ou reverter); quem troca o código é o processo de segundo plano. Aplicar e reverter exigem a
 * senha de novo (com o limite de tentativas do login) e a confirmação marcada; reverter só aparece quando o `Reversor` confirma
 * que é seguro (versão instalada, código guardado e cópia do banco conferem com o registro da última atualização). A auditoria
 * guarda só as versões (validadas como SemVer) e o id de quem fez, nunca a senha nem o texto das mudanças.
 */
final class TelasDeAtualizacao
{
    use AuxiliaresDeTela;

    private readonly EstadoDoPainel $estado;
    private readonly PedidoDeAtualizacao $pedidos;
    private readonly SituacaoDaAtualizacao $situacao;
    private readonly Lancador $lancador;
    private readonly Baixador $baixador;
    /** @var list<string> */
    private readonly array $chaves;

    /** @var array<string, string> abas em link (`?aba=`), a primeira é a padrão */
    private const ABAS = ['atualizar' => 'Atualizar', 'historico' => 'Histórico e voltar atrás', 'configuracao' => 'Configuração'];

    /**
     * Os parâmetros opcionais existem para os testes (baixador local, chave de teste, versão e processo falsos); em produção valem
     * os padrões: HTTPS, as chaves embutidas em `Chaves::PUBLICAS`, a versão do produto e o processo real.
     *
     * @param list<string>|null $chaves
     * @param (\Closure(): int)|null $relogio
     */

    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
        ?Baixador $baixador = null,
        ?array $chaves = null,
        private readonly string $versaoInstalada = Versao::ATUAL,
        ?Lancador $lancador = null,
        ?\Closure $relogio = null,
        private readonly string $phpAtual = PHP_VERSION,
    ) {
        $this->estado = new EstadoDoPainel($servicos->caminhos, $relogio);
        $this->pedidos = new PedidoDeAtualizacao($servicos->caminhos, $relogio);
        $this->situacao = new SituacaoDaAtualizacao($servicos->caminhos, $relogio);
        $this->baixador = $baixador ?? new BaixadorHttps();
        $this->chaves = $chaves ?? Chaves::PUBLICAS;
        $this->lancador = $lancador ?? new LancadorDeProcesso($servicos->caminhos, LancadorDeProcesso::descobrirPhp(Configuracao::ler($servicos->caminhos)->php));
    }

    /**
     * Aviso de versão nova na página inicial do painel (`TelasDeConteudo::inicio`), só para o administrador geral. **Nunca
     * consulta a rede aqui**: só lê o cache de 24 h que a tela "Atualização" (ou `bin/pbl verificar-atualizacao`, pelo cron) já
     * gravou. Cache vencido ou inexistente: mostra "verificar" com link para a tela, que aí sim pode consultar. Sem endereço
     * configurado, verificação desligada ou build sem chave de assinatura embutida: nada aparece (não há o que avisar).
     *
     * @param list<string>|null $chaves só para os testes; em produção vale `Chaves::PUBLICAS`
     * @param (\Closure(): int)|null $relogio só para os testes
     */
    public static function avisoHomePage(Servicos $servicos, Usuario $usuario, string $versaoInstalada = Versao::ATUAL, ?array $chaves = null, ?\Closure $relogio = null): string
    {
        if (!$servicos->permissoes->podeAdministrarContas($usuario)) {
            return '';
        }
        $c = Configuracao::ler($servicos->caminhos);
        $chaves ??= Chaves::PUBLICAS;
        if ($c->url === '' || !$c->verificar || Chaves::semConfianca($chaves, $c->canal)) {
            return '';
        }
        $link = Painel::PREFIXO . '/atualizacao';
        $cache = (new EstadoDoPainel($servicos->caminhos, $relogio))->verificacaoRecente($c->url, $c->canal, $versaoInstalada);
        if ($cache === null || !$cache['ok']) {
            return '<div class="alert alert-secondary" role="status">Verificação de novidades vencida. <a href="' . $link . '">Verificar</a>.</div>';
        }
        if (!$cache['disponivel'] || $cache['manifesto'] === null) {
            return '';
        }
        $m = $cache['manifesto'];
        $seguranca = $m['seguranca'] ? ' <span class="badge text-bg-danger">Correção de segurança</span>' : '';

        return '<div class="alert alert-info" role="status">Nova versão disponível: <strong>' . Pagina::h($m['versao']) . '</strong>' . $seguranca
            . ' <a href="' . $link . '">Ver mudanças e atualizar</a>.</div>';
    }

    /** @return string|null `ver`, `verificar`, `aplicar`, `reverter`, `cancelar` ou `progresso`; null = o `Painel` responde 404 */
    public static function rota(string $prefixo, string $caminho, string $metodo): ?string
    {
        return match (true) {
            $caminho === $prefixo . '/atualizacao' && $metodo === 'GET' => 'ver',
            $caminho === $prefixo . '/atualizacao/verificar' && $metodo === 'POST' => 'verificar',
            $caminho === $prefixo . '/atualizacao/aplicar' && $metodo === 'POST' => 'aplicar',
            $caminho === $prefixo . '/atualizacao/reverter' && $metodo === 'POST' => 'reverter',
            $caminho === $prefixo . '/atualizacao/cancelar-agendamento' && $metodo === 'POST' => 'cancelar',
            $caminho === $prefixo . '/atualizacao/progresso' && $metodo === 'GET' => 'progresso',
            default => null,
        };
    }

    public function tratar(SessaoAtiva $sessao, string $tela, Request $requisicao): Response
    {
        $post = $requisicao->getMethod() === 'POST';
        if ($post && !$this->postConfere($sessao, $requisicao)) {
            return $this->recusado();
        }
        $u = $sessao->usuario;
        if (!$this->servicos->permissoes->podeAdministrarContas($u)) {
            if ($tela === 'progresso') {
                return $this->json(['erro' => 'Só administradores podem ver o andamento.'], Response::HTTP_FORBIDDEN);
            }
            if ($post) {
                $this->servicos->auditoria->registrar('atualizacao.negado', 'usuario:' . $u->id, $u->id);
            }

            return Pagina::resposta('Sem acesso', '<div class="alert alert-warning" role="alert">Só administradores podem ver e aplicar atualizações.</div>', Response::HTTP_FORBIDDEN, false, $this->barra($sessao));
        }

        return match ($tela) {
            'ver' => $this->pagina($sessao, $this->aviso($requisicao), null, Response::HTTP_OK, false, Pagina::abaDe($requisicao, 'atualizar', array_keys(self::ABAS))),
            'verificar' => $this->verificarAgora($sessao),
            'reverter' => $this->reverter($sessao, $requisicao),
            'cancelar' => $this->cancelar($sessao),
            'progresso' => $this->json($this->situacao->ler()),
            default => $this->aplicar($sessao, $requisicao),
        };
    }

    // ---------------------------------------------------------------- consulta

    /**
     * @return array{situacao: string, texto: string, quando: int, disponivel: bool, motivo: string, manifesto: array{versao: string, data: string, resumo: string, notas: string, seguranca: bool, migracoes: bool, php_minimo: string}|null}
     *         `situacao`: `sem_url`, `desligada`, `sem_chave`, `erro` ou `ok`
     */
    private function consultar(bool $forcar, bool $podeConsultar = true): array
    {
        $base = ['situacao' => 'ok', 'texto' => '', 'quando' => 0, 'disponivel' => false, 'motivo' => '', 'manifesto' => null];
        $c = Configuracao::ler($this->servicos->caminhos);
        if ($c->url === '') {
            return ['situacao' => 'sem_url'] + $base;
        }
        if (!$c->verificar) {
            return ['situacao' => 'desligada'] + $base;
        }
        if (Chaves::semConfianca($this->chaves, $c->canal)) {
            return ['situacao' => 'sem_chave'] + $base;
        }
        $cache = $forcar ? null : $this->estado->verificacaoRecente($c->url, $c->canal, $this->versaoInstalada);
        if ($cache === null && $podeConsultar) {
            return $this->consultarAgora($c);
        }
        if ($cache === null) {
            return ['situacao' => 'erro', 'texto' => 'Ainda não consultei o canal de atualizações.'] + $base;
        }
        if (!$cache['ok']) {
            return ['situacao' => 'erro', 'texto' => $cache['erro'], 'quando' => $cache['consultado_em']] + $base;
        }

        return ['situacao' => 'ok', 'quando' => $cache['consultado_em'], 'disponivel' => $cache['disponivel'], 'motivo' => $cache['motivo'], 'manifesto' => $cache['manifesto']] + $base;
    }

    /** @return array{situacao: string, texto: string, quando: int, disponivel: bool, motivo: string, manifesto: array{versao: string, data: string, resumo: string, notas: string, seguranca: bool, migracoes: bool, php_minimo: string}|null} */
    private function consultarAgora(Configuracao $c): array
    {
        try {
            $v = $this->atualizador()->verificar($c->url, $c->canal);
        } catch (ErroDeAtualizacao $e) {
            $this->guardarFalha($c, $e->getMessage());

            return ['situacao' => 'erro', 'texto' => $e->getMessage(), 'quando' => time(), 'disponivel' => false, 'motivo' => '', 'manifesto' => null];
        } catch (\Throwable) {
            $this->guardarFalha($c, 'Não foi possível consultar o canal de atualizações.');

            return ['situacao' => 'erro', 'texto' => 'Não foi possível consultar o canal de atualizações.', 'quando' => time(), 'disponivel' => false, 'motivo' => '', 'manifesto' => null];
        }
        try {
            $this->estado->guardarVerificacao($c->url, $c->canal, $this->versaoInstalada, $v);
        } catch (ErroDeAtualizacao) {
            // sem permissão em dados/: mostra o resultado mesmo assim, só não guarda o cache
        }
        $m = $v->manifesto;

        return [
            'situacao' => 'ok', 'texto' => '', 'quando' => time(), 'disponivel' => $v->disponivel, 'motivo' => $v->motivo,
            'manifesto' => $m === null ? null : ['versao' => $m->versao, 'data' => $m->data, 'resumo' => $m->resumo, 'notas' => $m->notas, 'seguranca' => $m->seguranca, 'migracoes' => $m->migracoes, 'php_minimo' => $m->phpMinimo],
        ];
    }

    private function guardarFalha(Configuracao $c, string $erro): void
    {
        try {
            $this->estado->guardarVerificacao($c->url, $c->canal, $this->versaoInstalada, null, $erro);
        } catch (ErroDeAtualizacao) {
        }
    }

    private function atualizador(): Atualizador
    {
        return new Atualizador($this->servicos->caminhos, $this->baixador, $this->chaves, $this->versaoInstalada, null, $this->phpAtual);
    }

    private function verificarAgora(SessaoAtiva $sessao): Response
    {
        if ($this->estado->situacao()['estado'] !== 'andamento') {
            $this->consultar(true);
            $this->servicos->auditoria->registrar('atualizacao.verificar', 'usuario:' . $sessao->usuario->id, $sessao->usuario->id);
        }

        return new RedirectResponse(Painel::PREFIXO . '/atualizacao?ok=verificado', Response::HTTP_SEE_OTHER);
    }

    // ---------------------------------------------------------------- aplicar

    private function aplicar(SessaoAtiva $sessao, Request $requisicao): Response
    {
        $senha = $this->texto($requisicao, 'senha');
        $versao = $this->texto($requisicao, 'versao');
        if ($senha === null || $versao === null) {
            return $this->incompleto($sessao);
        }
        $u = $sessao->usuario;
        if ($this->texto($requisicao, 'confirmar') !== '1') {
            return $this->pagina($sessao, '', 'Marque a confirmação para atualizar.', Response::HTTP_UNPROCESSABLE_ENTITY, true);
        }
        $c = Configuracao::ler($this->servicos->caminhos);
        if ($c->url === '' || !$c->verificar || Chaves::semConfianca($this->chaves, $c->canal)) {
            return $this->pagina($sessao, '', 'Atualizar pelo painel não está disponível: veja o Diagnóstico na aba Configuração.', Response::HTTP_CONFLICT, false);
        }
        $ocupada = $this->ocupada();
        if ($ocupada !== null) {
            return $this->pagina($sessao, '', $ocupada, Response::HTTP_CONFLICT, false);
        }

        // Reautenticação (ADR 0011, "modo elevado", fase 5h): o mesmo componente comum, sempre exigindo a senha de
        // novo (mesmo com o modo já elevado por outra ação), porque esta troca o código em execução.
        $ip = $this->rede->ip($requisicao);
        $falha = $this->servicos->modoElevado()->confirmar($sessao, $senha, $ip, 'atualizacao', true);
        if ($falha !== null) {
            $r = $this->pagina($sessao, '', $falha['mensagem'], $falha['status'], true);
            if ($falha['retryAfter'] !== null) {
                $r->headers->set('Retry-After', (string) $falha['retryAfter']);
            }

            return $r;
        }

        // O que o administrador viu pode ter mudado no canal: confere de novo, ao vivo, e só segue se for a mesma versão.
        $nova = $this->consultarAgora($c);
        if ($nova['situacao'] !== 'ok' || !$nova['disponivel'] || $nova['manifesto'] === null) {
            return $this->pagina($sessao, '', 'Não há atualização para aplicar agora: ' . ($nova['texto'] !== '' ? $nova['texto'] : $nova['motivo']), Response::HTTP_CONFLICT, false);
        }
        if ($nova['manifesto']['versao'] !== $versao) {
            return $this->pagina($sessao, '', 'A versão disponível mudou para ' . $nova['manifesto']['versao'] . '. Leia as mudanças e confirme de novo.', Response::HTTP_CONFLICT, false);
        }
        if (!is_writable($this->servicos->caminhos->raiz) || !extension_loaded('zip')) {
            return $this->pagina($sessao, '', 'Este servidor não deixa o PHP trocar o código da instalação (falta permissão de escrita na pasta do PBL4 ou a extensão zip). Atualize pelo terminal: php bin/pbl atualizar.', Response::HTTP_CONFLICT, false);
        }
        $agendar = $c->agendar && $this->texto($requisicao, 'modo') === 'agendar';
        $automatico = false;
        if (!$agendar) {
            try {
                $iniciou = $this->estado->iniciar($this->versaoInstalada, $versao, $this->lancador);
            } catch (ErroDeAtualizacao $e) {
                if (!$c->agendar) {
                    return $this->pagina($sessao, '', $e->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE, false);
                }
                // Hospedagem que não deixa o painel iniciar processos (proc_open desligado, sem PHP de linha de comando): vira pedido ao cron.
                $agendar = $automatico = true;
                $iniciou = false;
            }
            if (!$agendar && !$iniciou) {
                return $this->pagina($sessao, '', 'Já existe uma atualização em andamento. Espere terminar.', Response::HTTP_CONFLICT, false);
            }
        }
        if ($agendar) {
            return $this->agendarPedido($sessao, 'atualizar', $versao, $ip, $automatico);
        }
        $this->servicos->auditoria->registrar('atualizacao.iniciar', 'usuario:' . $u->id, $u->id, null, ['de' => $this->versaoInstalada, 'para' => $versao], $this->servicos->limiteDeTentativas()->hashDoIp($ip));

        return new RedirectResponse(Painel::PREFIXO . '/atualizacao?ok=iniciada', Response::HTTP_SEE_OTHER);
    }

    // ---------------------------------------------------------------- agendar / cancelar / andamento

    /** Algo já está agendado ou rodando (por qualquer origem)? Devolve a mensagem para o administrador, ou null. */
    private function ocupada(): ?string
    {
        if ($this->estado->situacao()['estado'] === 'andamento') {
            return 'Já existe uma atualização em andamento. Espere terminar.';
        }

        return match ($this->situacao->ler()['estado']) {
            'agendada' => 'Já existe uma atualização agendada para o cron. Espere ela rodar ou cancele o agendamento abaixo.',
            'em_andamento' => 'Já existe uma atualização em andamento. Espere terminar.',
            default => null,
        };
    }

    /**
     * Grava o pedido que o cron executa (`dados/atualizacao/pedido.json`). Só chega aqui depois de TODAS as exigências do botão
     * (senha, confirmação, CSRF, origem, nova consulta ao manifesto assinado com a mesma versão). A senha nunca vai para o pedido,
     * e o cron ainda reconfere assinatura, SHA-256 e versão antes de aplicar.
     *
     * @param string $tipo `atualizar` ou `reverter`
     */
    private function agendarPedido(SessaoAtiva $sessao, string $tipo, string $versao, string $ip, bool $automatico): Response
    {
        $u = $sessao->usuario;
        try {
            Trava::soltar(Trava::obter($this->servicos->caminhos)); // outra operação segurando a trava = em andamento
        } catch (ErroDeAtualizacao) {
            return $this->pagina($sessao, '', 'Já existe uma atualização em andamento. Espere terminar.', Response::HTTP_CONFLICT, false);
        }
        try {
            $this->pedidos->agendar($tipo, $versao, $u->id, $u->nome);
        } catch (ErroDeAtualizacao $e) {
            return $this->pagina($sessao, '', $e->getMessage(), Response::HTTP_CONFLICT, false);
        }
        $this->servicos->auditoria->registrar('atualizacao.agendar', 'usuario:' . $u->id, $u->id, null, ['tipo' => $tipo, 'para' => $versao], $this->servicos->limiteDeTentativas()->hashDoIp($ip));

        return new RedirectResponse(Painel::PREFIXO . '/atualizacao?ok=' . ($automatico ? 'agendada_sem_processo' : 'agendada'), Response::HTTP_SEE_OTHER);
    }

    /** Cancela o pedido que o cron ainda não começou. Não pede senha: só desfaz, nunca troca nada. */
    private function cancelar(SessaoAtiva $sessao): Response
    {
        try {
            $p = $this->pedidos->cancelar();
        } catch (ErroDeAtualizacao $e) {
            return $this->pagina($sessao, '', $e->getMessage(), Response::HTTP_CONFLICT, false);
        }
        $u = $sessao->usuario;
        $this->servicos->auditoria->registrar('atualizacao.cancelar_agendamento', 'usuario:' . $u->id, $u->id, null, ['tipo' => $p->tipo, 'para' => $p->versaoAlvo]);

        return new RedirectResponse(Painel::PREFIXO . '/atualizacao?ok=cancelada', Response::HTTP_SEE_OTHER);
    }

    /**
     * Resposta do endpoint de andamento: só a lista fixa de campos de {@see SituacaoDaAtualizacao}, sem cache.
     *
     * @param array<string, mixed> $dados
     */
    private function json(array $dados, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse($dados, $status, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    // ---------------------------------------------------------------- reverter

    private function reversor(): Reversor
    {
        return new Reversor($this->servicos->caminhos, $this->versaoInstalada);
    }

    /**
     * Voltar atrás depois de uma atualização concluída (ADR 0006): a mesma reautenticação (senha de novo, limite de tentativas)
     * e a mesma confirmação marcada de `aplicar`; dispara `bin/pbl reverter --confirmar` em segundo plano, pela mesma `Lancador` e
     * sob o mesmo `EstadoDoPainel` (uma operação de cada vez, atualizar ou reverter). Quem decide se é seguro é o `Reversor`
     * (registro da atualização, código guardado, cópia do banco, versão do esquema); aqui só se confirma a pessoa e a intenção.
     */
    private function reverter(SessaoAtiva $sessao, Request $requisicao): Response
    {
        $senha = $this->texto($requisicao, 'senha');
        if ($senha === null) {
            return $this->incompleto($sessao);
        }
        $u = $sessao->usuario;
        if ($this->texto($requisicao, 'confirmar') !== '1') {
            return $this->pagina($sessao, '', 'Marque a confirmação para voltar atrás.', Response::HTTP_UNPROCESSABLE_ENTITY, true, 'historico');
        }
        $ocupada = $this->ocupada();
        if ($ocupada !== null) {
            return $this->pagina($sessao, '', $ocupada, Response::HTTP_CONFLICT, false, 'historico');
        }
        $a = $this->reversor()->avaliar();
        if (!$a->possivel) {
            return $this->pagina($sessao, '', $a->motivo, Response::HTTP_CONFLICT, false, 'historico');
        }

        // Reautenticação (ADR 0011, "modo elevado", fase 5h): o mesmo componente comum de "aplicar", sempre exigindo
        // a senha de novo.
        $ip = $this->rede->ip($requisicao);
        $falha = $this->servicos->modoElevado()->confirmar($sessao, $senha, $ip, 'atualizacao', true);
        if ($falha !== null) {
            $r = $this->pagina($sessao, '', $falha['mensagem'], $falha['status'], true, 'historico');
            if ($falha['retryAfter'] !== null) {
                $r->headers->set('Retry-After', (string) $falha['retryAfter']);
            }

            return $r;
        }

        if (!is_writable($this->servicos->caminhos->raiz)) {
            return $this->pagina($sessao, '', 'Este servidor não deixa o PHP trocar o código da instalação (falta permissão de escrita na pasta do PBL4). Reverta pelo terminal: php bin/pbl reverter --confirmar.', Response::HTTP_CONFLICT, false, 'historico');
        }
        $agendar = Configuracao::ler($this->servicos->caminhos)->agendar && $this->texto($requisicao, 'modo') === 'agendar';
        $automatico = false;
        if (!$agendar) {
            try {
                $iniciou = $this->estado->iniciar($a->para, $a->de, $this->lancador, 'reverter');
            } catch (ErroDeAtualizacao $e) {
                if (!Configuracao::ler($this->servicos->caminhos)->agendar) {
                    return $this->pagina($sessao, '', $e->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE, false, 'historico');
                }
                $agendar = $automatico = true;
                $iniciou = false;
            }
            if (!$agendar && !$iniciou) {
                return $this->pagina($sessao, '', 'Já existe uma atualização em andamento. Espere terminar.', Response::HTTP_CONFLICT, false, 'historico');
            }
        }
        if ($agendar) {
            return $this->agendarPedido($sessao, 'reverter', $a->de, $ip, $automatico);
        }
        $this->servicos->auditoria->registrar('atualizacao.reverter_iniciar', 'usuario:' . $u->id, $u->id, null, ['de' => $a->para, 'para' => $a->de], $this->servicos->limiteDeTentativas()->hashDoIp($ip));

        return new RedirectResponse(Painel::PREFIXO . '/atualizacao?ok=reversao_iniciada', Response::HTTP_SEE_OTHER);
    }

    // ---------------------------------------------------------------- tela

    private function aviso(Request $requisicao): string
    {
        $texto = match ($requisicao->query->all()['ok'] ?? null) {
            'verificado' => 'Consulta feita agora.',
            'iniciada' => 'Atualização iniciada em segundo plano. O andamento aparece abaixo; durante a troca o site responde "em manutenção" por alguns instantes.',
            'reversao_iniciada' => 'Volta atrás iniciada em segundo plano. O andamento aparece abaixo; durante a troca o site responde "em manutenção" por alguns instantes.',
            'agendada' => 'Agendada: aguardando o próximo ciclo do cron (até 1 minuto). O andamento aparece abaixo.',
            'agendada_sem_processo' => 'Esta hospedagem não deixa o painel iniciar a atualização na hora; ela foi agendada para o próximo ciclo do cron (até 1 minuto). O andamento aparece abaixo.',
            'cancelada' => 'O agendamento foi cancelado. Nada foi alterado.',
            default => null,
        };

        return $texto === null ? '' : '<div class="alert alert-success" role="status">' . Pagina::h($texto) . '</div>';
    }

    private function pagina(SessaoAtiva $sessao, string $aviso, ?string $erro, int $status, bool $lembrarConfirmacao, string $aba = 'atualizar'): Response
    {
        $andamento = $this->situacaoAuditada($sessao);
        $sit = $this->situacao->ler();
        $ocupado = $andamento['estado'] === 'andamento' || in_array($sit['estado'], ['agendada', 'em_andamento'], true);
        $c = Configuracao::ler($this->servicos->caminhos);
        $k = $this->consultar(false, $andamento['estado'] !== 'andamento');
        $csrf = $this->campoCsrf($sessao);
        $base = Painel::PREFIXO . '/atualizacao';
        $podeAplicar = $k['situacao'] === 'ok' && $k['disponivel'] && $k['manifesto'] !== null && !$ocupado;

        $corpo = $aviso . $this->erro($erro)
            . $this->cabecalhoDeEstado($k, $c, $base, $csrf, $ocupado)
            . $this->andamento($sit, $base, $csrf, $aviso !== '' || $ocupado)
            . Pagina::abasEmLink(self::ABAS, $aba, $base, 'Seções da atualização');
        $corpo .= match ($aba) {
            'historico' => $this->execucao($andamento) . $this->voltarAtras($ocupado, $base, $csrf, $lembrarConfirmacao, $c->agendar),
            'configuracao' => $this->configuracao($c),
            default => $this->consulta($k) . ($podeAplicar ? $this->formularioDeAplicar($k['manifesto'], $base, $csrf, $lembrarConfirmacao, $c->agendar) : ''),
        };

        return Pagina::resposta('Atualização', $corpo, $status, false, $this->barra($sessao), [['Início', Painel::PREFIXO . '/'], ['Atualização', null]], 'Mantenha o PBL4 em dia e desfaça uma atualização, se precisar.');
    }

    /**
     * Estado e UMA ação principal (ADR 0033): ocupado → "Ver andamento"; versão nova → "Atualizar para X"; em dia ou com erro →
     * "Verificar agora"; sem endereço/chave → "Configurar". "Verificar agora" é sempre o único formulário de consulta da tela.
     *
     * @param array{situacao: string, texto: string, quando: int, disponivel: bool, motivo: string, manifesto: array{versao: string, data: string, resumo: string, notas: string, seguranca: bool, migracoes: bool, php_minimo: string}|null} $k
     */
    private function cabecalhoDeEstado(array $k, Configuracao $c, string $base, string $csrf, bool $ocupado): string
    {
        $versao = 'Versão instalada: <code>' . Pagina::h($this->versaoInstalada) . '</code> · canal ' . Pagina::h($c->canal) . '.';
        $verificar = static fn (string $classe): string => '<form method="post" action="' . $base . '/verificar" class="d-inline">' . $csrf . '<button type="submit" class="btn ' . $classe . '">Verificar agora</button></form>';
        $m = $k['manifesto'];
        if ($ocupado) {
            return Pagina::cabecalho($versao . ' Em andamento: acompanhe o andamento abaixo.', '<a class="btn btn-primary" href="#titulo-andamento">Ver andamento</a>');
        }
        if ($k['situacao'] === 'ok' && $k['disponivel'] && $m !== null) {
            $estado = Pagina::cartaoDeEstado($m['seguranca'] ? 'atencao' : 'andamento', 'Há uma versão nova: ' . $m['versao'], $m['seguranca'] ? 'Inclui correção de segurança.' : '');

            return Pagina::cabecalho($versao, '<a class="btn btn-primary" href="' . $base . '?aba=atualizar#atualizar-agora">Atualizar para ' . Pagina::h($m['versao']) . '</a>', [$verificar('btn-link')]) . $estado;
        }
        if ($k['situacao'] === 'ok') {
            return Pagina::cabecalho($versao, $verificar('btn-primary'), []) . Pagina::cartaoDeEstado('ok', 'Você está na versão mais recente');
        }
        if ($k['situacao'] === 'erro') {
            return Pagina::cabecalho($versao, $verificar('btn-primary')) . Pagina::cartaoDeEstado('erro', 'Não deu para consultar as atualizações', 'O motivo está na aba Atualizar.');
        }

        return Pagina::cabecalho($versao, '<a class="btn btn-primary" href="' . $base . '?aba=configuracao">Configurar</a>')
            . Pagina::cartaoDeEstado('atencao', $k['situacao'] === 'desligada' ? 'A verificação está desligada' : 'As atualizações não estão prontas', 'Veja o que falta na aba Configuração.');
    }

    /** Aba Configuração: canal, endereço, agendamento (somente leitura: o arquivo é do servidor) e o Diagnóstico recolhido. */
    private function configuracao(Configuracao $c): string
    {
        $linha = static fn (string $rotulo, string $valor): string => '<dt class="col-sm-3">' . $rotulo . '</dt><dd class="col-sm-9">' . $valor . '</dd>';
        $dl = '<dl class="row">' . $linha('Canal', Pagina::h($c->canal))
            . $linha('Endereço de consulta', $c->url === '' ? '<span class="text-body-secondary">não configurado</span>' : '<code>' . Pagina::h($c->url) . '</code>')
            . $linha('Verificação', $c->verificar ? 'ligada (no máximo uma vez por dia)' : 'desligada')
            . $linha('Agendar para o cron', $c->agendar ? 'permitido' : 'desligado') . '</dl>'
            . '<p class="small text-body-secondary">Estes valores ficam em <code>dados/config/atualizacao.json</code>, no servidor. O cron e o pseudo-cron estão em <a href="' . Painel::PREFIXO . '/tarefas">Tarefas agendadas</a>.</p>';

        $itens = $this->requisitos();
        $falta = array_filter($itens, static fn (array $i): bool => !$i[1]);
        $lista = '';
        foreach ($itens as [$nome, $ok, $detalhe]) {
            $lista .= '<li>' . ($ok ? '<span class="badge text-bg-success">OK</span> ' : '<span class="badge text-bg-danger">Falta</span> ') . Pagina::h($nome) . ' <span class="text-body-secondary">— ' . Pagina::h($detalhe) . '</span></li>';
        }

        return Pagina::secao('Configuração', 'Como as atualizações são consultadas.', $dl)
            . Pagina::recolhivel('diagnostico', 'Diagnóstico' . ($falta === [] ? '' : ' (' . count($falta) . ' ' . (count($falta) === 1 ? 'item pede' : 'itens pedem') . ' atenção)'), '<ul class="list-unstyled mb-0">' . $lista . '</ul>', $falta !== []);
    }

    /** @return list<array{0: string, 1: bool, 2: string}> nome, está bem, detalhe */
    private function requisitos(): array
    {
        $raiz = $this->servicos->caminhos->raiz;
        $php = LancadorDeProcesso::descobrirPhp(Configuracao::ler($this->servicos->caminhos)->php);

        return [
            ['Versão do PHP', version_compare($this->phpAtual, '8.2.0', '>='), $this->phpAtual],
            ['Iniciar processos (proc_open)', LancadorDeProcesso::disponivel(), LancadorDeProcesso::disponivel() ? 'o botão inicia na hora' : 'só dá para agendar para o cron'],
            ['PHP de linha de comando', $php !== null, $php ?? 'não achado; indique "php" em atualizacao.json'],
            ['Extensão zip', extension_loaded('zip'), extension_loaded('zip') ? 'carregada' : 'não carregada'],
            ['Escrita na pasta do PBL4', is_writable($raiz), is_writable($raiz) ? 'permitida' : 'sem permissão; atualize pelo terminal'],
        ];
    }

    /**
     * A situação da atualização (ou da volta atrás) em segundo plano; a primeira vez que a tela vê o fim, registra na
     * auditoria (uma vez só). `atualizacao.concluida`/`.falhou`/`.sem_resposta` para uma atualização, com o prefixo
     * `atualizacao.reverter_` para uma volta atrás (não muda o nome das ações já existentes).
     *
     * @return array{estado: string, tipo: string, de: string, para: string, iniciado: int, saida: string, codigo: int|null, auditado: bool}
     */
    private function situacaoAuditada(SessaoAtiva $sessao): array
    {
        $s = $this->estado->situacao();
        if (in_array($s['estado'], ['concluida', 'falhou', 'sem_resposta'], true) && !$s['auditado']) {
            $acao = ($s['tipo'] === 'reverter' ? 'atualizacao.reverter_' : 'atualizacao.') . $s['estado'];
            $this->servicos->auditoria->registrar($acao, 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, null, ['de' => $s['de'], 'para' => $s['para']]);
            try {
                $this->estado->marcarAuditado();
            } catch (ErroDeAtualizacao) {
            }
        }

        return $s;
    }

    /** @param array{estado: string, tipo: string, de: string, para: string, iniciado: int, saida: string, codigo: int|null, auditado: bool} $s */
    private function execucao(array $s): string
    {
        if ($s['estado'] === 'ociosa') {
            return Pagina::vazio('Nada foi feito pelo painel ainda', 'Quando você atualizar ou voltar atrás por aqui, o resultado fica registrado nesta aba.');
        }
        $reverter = $s['tipo'] === 'reverter';
        $titulo = $reverter ? 'Última volta atrás iniciada pelo painel' : 'Última atualização iniciada pelo painel';
        $acao = $reverter ? 'a volta atrás' : 'a atualização';
        $quando = gmdate('d/m/Y H:i', $s['iniciado']) . ' UTC';
        $saida = $s['saida'] === '' ? '' : '<pre class="mb-0 mt-2 small">' . Pagina::h($s['saida']) . '</pre>';
        $de = Pagina::h($s['de']);
        $para = Pagina::h($s['para']);

        return '<section class="mb-4" aria-labelledby="titulo-execucao"><h2 class="h5" id="titulo-execucao">' . Pagina::h($titulo) . '</h2>' . match ($s['estado']) {
            'andamento' => '<div class="alert alert-info" role="status">Em andamento desde ' . $quando . ': de ' . $de . ' para ' . $para . '. O site fica em manutenção durante a troca. '
                . '<a href="' . Painel::PREFIXO . '/atualizacao">Recarregar</a>.' . $saida . '</div>',
            'concluida' => '<div class="alert alert-success" role="status">Terminou sem erro (iniciada em ' . $quando . ', de ' . $de . ' para ' . $para . ').' . $saida . '</div>',
            'falhou' => '<div class="alert alert-danger" role="alert">' . ucfirst($acao) . ' falhou (iniciada em ' . $quando . ', de ' . $de . ' para ' . $para . '). O PBL4 já devolveu o estado anterior. Motivo:' . $saida . '</div>',
            default => '<div class="alert alert-warning" role="alert">' . ucfirst($acao) . ' iniciada em ' . $quando . ' sem sinal há mais de 1 hora. Pode ter sido interrompida: confira o site e tente de novo.' . $saida . '</div>',
        } . '</section>';
    }

    /**
     * @param array{situacao: string, texto: string, quando: int, disponivel: bool, motivo: string, manifesto: array{versao: string, data: string, resumo: string, notas: string, seguranca: bool, migracoes: bool, php_minimo: string}|null} $k
     */
    private function consulta(array $k): string
    {
        $aviso = static fn (string $classe, string $texto): string => '<div class="alert alert-' . $classe . '" role="status">' . $texto . '</div>';
        $r = '<section class="mb-4" aria-labelledby="titulo-consulta"><h2 class="h5" id="titulo-consulta">Versão disponível</h2>';
        $r .= match ($k['situacao']) {
            'sem_url' => $aviso('warning', 'Nenhum endereço de atualizações configurado. Para ligar a consulta, crie o arquivo <code>dados/config/atualizacao.json</code> no servidor com o endereço do <code>latest.json</code> do canal que você usa, por exemplo <code>{"url": "https://…/latest.json", "canal": "estavel"}</code>. O PBL4 não traz endereço pronto.'),
            'desligada' => $aviso('secondary', 'A verificação de atualizações está desligada (<code>"verificar": false</code> em <code>dados/config/atualizacao.json</code>). Nada é consultado.'),
            'sem_chave' => $aviso('warning', '<strong>Nenhuma chave de assinatura instalada; atualizações recusadas.</strong> Este PBL4 só aceita pacotes assinados e este build não traz chave pública de assinatura, então não consulta nem aplica atualizações. Instale uma versão oficial do PBL4, que já traz as chaves.'),
            'erro' => $aviso('danger', 'Não foi possível consultar o canal: ' . Pagina::h($k['texto']) . ($k['quando'] > 0 ? ' (' . gmdate('d/m/Y H:i', $k['quando']) . ' UTC)' : '')),
            default => $this->resultado($k),
        };

        return $r . '</section>';
    }

    /** @param array{situacao: string, texto: string, quando: int, disponivel: bool, motivo: string, manifesto: array{versao: string, data: string, resumo: string, notas: string, seguranca: bool, migracoes: bool, php_minimo: string}|null} $k */
    private function resultado(array $k): string
    {
        $quando = '<p class="small text-body-secondary">Consultado em ' . gmdate('d/m/Y H:i', $k['quando']) . ' UTC; no máximo 1 vez por dia.</p>';
        $m = $k['manifesto'];
        if (!$k['disponivel'] || $m === null) {
            return '<div class="alert alert-secondary" role="status">' . Pagina::h($k['motivo']) . '</div>' . $quando;
        }
        $r = '<div class="alert alert-info" role="status"><strong>Nova versão: ' . Pagina::h($m['versao']) . '</strong> (' . Pagina::h($m['data']) . ')'
            . ($m['seguranca'] ? ' <span class="badge text-bg-danger">Correção de segurança</span>' : '') . '</div>'
            . '<dl class="row"><dt class="col-sm-3">Mudanças</dt><dd class="col-sm-9">' . ($m['resumo'] === '' ? '<span class="text-body-secondary">sem resumo</span>' : Pagina::h($m['resumo']))
            . ($m['notas'] === '' ? '' : ' (<a href="' . Pagina::h($m['notas']) . '" rel="noopener noreferrer">notas completas</a>)') . '</dd>'
            . '<dt class="col-sm-3">Banco de dados</dt><dd class="col-sm-9">' . ($m['migracoes'] ? 'tem migrações; uma cópia do banco é guardada antes' : 'sem migrações') . '</dd>'
            . '<dt class="col-sm-3">PHP mínimo</dt><dd class="col-sm-9">' . Pagina::h($m['php_minimo']) . '</dd></dl>';

        return $r . $quando;
    }

    /** @param array{versao: string, data: string, resumo: string, notas: string, seguranca: bool, migracoes: bool, php_minimo: string} $m */
    private function formularioDeAplicar(array $m, string $base, string $csrf, bool $lembrar, bool $podeAgendar): string
    {
        return '<section class="mb-4" id="atualizar-agora" aria-labelledby="titulo-aplicar"><h2 class="h5" id="titulo-aplicar">Atualizar agora</h2>'
            . '<p>Guarda uma cópia do banco, troca o código e volta ao anterior se algo falhar. <code>dados/</code> não muda.</p>'
            . '<form method="post" action="' . $base . '/aplicar" class="row g-3">' . $csrf
            . '<input type="hidden" name="versao" value="' . Pagina::h($m['versao']) . '">'
            . '<div class="col-12 col-md-6"><label for="campo-senha" class="form-label">Sua senha, para confirmar</label><input id="campo-senha" class="form-control" name="senha" type="password" autocomplete="current-password" maxlength="1024" required></div>'
            . '<div class="col-12"><div class="form-check"><input id="campo-confirmar" class="form-check-input" type="checkbox" name="confirmar" value="1" required' . ($lembrar ? ' checked' : '') . '>'
            . '<label for="campo-confirmar" class="form-check-label">Entendo que o site fica em manutenção e quero atualizar para a versão ' . Pagina::h($m['versao']) . '.</label></div></div>'
            . $this->escolhaDeAgendar($podeAgendar, 'atualizar')
            . '<div class="col-12"><button type="submit" class="btn btn-warning">' . ($this->soAgenda($podeAgendar) ? 'Agendar atualização' : 'Atualizar agora') . '</button></div></form></section>';
    }

    /** O servidor não deixa o painel iniciar processos e o agendamento está ligado: só resta agendar. */
    private function soAgenda(bool $podeAgendar): bool
    {
        return $podeAgendar && !LancadorDeProcesso::disponivel();
    }

    /** Caixa "agendar para o cron" (ou o aviso de que só o agendamento funciona neste servidor). */
    private function escolhaDeAgendar(bool $podeAgendar, string $tipo): string
    {
        if (!$podeAgendar) {
            return '';
        }
        $acao = $tipo === 'reverter' ? 'a volta atrás' : 'a atualização';
        if ($this->soAgenda($podeAgendar)) {
            return '<input type="hidden" name="modo" value="agendar"><div class="col-12"><p class="small mb-0">O servidor não deixa iniciar ' . $acao . ' na hora. Ela será <strong>agendada</strong> e o cron a executa em até 1 minuto.</p></div>';
        }

        return '<div class="col-12"><div class="form-check"><input id="campo-agendar-' . $tipo . '" class="form-check-input" type="checkbox" name="modo" value="agendar">'
            . '<label for="campo-agendar-' . $tipo . '" class="form-check-label">Em vez de iniciar agora, agendar para o cron do servidor (executa em até 1 minuto).</label></div></div>';
    }

    /**
     * A seção "Andamento": barra, etapa, tempo e resultado da atualização (ou volta atrás), vindos do terminal, do cron ou do processo
     * do painel. O HTML já nasce com o estado de agora (funciona sem JavaScript); `pbl-atualizacao-progresso.js` o mantém em dia.
     *
     * @param array{estado: string, tipo: string, etapa: string, etapa_nome: string, percentual: int, mensagem: string, inicio: int|null, fim: int|null, decorrido_s: int, agendada_em: int|null, valida_ate: int|null, agendada_ha_s: int|null, falha: string, cron_ativo: bool, cron_sem_iniciar: bool} $s
     * @param bool $iniciou a página acabou de iniciar/agendar algo (mostra a seção mesmo antes de o primeiro andamento ser gravado)
     */
    private function andamento(array $s, string $base, string $csrf, bool $iniciou): string
    {
        if ($s['estado'] === 'ocioso' && !$iniciou) {
            return '';
        }
        $agendada = $s['estado'] === 'agendada';
        $concluido = $s['estado'] === 'concluido';
        $falhou = $s['estado'] === 'falhou';
        $acao = $s['tipo'] === 'reverter' ? 'A volta atrás' : 'A atualização';
        $tempo = match ($s['estado']) {
            'agendada' => 'Agendada há ' . self::duracao((int) $s['agendada_ha_s']) . '.',
            'em_andamento' => 'Tempo decorrido: ' . self::duracao($s['decorrido_s']) . '.',
            'ocioso' => '',
            default => 'Durou ' . self::duracao($s['decorrido_s']) . '.',
        };
        $semCron = '<strong>O cron parece não estar rodando.</strong> A atualização agendada espera há mais de 5 minutos. Confira se há uma tarefa agendada a cada minuto com <code>php …/bin/pbl cron</code> no painel da hospedagem e se a tela <a href="' . Painel::PREFIXO . '/cron">Tarefas agendadas</a> mostra um ciclo recente (veja o passo a passo em <code>docs/usuario/atualizar.md</code>).';
        $pct = $s['percentual'];

        return '<section class="pbl-estado pbl-estado-andamento" aria-labelledby="titulo-andamento" data-pbl-progresso data-url="' . $base . '/progresso" data-estado="' . Pagina::h($s['estado']) . '">'
            . '<h2 class="h5" id="titulo-andamento">Andamento</h2>'
            . '<p class="mb-1"><span class="text-body-secondary">Etapa:</span> <strong aria-live="polite" aria-atomic="true" data-pbl-etapa>' . Pagina::h($s['etapa_nome']) . '</strong></p>'
            . '<div class="progress mb-2" role="progressbar" aria-labelledby="titulo-andamento" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $pct . '" aria-valuetext="' . Pagina::h($pct . '% — ' . $s['mensagem']) . '" data-pbl-trilho><div class="progress-bar" data-pbl-barra></div></div>'
            . '<p class="mb-1" data-pbl-mensagem>' . Pagina::h($s['mensagem']) . '</p>'
            . '<p class="small text-body-secondary" data-pbl-tempo>' . Pagina::h($tempo) . '</p>'
            . '<div class="alert alert-success"' . ($concluido ? '' : ' hidden') . ' role="status" data-pbl-ok>' . ($concluido ? Pagina::h($acao . ' terminou sem erro. Recarregue a página para ver a versão instalada.') : '') . '</div>'
            . '<div class="alert alert-danger"' . ($falhou ? '' : ' hidden') . ' role="alert" data-pbl-erro>' . ($falhou ? Pagina::h($acao . ' falhou. ' . $s['falha'] . ' O PBL4 já devolveu o estado anterior.') : '') . '</div>'
            . '<div class="alert alert-warning"' . ($agendada && $s['cron_sem_iniciar'] ? '' : ' hidden') . ' role="status" data-pbl-aviso-cron>' . $semCron . '</div>'
            . '<p class="small text-body-secondary" role="status" data-pbl-conexao hidden></p>'
            . '<form method="post" action="' . $base . '/cancelar-agendamento" class="mb-2"' . ($agendada ? '' : ' hidden') . ' data-pbl-cancelar>' . $csrf
            . '<button type="submit" class="btn btn-outline-secondary btn-sm">Cancelar o agendamento</button></form>'
            . '<p class="small mb-0"' . ($concluido || $falhou ? '' : ' hidden') . ' data-pbl-recarregar><a href="' . $base . '">Recarregar a página</a></p>'
            . '<noscript><p class="small mb-0">Sem JavaScript o andamento não se atualiza sozinho: <a href="' . $base . '">atualizar a página</a>.</p></noscript>'
            . '</section>';
    }

    private static function duracao(int $s): string
    {
        $s = max(0, $s);

        return match (true) {
            $s < 60 => $s . ' s',
            $s < 3600 => intdiv($s, 60) . ' min ' . ($s % 60) . ' s',
            default => intdiv($s, 3600) . ' h ' . intdiv($s % 3600, 60) . ' min',
        };
    }

    /**
     * "Se algo der errado": a falha automática (sempre existe) e, quando o `Reversor` diz que dá, o botão para voltar atrás
     * de uma atualização já concluída. Só aparece com uma avaliação positiva: o `Reversor` já confere versão instalada,
     * código guardado, cópia do banco (SHA-256 e versão de esquema) e permissão de escrita antes de dizer que sim.
     *
     * @param bool $ocupado já há uma atualização agendada ou em andamento: não oferece outra
     */
    private function voltarAtras(bool $ocupado, string $base, string $csrf, bool $lembrar, bool $podeAgendar): string
    {
        $cab = '<section class="pbl-perigo" aria-labelledby="titulo-voltar"><h2 class="h5" id="titulo-voltar">Voltar atrás</h2>'
            . '<p class="small">Se uma atualização falha, o PBL4 devolve o estado anterior sozinho.</p>';
        if ($ocupado) {
            return $cab . '<p class="mb-0">Disponível quando a operação em andamento terminar.</p></section>';
        }
        $a = $this->reversor()->avaliar();
        if (!$a->possivel) {
            return $cab . '<p class="mb-0">Voltar atrás de uma atualização já concluída: ' . Pagina::h($a->motivo) . '</p></section>';
        }
        $quando = gmdate('d/m/Y H:i', $a->em) . ' UTC';
        $perda = $a->restauraBanco
            ? 'o banco volta ao de antes. Tudo o que foi criado depois de ' . $quando . ' se perde.'
            : 'só o código volta; não havia banco para copiar.';

        return $cab . '<p>Dá para voltar para a versão <code>' . Pagina::h($a->de) . '</code> (estava instalada antes de ' . Pagina::h($a->para) . ', atualizada em ' . $quando . ').</p>'
            . '<form method="post" action="' . $base . '/reverter" class="row g-3">' . $csrf
            . '<p class="col-12 mb-0"><strong>Atenção:</strong> troca o código e restaura o banco: ' . $perda . '</p>'
            . '<div class="col-12 col-md-6"><label for="campo-senha-reverter" class="form-label">Sua senha, para confirmar</label><input id="campo-senha-reverter" class="form-control" name="senha" type="password" autocomplete="current-password" maxlength="1024" required></div>'
            . '<div class="col-12"><div class="form-check"><input id="campo-confirmar-reverter" class="form-check-input" type="checkbox" name="confirmar" value="1" required' . ($lembrar ? ' checked' : '') . '>'
            . '<label for="campo-confirmar-reverter" class="form-check-label">Entendo que isso perde o que foi criado depois de ' . $quando . ' e quero voltar para a versão ' . Pagina::h($a->de) . '.</label></div></div>'
            . $this->escolhaDeAgendar($podeAgendar, 'reverter')
            . '<div class="col-12"><button type="submit" class="btn btn-outline-danger">' . ($this->soAgenda($podeAgendar) ? 'Agendar a volta para a ' : 'Voltar para a ') . Pagina::h($a->de) . '</button></div></form></section>';
    }
}
