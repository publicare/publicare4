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
use Pbl\Atualizacao\Reversor;
use Pbl\Servicos;
use Pbl\Versao;
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
    private readonly Lancador $lancador;
    private readonly Baixador $baixador;
    /** @var list<string> */
    private readonly array $chaves;

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
        $this->baixador = $baixador ?? new BaixadorHttps();
        $this->chaves = $chaves ?? Chaves::PUBLICAS;
        $this->lancador = $lancador ?? new LancadorDeProcesso($servicos->caminhos, LancadorDeProcesso::descobrirPhp(Configuracao::ler($servicos->caminhos)->php));
    }

    /**
     * Aviso de versão nova na página inicial do painel (`TelasDeConteudo::inicio`), só para o administrador geral. **Nunca
     * consulta a rede aqui**: só lê o cache de 24 h que a tela "Atualização" (ou `bin/pbl verificar-atualizacao`, pelo cron) já
     * gravou. Cache vencido ou inexistente: mostra "verificar" com link para a tela, que aí sim pode consultar. Sem endereço
     * configurado, verificação desligada ou sem chave de assinatura embutida: nada aparece (não há o que avisar).
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

    /** @return string|null `ver`, `verificar`, `aplicar` ou `reverter`; null = o `Painel` responde 404 */
    public static function rota(string $prefixo, string $caminho, string $metodo): ?string
    {
        return match (true) {
            $caminho === $prefixo . '/atualizacao' && $metodo === 'GET' => 'ver',
            $caminho === $prefixo . '/atualizacao/verificar' && $metodo === 'POST' => 'verificar',
            $caminho === $prefixo . '/atualizacao/aplicar' && $metodo === 'POST' => 'aplicar',
            $caminho === $prefixo . '/atualizacao/reverter' && $metodo === 'POST' => 'reverter',
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
            if ($post) {
                $this->servicos->auditoria->registrar('atualizacao.negado', 'usuario:' . $u->id, $u->id);
            }

            return Pagina::resposta('Sem acesso', '<div class="alert alert-warning" role="alert">Só administradores podem ver e aplicar atualizações.</div>', Response::HTTP_FORBIDDEN, false, $this->barra($sessao));
        }

        return match ($tela) {
            'ver' => $this->pagina($sessao, $this->aviso($requisicao), null, Response::HTTP_OK, false),
            'verificar' => $this->verificarAgora($sessao),
            'reverter' => $this->reverter($sessao, $requisicao),
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
            return $this->pagina($sessao, '', 'Atualizar pelo painel não está disponível: veja o que falta na parte de cima da página.', Response::HTTP_CONFLICT, false);
        }
        if ($this->estado->situacao()['estado'] === 'andamento') {
            return $this->pagina($sessao, '', 'Já existe uma atualização em andamento. Espere terminar.', Response::HTTP_CONFLICT, false);
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
        try {
            $iniciou = $this->estado->iniciar($this->versaoInstalada, $versao, $this->lancador);
        } catch (ErroDeAtualizacao $e) {
            return $this->pagina($sessao, '', $e->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE, false);
        }
        if (!$iniciou) {
            return $this->pagina($sessao, '', 'Já existe uma atualização em andamento. Espere terminar.', Response::HTTP_CONFLICT, false);
        }
        $this->servicos->auditoria->registrar('atualizacao.iniciar', 'usuario:' . $u->id, $u->id, null, ['de' => $this->versaoInstalada, 'para' => $versao], $this->servicos->limiteDeTentativas()->hashDoIp($ip));

        return new RedirectResponse(Painel::PREFIXO . '/atualizacao?ok=iniciada', Response::HTTP_SEE_OTHER);
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
            return $this->pagina($sessao, '', 'Marque a confirmação para voltar atrás.', Response::HTTP_UNPROCESSABLE_ENTITY, true);
        }
        if ($this->estado->situacao()['estado'] === 'andamento') {
            return $this->pagina($sessao, '', 'Já existe uma atualização em andamento. Espere terminar.', Response::HTTP_CONFLICT, false);
        }
        $a = $this->reversor()->avaliar();
        if (!$a->possivel) {
            return $this->pagina($sessao, '', $a->motivo, Response::HTTP_CONFLICT, false);
        }

        // Reautenticação (ADR 0011, "modo elevado", fase 5h): o mesmo componente comum de "aplicar", sempre exigindo
        // a senha de novo.
        $ip = $this->rede->ip($requisicao);
        $falha = $this->servicos->modoElevado()->confirmar($sessao, $senha, $ip, 'atualizacao', true);
        if ($falha !== null) {
            $r = $this->pagina($sessao, '', $falha['mensagem'], $falha['status'], true);
            if ($falha['retryAfter'] !== null) {
                $r->headers->set('Retry-After', (string) $falha['retryAfter']);
            }

            return $r;
        }

        if (!is_writable($this->servicos->caminhos->raiz)) {
            return $this->pagina($sessao, '', 'Este servidor não deixa o PHP trocar o código da instalação (falta permissão de escrita na pasta do PBL4). Reverta pelo terminal: php bin/pbl reverter --confirmar.', Response::HTTP_CONFLICT, false);
        }
        try {
            $iniciou = $this->estado->iniciar($a->para, $a->de, $this->lancador, 'reverter');
        } catch (ErroDeAtualizacao $e) {
            return $this->pagina($sessao, '', $e->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE, false);
        }
        if (!$iniciou) {
            return $this->pagina($sessao, '', 'Já existe uma atualização em andamento. Espere terminar.', Response::HTTP_CONFLICT, false);
        }
        $this->servicos->auditoria->registrar('atualizacao.reverter_iniciar', 'usuario:' . $u->id, $u->id, null, ['de' => $a->para, 'para' => $a->de], $this->servicos->limiteDeTentativas()->hashDoIp($ip));

        return new RedirectResponse(Painel::PREFIXO . '/atualizacao?ok=reversao_iniciada', Response::HTTP_SEE_OTHER);
    }

    // ---------------------------------------------------------------- tela

    private function aviso(Request $requisicao): string
    {
        $texto = match ($requisicao->query->all()['ok'] ?? null) {
            'verificado' => 'Consulta feita agora.',
            'iniciada' => 'Atualização iniciada em segundo plano. Recarregue esta página para acompanhar; durante a troca o site responde "em manutenção" por alguns instantes.',
            'reversao_iniciada' => 'Volta atrás iniciada em segundo plano. Recarregue esta página para acompanhar; durante a troca o site responde "em manutenção" por alguns instantes.',
            default => null,
        };

        return $texto === null ? '' : '<div class="alert alert-success" role="status">' . Pagina::h($texto) . '</div>';
    }

    private function pagina(SessaoAtiva $sessao, string $aviso, ?string $erro, int $status, bool $lembrarConfirmacao): Response
    {
        $andamento = $this->situacaoAuditada($sessao);
        $c = Configuracao::ler($this->servicos->caminhos);
        $k = $this->consultar(false, $andamento['estado'] !== 'andamento');
        $csrf = $this->campoCsrf($sessao);
        $base = Painel::PREFIXO . '/atualizacao';

        $corpo = $aviso . $this->erro($erro)
            . '<dl class="row"><dt class="col-sm-3">Versão instalada</dt><dd class="col-sm-9"><code>' . Pagina::h($this->versaoInstalada) . '</code></dd>'
            . '<dt class="col-sm-3">Canal</dt><dd class="col-sm-9">' . Pagina::h($c->canal) . '</dd>'
            . '<dt class="col-sm-3">Endereço de consulta</dt><dd class="col-sm-9">' . ($c->url === '' ? '<span class="text-body-secondary">não configurado</span>' : '<code>' . Pagina::h($c->url) . '</code>') . '</dd></dl>'
            . $this->execucao($andamento)
            . $this->consulta($k, $c, $base, $csrf);
        if ($k['situacao'] === 'ok' && $k['disponivel'] && $k['manifesto'] !== null && $andamento['estado'] !== 'andamento') {
            $corpo .= $this->formularioDeAplicar($k['manifesto'], $base, $csrf, $lembrarConfirmacao);
        }
        $corpo .= $this->voltarAtras($andamento, $base, $csrf, $lembrarConfirmacao) . '<p class="mt-4"><a href="' . Painel::PREFIXO . '/">Voltar ao painel</a></p>';

        return Pagina::resposta('Atualização', $corpo, $status, false, $this->barra($sessao), [['Atualização', null]]);
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
            return '';
        }
        $reverter = $s['tipo'] === 'reverter';
        $titulo = $reverter ? 'Última volta atrás iniciada pelo painel' : 'Última atualização iniciada pelo painel';
        $acao = $reverter ? 'a volta atrás' : 'a atualização';
        $quando = gmdate('d/m/Y H:i', $s['iniciado']) . ' UTC';
        $saida = $s['saida'] === '' ? '' : '<pre class="mb-0 mt-2 small">' . Pagina::h($s['saida']) . '</pre>';
        $de = Pagina::h($s['de']);
        $para = Pagina::h($s['para']);

        return '<section class="mb-4" aria-labelledby="titulo-execucao"><h2 class="h5" id="titulo-execucao">' . Pagina::h($titulo) . '</h2>' . match ($s['estado']) {
            'andamento' => '<div class="alert alert-info" role="status">Em andamento desde ' . $quando . ': de ' . $de . ' para ' . $para . '. Pode levar alguns minutos e o site fica em manutenção durante a troca. '
                . '<a href="' . Painel::PREFIXO . '/atualizacao">Recarregar</a>.' . $saida . '</div>',
            'concluida' => '<div class="alert alert-success" role="status">Terminou sem erro (iniciada em ' . $quando . ', de ' . $de . ' para ' . $para . ').' . $saida . '</div>',
            'falhou' => '<div class="alert alert-danger" role="alert">' . ucfirst($acao) . ' falhou (iniciada em ' . $quando . ', de ' . $de . ' para ' . $para . '). Se a falha foi depois da troca do código, o PBL4 já devolveu o estado anterior. O motivo:' . $saida . '</div>',
            default => '<div class="alert alert-warning" role="alert">' . ucfirst($acao) . ' iniciada em ' . $quando . ' não deu sinal de término há mais de uma hora. O processo pode ter sido interrompido; confira o site e tente de novo, se for o caso.' . $saida . '</div>',
        } . '</section>';
    }

    /**
     * @param array{situacao: string, texto: string, quando: int, disponivel: bool, motivo: string, manifesto: array{versao: string, data: string, resumo: string, notas: string, seguranca: bool, migracoes: bool, php_minimo: string}|null} $k
     */
    private function consulta(array $k, Configuracao $c, string $base, string $csrf): string
    {
        $aviso = static fn (string $classe, string $texto): string => '<div class="alert alert-' . $classe . '" role="status">' . $texto . '</div>';
        $botao = '<form method="post" action="' . $base . '/verificar" class="mb-3">' . $csrf . '<button type="submit" class="btn btn-outline-primary">Verificar agora</button></form>';
        $r = '<section class="mb-4" aria-labelledby="titulo-consulta"><h2 class="h5" id="titulo-consulta">Versão disponível</h2>';
        $r .= match ($k['situacao']) {
            'sem_url' => $aviso('warning', 'Nenhum endereço de atualizações configurado. Para ligar a consulta, crie o arquivo <code>dados/config/atualizacao.json</code> no servidor com o endereço do <code>latest.json</code> do canal que você usa, por exemplo <code>{"url": "https://…/latest.json", "canal": "estavel"}</code>. O PBL4 não traz endereço pronto.'),
            'desligada' => $aviso('secondary', 'A verificação de atualizações está desligada (<code>"verificar": false</code> em <code>dados/config/atualizacao.json</code>). Nada é consultado.'),
            'sem_chave' => $aviso('warning', '<strong>Nenhuma chave de assinatura instalada; atualizações recusadas.</strong> Este PBL4 só aceita pacotes assinados e ainda não traz a chave pública de ninguém, então não consulta nem aplica atualizações. A chave virá numa versão do próprio PBL4.'),
            'erro' => $botao . $aviso('danger', 'Não foi possível consultar o canal: ' . Pagina::h($k['texto']) . ($k['quando'] > 0 ? ' (' . gmdate('d/m/Y H:i', $k['quando']) . ' UTC)' : '')),
            default => $botao . $this->resultado($k),
        };

        return $r . '</section>';
    }

    /** @param array{situacao: string, texto: string, quando: int, disponivel: bool, motivo: string, manifesto: array{versao: string, data: string, resumo: string, notas: string, seguranca: bool, migracoes: bool, php_minimo: string}|null} $k */
    private function resultado(array $k): string
    {
        $quando = '<p class="small text-body-secondary">Consultado em ' . gmdate('d/m/Y H:i', $k['quando']) . ' UTC; a consulta se repete no máximo uma vez por dia.</p>';
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
    private function formularioDeAplicar(array $m, string $base, string $csrf, bool $lembrar): string
    {
        return '<section class="mb-4" aria-labelledby="titulo-aplicar"><h2 class="h5" id="titulo-aplicar">Atualizar agora</h2>'
            . '<p>O PBL4 vai guardar uma cópia do banco, pôr o site em manutenção por alguns instantes, trocar o código e conferir a versão nova; se algo falhar, volta ao código anterior. Seus dados (<code>dados/</code>) não são tocados.</p>'
            . '<form method="post" action="' . $base . '/aplicar" class="row g-3">' . $csrf
            . '<input type="hidden" name="versao" value="' . Pagina::h($m['versao']) . '">'
            . '<div class="col-12 col-md-6"><label for="campo-senha" class="form-label">Sua senha, para confirmar</label><input id="campo-senha" class="form-control" name="senha" type="password" autocomplete="current-password" maxlength="1024" required></div>'
            . '<div class="col-12"><div class="form-check"><input id="campo-confirmar" class="form-check-input" type="checkbox" name="confirmar" value="1" required' . ($lembrar ? ' checked' : '') . '>'
            . '<label for="campo-confirmar" class="form-check-label">Entendo que o site ficará em manutenção durante a troca e quero atualizar para a versão ' . Pagina::h($m['versao']) . '.</label></div></div>'
            . '<div class="col-12"><button type="submit" class="btn btn-warning">Atualizar agora</button></div></form></section>';
    }

    /**
     * "Se algo der errado": a falha automática (sempre existe) e, quando o `Reversor` diz que dá, o botão para voltar atrás
     * de uma atualização já concluída. Só aparece com uma avaliação positiva: o `Reversor` já confere versão instalada,
     * código guardado, cópia do banco (SHA-256 e versão de esquema) e permissão de escrita antes de dizer que sim.
     *
     * @param array{estado: string, tipo: string, de: string, para: string, iniciado: int, saida: string, codigo: int|null, auditado: bool} $andamento
     */
    private function voltarAtras(array $andamento, string $base, string $csrf, bool $lembrar): string
    {
        $corpo = '<section class="mb-4" aria-labelledby="titulo-voltar"><h2 class="h5" id="titulo-voltar">Se algo der errado</h2>'
            . '<ul><li>Falha durante a atualização (ou a volta atrás): o PBL4 devolve sozinho o estado anterior e mostra o motivo acima.</li>';
        if ($andamento['estado'] === 'andamento') {
            return $corpo . '</ul></section>';
        }
        $a = $this->reversor()->avaliar();
        if (!$a->possivel) {
            return $corpo . '<li>Voltar atrás de uma atualização já concluída: ' . Pagina::h($a->motivo) . '</li></ul></section>';
        }
        $quando = gmdate('d/m/Y H:i', $a->em) . ' UTC';
        $perda = $a->restauraBanco
            ? 'o banco também volta para a cópia de antes da atualização: qualquer conteúdo, conta ou mudança criada depois de ' . $quando . ' se perde.'
            : 'não havia banco para copiar na atualização; só o código volta.';

        return $corpo . '<li>Dá para voltar para a versão <code>' . Pagina::h($a->de) . '</code> (estava instalada antes de ' . Pagina::h($a->para) . ', atualizada em ' . $quando . ').</li></ul>'
            . '<form method="post" action="' . $base . '/reverter" class="row g-3">' . $csrf
            . '<p class="col-12">Isso troca o código de volta e restaura o banco de antes da atualização: ' . $perda . '</p>'
            . '<div class="col-12 col-md-6"><label for="campo-senha-reverter" class="form-label">Sua senha, para confirmar</label><input id="campo-senha-reverter" class="form-control" name="senha" type="password" autocomplete="current-password" maxlength="1024" required></div>'
            . '<div class="col-12"><div class="form-check"><input id="campo-confirmar-reverter" class="form-check-input" type="checkbox" name="confirmar" value="1" required' . ($lembrar ? ' checked' : '') . '>'
            . '<label for="campo-confirmar-reverter" class="form-check-label">Entendo que isso perde o que foi criado depois de ' . $quando . ' e quero voltar para a versão ' . Pagina::h($a->de) . '.</label></div></div>'
            . '<div class="col-12"><button type="submit" class="btn btn-outline-danger">Voltar para a ' . Pagina::h($a->de) . '</button></div></form></section>';
    }
}
