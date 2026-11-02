<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Atualizacao\Atualizador;
use Pbl\Atualizacao\Baixador;
use Pbl\Atualizacao\BaixadorHttps;
use Pbl\Atualizacao\Chaves;
use Pbl\Atualizacao\Configuracao;
use Pbl\Atualizacao\ErroDeAtualizacao;
use Pbl\Atualizacao\EstadoDoPainel;
use Pbl\Atualizacao\Lancador;
use Pbl\Atualizacao\LancadorDeProcesso;
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
 * Quem atualiza é o MESMO `Atualizador` da linha de comando: o botão só inicia `bin/pbl atualizar` em outro processo (`Lancador`),
 * porque a atualização demora e troca o código em execução. A requisição web grava apenas em `dados/atualizacao-painel/`
 * (`EstadoDoPainel`: cache, trava e resultado); quem troca o código é o processo de segundo plano. Aplicar exige a senha de novo
 * (com o limite de tentativas do login) e a confirmação marcada. A auditoria guarda só as versões (validadas como SemVer pelo
 * manifesto assinado) e o id de quem fez, nunca a senha nem o texto das mudanças.
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

    /** @return string|null `ver`, `verificar` ou `aplicar`; null = o `Painel` responde 404 */
    public static function rota(string $prefixo, string $caminho, string $metodo): ?string
    {
        return match (true) {
            $caminho === $prefixo . '/atualizacao' && $metodo === 'GET' => 'ver',
            $caminho === $prefixo . '/atualizacao/verificar' && $metodo === 'POST' => 'verificar',
            $caminho === $prefixo . '/atualizacao/aplicar' && $metodo === 'POST' => 'aplicar',
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
        if ($this->chaves === []) {
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
        if ($c->url === '' || !$c->verificar || $this->chaves === []) {
            return $this->pagina($sessao, '', 'Atualizar pelo painel não está disponível: veja o que falta na parte de cima da página.', Response::HTTP_CONFLICT, false);
        }
        if ($this->estado->situacao()['estado'] === 'andamento') {
            return $this->pagina($sessao, '', 'Já existe uma atualização em andamento. Espere terminar.', Response::HTTP_CONFLICT, false);
        }

        // Reautenticação: a mesma senha e o mesmo limite de tentativas do login.
        $ip = $this->rede->ip($requisicao);
        $limite = $this->servicos->limiteDeTentativas();
        $espera = $limite->bloqueadoPor($u->login, $ip);
        if ($espera !== null) {
            $this->servicos->auditoria->registrar('atualizacao.bloqueado', 'usuario:' . $u->id, $u->id, null, [], $limite->hashDoIp($ip));
            $r = $this->pagina($sessao, '', 'Muitas tentativas de senha. Aguarde alguns minutos e tente de novo.', Response::HTTP_TOO_MANY_REQUESTS, false);
            $r->headers->set('Retry-After', (string) $espera);

            return $r;
        }
        $confere = $this->servicos->usuarios->autenticar($u->login, mb_substr($senha, 0, 1024));
        if ($confere === null || $confere->id !== $u->id) {
            $limite->registrarFalha($u->login, $ip);
            $this->servicos->auditoria->registrar('atualizacao.senha_errada', 'usuario:' . $u->id, $u->id, null, [], $limite->hashDoIp($ip));

            return $this->pagina($sessao, '', 'Senha incorreta.', Response::HTTP_UNAUTHORIZED, true);
        }
        $limite->limparLogin($u->login);

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
        $this->servicos->auditoria->registrar('atualizacao.iniciar', 'usuario:' . $u->id, $u->id, null, ['de' => $this->versaoInstalada, 'para' => $versao], $limite->hashDoIp($ip));

        return new RedirectResponse(Painel::PREFIXO . '/atualizacao?ok=iniciada', Response::HTTP_SEE_OTHER);
    }

    // ---------------------------------------------------------------- tela

    private function aviso(Request $requisicao): string
    {
        $texto = match ($requisicao->query->all()['ok'] ?? null) {
            'verificado' => 'Consulta feita agora.',
            'iniciada' => 'Atualização iniciada em segundo plano. Recarregue esta página para acompanhar; durante a troca o site responde "em manutenção" por alguns instantes.',
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
        $corpo .= $this->voltarAtras() . '<p class="mt-4"><a href="' . Painel::PREFIXO . '/">Voltar ao painel</a></p>';

        return Pagina::resposta('Atualização', $corpo, $status, false, $this->barra($sessao));
    }

    /**
     * A situação da atualização em segundo plano; a primeira vez que a tela vê o fim dela, registra na auditoria (uma vez só).
     *
     * @return array{estado: string, de: string, para: string, iniciado: int, saida: string, codigo: int|null, auditado: bool}
     */
    private function situacaoAuditada(SessaoAtiva $sessao): array
    {
        $s = $this->estado->situacao();
        if (in_array($s['estado'], ['concluida', 'falhou', 'sem_resposta'], true) && !$s['auditado']) {
            $this->servicos->auditoria->registrar('atualizacao.' . $s['estado'], 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, null, ['de' => $s['de'], 'para' => $s['para']]);
            try {
                $this->estado->marcarAuditado();
            } catch (ErroDeAtualizacao) {
            }
        }

        return $s;
    }

    /** @param array{estado: string, de: string, para: string, iniciado: int, saida: string, codigo: int|null, auditado: bool} $s */
    private function execucao(array $s): string
    {
        if ($s['estado'] === 'ociosa') {
            return '';
        }
        $quando = gmdate('d/m/Y H:i', $s['iniciado']) . ' UTC';
        $saida = $s['saida'] === '' ? '' : '<pre class="mb-0 mt-2 small">' . Pagina::h($s['saida']) . '</pre>';
        $de = Pagina::h($s['de']);
        $para = Pagina::h($s['para']);

        return '<section class="mb-4" aria-labelledby="titulo-execucao"><h2 class="h5" id="titulo-execucao">Última atualização iniciada pelo painel</h2>' . match ($s['estado']) {
            'andamento' => '<div class="alert alert-info" role="status">Em andamento desde ' . $quando . ': de ' . $de . ' para ' . $para . '. Pode levar alguns minutos e o site fica em manutenção durante a troca. '
                . '<a href="' . Painel::PREFIXO . '/atualizacao">Recarregar</a>.' . $saida . '</div>',
            'concluida' => '<div class="alert alert-success" role="status">Terminou sem erro (iniciada em ' . $quando . ', de ' . $de . ' para ' . $para . ').' . $saida . '</div>',
            'falhou' => '<div class="alert alert-danger" role="alert">A atualização falhou (iniciada em ' . $quando . ', de ' . $de . ' para ' . $para . '). Se a falha foi depois da troca do código, o PBL4 já devolveu o código anterior. O motivo:' . $saida . '</div>',
            default => '<div class="alert alert-warning" role="alert">A atualização iniciada em ' . $quando . ' não deu sinal de término há mais de uma hora. O processo pode ter sido interrompido; confira o site e tente de novo, se for o caso.' . $saida . '</div>',
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
        $quando = '<p class="form-text">Consultado em ' . gmdate('d/m/Y H:i', $k['quando']) . ' UTC; a consulta se repete no máximo uma vez por dia.</p>';
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

    private function voltarAtras(): string
    {
        return '<section class="mb-4" aria-labelledby="titulo-voltar"><h2 class="h5" id="titulo-voltar">Se algo der errado</h2>'
            . '<ul><li>Falha durante a atualização: o PBL4 devolve sozinho o código anterior e mostra o motivo acima.</li>'
            . '<li>Depois de uma atualização que terminou, mas que você quer desfazer: o código anterior fica em <code>dados/atualizacao/anterior/</code> e a cópia do banco em <code>dados/backups/</code>. '
            . 'Ainda não há botão nem comando para voltar; é manual, pelo servidor: com o site parado, devolva cada pasta de <code>anterior/</code> ao lugar dela (<code>app/</code>, <code>vendor/</code>…). '
            . 'As migrações só acrescentam, então o código anterior funciona com o banco já migrado; use a cópia do banco só se for preciso. O passo a passo está em <code>docs/usuario/atualizar.md</code>.</li></ul></section>';
    }
}
