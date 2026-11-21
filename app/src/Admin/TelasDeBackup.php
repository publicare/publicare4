<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Backup\AgendaDeBackup;
use Pbl\Backup\CatalogoDeBackups;
use Pbl\Backup\ConfigDeBackup;
use Pbl\Backup\CriadorDeBackup;
use Pbl\Backup\ErroDeBackup;
use Pbl\Backup\EstadoDoBackup;
use Pbl\Backup\InfoDeBackup;
use Pbl\Backup\RestauradorDeBackup;
use Pbl\Backup\RetencaoDeBackups;
use Pbl\Backup\ValidadorDePasta;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tela "Cópias de segurança" (`/_pbl/copias`, ADR 0029). Só orquestra: quem cria, restaura e apaga é o mesmo código do terminal e do
 * cron (`Pbl\Backup`). Só o administrador geral; todo POST confere origem e CSRF primeiro e pede a senha (modo elevado; a restauração
 * pede sempre). Nada é iniciado em segundo plano: a cópia roda dentro do POST, com a barra de "trabalhando" no navegador. Nenhuma
 * resposta mostra caminho do servidor além do configurado, nem segredo; o pacote só sai por POST autenticado.
 */
final class TelasDeBackup
{
    use AuxiliaresDeTela;

    public const CONFIRMACAO = 'RESTAURAR';
    private const NOMES_DIAS = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
    private const TIPOS = ['manual' => 'Manual', 'agendada' => 'Agendada', 'pre-atualizacao' => 'Pré-atualização', 'pre-restauracao' => 'Pré-restauração'];

    /**
     * @param (\Closure(): int)|null $relogio só para os testes
     */
    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
        private readonly ?\Closure $relogio = null,
    ) {
    }

    /** @return string|null `ver`, `criar`, `config`, `baixar`, `apagar`, `limpar`, `restaurar-ver` ou `restaurar`; null = 404 */
    public static function rota(string $prefixo, string $caminho, string $metodo): ?string
    {
        return match (true) {
            $caminho === $prefixo . '/copias' && $metodo === 'GET' => 'ver',
            $caminho === $prefixo . '/copias/criar' && $metodo === 'POST' => 'criar',
            $caminho === $prefixo . '/copias/config' && $metodo === 'POST' => 'config',
            $caminho === $prefixo . '/copias/baixar' && $metodo === 'POST' => 'baixar',
            $caminho === $prefixo . '/copias/apagar' && $metodo === 'POST' => 'apagar',
            $caminho === $prefixo . '/copias/limpar' && $metodo === 'POST' => 'limpar',
            $caminho === $prefixo . '/copias/restaurar' && $metodo === 'GET' => 'restaurar-ver',
            $caminho === $prefixo . '/copias/restaurar' && $metodo === 'POST' => 'restaurar',
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
                $this->servicos->auditoria->registrar('backup.negado', 'usuario:' . $u->id, $u->id);
            }

            return Pagina::resposta('Sem acesso', '<div class="alert alert-warning" role="alert">Só administradores podem ver e usar as cópias de segurança.</div>', Response::HTTP_FORBIDDEN, false, $this->barra($sessao));
        }

        return match ($tela) {
            'ver' => $this->pagina($sessao, $this->aviso($requisicao), null, Response::HTTP_OK),
            'criar' => $this->criar($sessao, $requisicao),
            'config' => $this->salvarConfig($sessao, $requisicao),
            'baixar' => $this->baixar($sessao, $requisicao),
            'apagar' => $this->apagar($sessao, $requisicao),
            'limpar' => $this->limpar($sessao, $requisicao),
            'restaurar-ver' => $this->formularioDeRestaurar($sessao, $this->nomeDaConsulta($requisicao), null, Response::HTTP_OK),
            default => $this->restaurar($sessao, $requisicao),
        };
    }

    // ---------------------------------------------------------------- ações

    private function criar(SessaoAtiva $sessao, Request $requisicao): Response
    {
        if (($falha = $this->senhaOuErro($sessao, $requisicao, 'backup')) !== null) {
            return $falha;
        }
        try {
            $info = $this->criador()->criar('manual');
        } catch (ErroDeBackup $e) {
            return $this->pagina($sessao, '', $e->getMessage(), $e->ocupado ? Response::HTTP_CONFLICT : Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->auditar($sessao, 'backup.criar', ['arquivo' => $info->arquivo, 'tipo' => 'manual']);

        return $this->voltar('criada');
    }

    private function salvarConfig(SessaoAtiva $sessao, Request $requisicao): Response
    {
        if (($falha = $this->senhaOuErro($sessao, $requisicao, 'backup')) !== null) {
            return $falha;
        }
        $atual = ConfigDeBackup::carregar($this->servicos->caminhos);
        $dias = [];
        $enviados = $requisicao->request->all()['dias'] ?? [];
        foreach (is_array($enviados) ? $enviados : [] as $d) {
            if (is_string($d) && preg_match('/^[1-7]$/D', $d) === 1) {
                $dias[] = (int) $d;
            }
        }
        $dias = array_values(array_unique($dias));
        sort($dias);
        $ultimas = $this->inteiro($this->texto($requisicao, 'reter_ultimas'));
        $diasDeRetencao = $this->inteiro($this->texto($requisicao, 'reter_dias'));
        $pasta = trim($this->texto($requisicao, 'pasta') ?? '');
        $erroPasta = $this->erroDeTexto($pasta, 300, 'A pasta');
        if ($erroPasta !== null || $ultimas === null || $diasDeRetencao === null) {
            return $this->pagina($sessao, '', $erroPasta ?? 'Informe números inteiros (0 ou mais) na retenção.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $nova = new ConfigDeBackup(
                $this->texto($requisicao, 'ativo') === '1',
                $this->texto($requisicao, 'frequencia') ?? '',
                $this->texto($requisicao, 'hora') ?? '',
                $dias === [] ? $atual->dias : $dias,
                $ultimas,
                $diasDeRetencao,
                $pasta === '' ? ConfigDeBackup::PASTA_PADRAO : $pasta,
                $atual->fuso,
            );
            ValidadorDePasta::resolver($this->servicos->caminhos, $nova->pasta);
            $nova->gravar($this->servicos->caminhos);
        } catch (\InvalidArgumentException|ErroDeBackup $e) {
            return $this->pagina($sessao, '', $this->mensagemDoRepositorio(new \InvalidArgumentException($e->getMessage())), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->auditar($sessao, 'backup.config', ['ativo' => $nova->ativo, 'frequencia' => $nova->frequencia]);

        return $this->voltar('config');
    }

    private function baixar(SessaoAtiva $sessao, Request $requisicao): Response
    {
        if (($falha = $this->senhaOuErro($sessao, $requisicao, 'backup')) !== null) {
            return $falha;
        }
        try {
            $arquivo = $this->catalogo()->caminhoDe($this->texto($requisicao, 'arquivo') ?? '');
        } catch (ErroDeBackup $e) {
            return $this->pagina($sessao, '', $e->getMessage(), Response::HTTP_NOT_FOUND);
        }
        $this->auditar($sessao, 'backup.baixar', ['arquivo' => basename($arquivo)]);
        $r = new StreamedResponse(static function () use ($arquivo): void {
            $f = fopen($arquivo, 'rb');
            if ($f === false) {
                return;
            }
            while (!feof($f)) {
                echo fread($f, 65536);
                flush();
            }
            fclose($f);
        }, Response::HTTP_OK, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . basename($arquivo) . '"',
            'Content-Length' => (string) filesize($arquivo),
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        return $r;
    }

    private function apagar(SessaoAtiva $sessao, Request $requisicao): Response
    {
        if (($falha = $this->senhaOuErro($sessao, $requisicao, 'backup')) !== null) {
            return $falha;
        }
        if ($this->texto($requisicao, 'confirmar') !== '1') {
            return $this->pagina($sessao, '', 'Marque a confirmação para apagar a cópia.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $nome = $this->texto($requisicao, 'arquivo') ?? '';
            $alvo = null;
            foreach ($this->catalogo()->listar(false) as $c) {
                if ($c->arquivo === $nome) {
                    $alvo = $c;
                }
            }
            if ($alvo === null) {
                throw new ErroDeBackup('Cópia não encontrada.');
            }
            if ($alvo->legado || $alvo->protegida) {
                throw new ErroDeBackup('Esta cópia é usada pelo "voltar atrás" da atualização e não pode ser apagada aqui.');
            }
            RetencaoDeBackups::aplicar($this->catalogo(), [$alvo]);
        } catch (ErroDeBackup $e) {
            return $this->pagina($sessao, '', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->auditar($sessao, 'backup.apagar', ['arquivo' => $nome]);

        return $this->voltar('apagada');
    }

    private function limpar(SessaoAtiva $sessao, Request $requisicao): Response
    {
        if (($falha = $this->senhaOuErro($sessao, $requisicao, 'backup')) !== null) {
            return $falha;
        }
        try {
            $config = ConfigDeBackup::carregar($this->servicos->caminhos);
            $catalogo = $this->catalogo();
            $plano = (new RetencaoDeBackups($config->reterUltimas, $config->reterDias))->planejar($catalogo->listar(), $this->agora());
            $n = RetencaoDeBackups::aplicar($catalogo, $plano);
        } catch (ErroDeBackup $e) {
            return $this->pagina($sessao, '', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->auditar($sessao, 'backup.limpar', ['apagadas' => $n]);

        return $this->voltar('limpa_' . min($n, 999));
    }

    private function restaurar(SessaoAtiva $sessao, Request $requisicao): Response
    {
        $nome = $this->texto($requisicao, 'arquivo') ?? '';
        if ($this->texto($requisicao, 'confirmar') !== '1' || $this->texto($requisicao, 'palavra') !== self::CONFIRMACAO) {
            return $this->formularioDeRestaurar($sessao, $nome, 'Marque o aviso e digite ' . self::CONFIRMACAO . ' para confirmar.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $falha = $this->exigirReautenticacao($sessao, $requisicao, 'backup-restaurar', true);
        if ($falha !== null) {
            return $this->aplicarFalhaDeReautenticacao($this->formularioDeRestaurar($sessao, $nome, $falha['mensagem'], $falha['status']), $falha);
        }
        try {
            $catalogo = $this->catalogo();
            $r = (new RestauradorDeBackup($this->servicos->caminhos, $catalogo, $this->criador()))->restaurar($nome);
        } catch (ErroDeBackup $e) {
            return $this->formularioDeRestaurar($sessao, $nome, $e->getMessage(), $e->ocupado ? Response::HTTP_CONFLICT : Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        // O banco foi trocado por baixo da conexão aberta desta requisição: audita numa conexão nova, sem deixar a falha (ex.: o usuário
        // não existir no banco restaurado) derrubar uma restauração que já deu certo.
        try {
            $novo = Servicos::doBancoInstalado($this->servicos->caminhos);
            $novo->auditoria->registrar('backup.restaurar', 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, null, ['arquivo' => $nome, 'versao' => $r['versao'], 'copia_anterior' => $r['copia_anterior']]);
        } catch (\Throwable) {
        }

        return $this->voltar('restaurada');
    }

    // ---------------------------------------------------------------- páginas

    private function pagina(SessaoAtiva $sessao, string $aviso, ?string $erro, int $status): Response
    {
        $csrf = $this->campoCsrf($sessao);
        $base = Painel::PREFIXO . '/copias';
        $config = ConfigDeBackup::carregar($this->servicos->caminhos);
        $copias = [];
        $espaco = '';
        $erroPasta = null;
        try {
            $copias = $this->catalogo()->listar();
            $espaco = $this->espaco($copias, ValidadorDePasta::resolver($this->servicos->caminhos, $config->pasta));
        } catch (ErroDeBackup $e) {
            $erroPasta = $e->getMessage();
        }
        $corpo = $aviso . $this->erro($erro)
            . ($erroPasta === null ? '' : $this->alerta('danger', 'Pasta de cópias com problema: ' . $erroPasta))
            . $this->secaoFazerAgora($sessao, $base, $csrf)
            . $this->secaoAgenda($sessao, $config, $base, $csrf)
            . $this->secaoLista($copias, $espaco, $sessao, $base, $csrf)
            . $this->card('Cópia para outro lugar pela rede', '<p class="mb-0">Desligada. O envio para SFTP ou S3 ainda não está disponível nesta versão; suas cópias ficam só neste servidor. Baixe uma cópia de vez em quando e guarde-a fora daqui.</p>', '', 'section', 'mb-4')
            . '<p class="mt-4"><a href="' . Painel::PREFIXO . '/">Voltar ao painel</a></p>';

        return Pagina::resposta('Cópias de segurança', $corpo, $status, false, $this->barra($sessao), [['Cópias de segurança', null]]);
    }

    private function secaoFazerAgora(SessaoAtiva $sessao, string $base, string $csrf): string
    {
        return '<section class="mb-4" aria-labelledby="titulo-agora" data-pbl-backup><h2 class="h5" id="titulo-agora">Fazer uma cópia agora</h2>'
            . '<p>Guarda o banco e os arquivos do site (<code>dados/</code>) num pacote. Pode levar alguns minutos em sites grandes: não feche a página.</p>'
            . '<form method="post" action="' . $base . '/criar" class="row g-3" data-pbl-backup-form>' . $csrf
            . '<div class="col-12 col-md-6">' . $this->campoSenha($sessao, 'campo-senha-criar') . '</div>'
            . '<div class="col-12"><button type="submit" class="btn btn-primary">Fazer agora</button></div>'
            . '<div class="col-12" hidden data-pbl-backup-andamento role="status" aria-live="polite"><p class="mb-1"><strong>Fazendo a cópia…</strong> Isso pode demorar. Não feche nem recarregue a página.</p>'
            . '<div class="progress" role="progressbar" aria-label="Fazendo a cópia" aria-valuetext="Em andamento"><div class="progress-bar progress-bar-striped progress-bar-animated w-100"></div></div></div>'
            . '</form></section>';
    }

    private function secaoAgenda(SessaoAtiva $sessao, ConfigDeBackup $c, string $base, string $csrf): string
    {
        $e = EstadoDoBackup::ler($this->servicos->caminhos);
        $situacao = '<p class="small text-body-secondary">'
            . ($c->ativo ? 'Cópia agendada <strong>ligada</strong>: ' . Pagina::h((new AgendaDeBackup($c))->texto()) . '.' : 'Cópia agendada <strong>desligada</strong>.')
            . ($e['em'] > 0 ? ' Última tentativa: ' . $this->quando($e['em'], $c->fuso) . ' — ' . ($e['ok'] ? 'ok' : 'falhou') . ($e['mensagem'] === '' ? '' : ' (' . Pagina::h($e['mensagem']) . ')') . '.' : '')
            . ' Depende do cron do servidor (veja <a href="' . Painel::PREFIXO . '/tarefas">Tarefas agendadas</a>).</p>';
        $dias = '';
        foreach (self::NOMES_DIAS as $n => $nome) {
            $dias .= '<div class="form-check form-check-inline">' . $this->checkbox('campo-dia-' . $n, 'dias[]', $nome, in_array($n, $c->dias, true), '', (string) $n) . '</div>';
        }
        $freq = '';
        foreach (['diaria' => 'Todos os dias', 'semanal' => 'Em dias da semana'] as $v => $r) {
            $freq .= Pagina::checkbox('campo-freq-' . $v, 'frequencia', $r, $c->frequencia === $v, '', $v, false, 'radio');
        }

        return '<section class="mb-4" aria-labelledby="titulo-agenda"><h2 class="h5" id="titulo-agenda">Cópia agendada e retenção</h2>' . $situacao
            . '<form method="post" action="' . $base . '/config" class="row g-3">' . $csrf
            . '<div class="col-12">' . $this->checkbox('campo-ativo', 'ativo', 'Fazer cópias automáticas', $c->ativo) . '</div>'
            . '<div class="col-12"><fieldset><legend class="h6">Frequência</legend>' . $freq . '</fieldset></div>'
            . '<div class="col-12 col-md-3">' . $this->campo('campo-hora', 'hora', 'Hora (fuso ' . $c->fuso . ')', $c->hora, ['tipo' => 'time', 'obrigatorio' => true]) . '</div>'
            . '<div class="col-12"><fieldset><legend class="h6">Dias da semana <span class="small text-body-secondary">(só na frequência semanal)</span></legend>' . $dias . '</fieldset></div>'
            . '<div class="col-6 col-md-3">' . $this->campo('campo-ultimas', 'reter_ultimas', 'Guardar as últimas', (string) $c->reterUltimas, ['tipo' => 'number', 'min' => 0, 'max' => 1000, 'inputmode' => 'numeric', 'obrigatorio' => true]) . '</div>'
            . '<div class="col-6 col-md-3">' . $this->campo('campo-reter-dias', 'reter_dias', 'Guardar por (dias)', (string) $c->reterDias, ['tipo' => 'number', 'min' => 0, 'max' => 3650, 'inputmode' => 'numeric', 'obrigatorio' => true, 'ajuda' => '0 = não limitar.']) . '</div>'
            . '<div class="col-12 col-md-8">' . $this->campo('campo-pasta', 'pasta', 'Pasta das cópias', $c->pasta, ['maxlength' => 300, 'ajuda' => 'Padrão: ' . ConfigDeBackup::PASTA_PADRAO . '. Pode ser uma pasta fora do site (caminho absoluto); precisa existir e ser gravável.']) . '</div>'
            . '<div class="col-12 col-md-6">' . $this->campoSenha($sessao, 'campo-senha-config') . '</div>'
            . '<div class="col-12">' . $this->acoes('Salvar') . '</div></form></section>';
    }

    /** @param list<InfoDeBackup> $copias */
    private function secaoLista(array $copias, string $espaco, SessaoAtiva $sessao, string $base, string $csrf): string
    {
        $linhas = '';
        foreach ($copias as $c) {
            $nome = Pagina::h($c->arquivo);
            $acoes = '';
            if (!$c->legado) {
                $acoes = '<form method="post" action="' . $base . '/baixar" class="d-inline">' . $csrf . '<input type="hidden" name="arquivo" value="' . $nome . '"><button type="submit" class="btn btn-sm btn-outline-secondary">Baixar</button></form> '
                    . ($c->integro === true ? '<a class="btn btn-sm btn-outline-warning" href="' . $base . '/restaurar?arquivo=' . rawurlencode($c->arquivo) . '">Restaurar</a> ' : '');
            }
            if (!$c->legado && !$c->protegida) {
                $acoes .= '<a class="btn btn-sm btn-outline-danger" href="' . $base . '/restaurar?arquivo=' . rawurlencode($c->arquivo) . '&amp;apagar=1">Apagar</a>';
            }
            $integra = $c->integro === null ? 'não conferida' : ($c->integro ? '<span class="badge text-bg-success">íntegra</span>' : '<span class="badge text-bg-danger">com problema</span>');
            $linhas .= '<tr><td data-rotulo="Data">' . $this->quando($c->criadoEm, ConfigDeBackup::FUSO_PADRAO) . '</td><td data-rotulo="Tipo">' . (self::TIPOS[$c->tipo] ?? Pagina::h($c->tipo)) . ($c->protegida ? ' <span class="badge text-bg-info">protegida</span>' : '') . '</td>'
                . '<td data-rotulo="Tamanho">' . $this->tamanho($c->tamanho) . '</td><td data-rotulo="Versão">' . Pagina::h($c->versao) . '</td>'
                . '<td data-rotulo="Integridade (SHA-256)">' . ($c->legado ? '<span class="text-body-secondary">cópia do atualizador</span>' : $integra) . '</td><td data-rotulo="Ações">' . $acoes . '</td></tr>';
        }
        $tabela = $copias === []
            ? '<p class="text-body-secondary">Nenhuma cópia ainda. Use "Fazer agora" acima.</p>'
            : '<table class="table align-middle"><caption class="visually-hidden">Cópias existentes</caption><thead><tr><th scope="col">Data</th><th scope="col">Tipo</th><th scope="col">Tamanho</th><th scope="col">Versão do PBL4</th><th scope="col">Integridade</th><th scope="col">Ações</th></tr></thead><tbody>' . $linhas . '</tbody></table>';

        return '<section class="mb-4" aria-labelledby="titulo-lista"><h2 class="h5" id="titulo-lista">Cópias no disco</h2>' . ($espaco === '' ? '' : '<p class="small text-body-secondary">' . $espaco . '</p>')
            . $tabela
            . '<form method="post" action="' . $base . '/limpar" class="row g-3">' . $csrf . '<div class="col-12 col-md-6">' . $this->campoSenha($sessao, 'campo-senha-limpar') . '</div>'
            . '<div class="col-12"><button type="submit" class="btn btn-outline-secondary">Limpar antigas</button> <span class="small text-body-secondary">Apaga o que passar da retenção; a cópia do "voltar atrás" nunca é apagada.</span></div></form></section>';
    }

    /** Confirmação de restaurar (ou de apagar, com `apagar=1`): tela própria, com o aviso do que se perde. */
    private function formularioDeRestaurar(SessaoAtiva $sessao, string $nome, ?string $erro, int $status): Response
    {
        $base = Painel::PREFIXO . '/copias';
        $csrf = $this->campoCsrf($sessao);
        $info = null;
        foreach ($this->catalogoOuVazio() as $c) {
            if ($c->arquivo === $nome && !$c->legado) {
                $info = $c;
            }
        }
        if ($info === null) {
            return Pagina::resposta('Cópia não encontrada', '<div class="alert alert-warning" role="alert">Cópia não encontrada.</div><p><a href="' . $base . '">Voltar às cópias</a></p>', Response::HTTP_NOT_FOUND, false, $this->barra($sessao), [['Cópias de segurança', $base], ['Cópia', null]]);
        }
        $quando = $this->quando($info->criadoEm, ConfigDeBackup::FUSO_PADRAO);
        $n = Pagina::h($info->arquivo);
        $corpo = $this->erro($erro)
            . '<dl class="row"><dt class="col-sm-3">Cópia</dt><dd class="col-sm-9"><code>' . $n . '</code></dd><dt class="col-sm-3">Feita em</dt><dd class="col-sm-9">' . $quando . '</dd>'
            . '<dt class="col-sm-3">Versão do PBL4</dt><dd class="col-sm-9">' . Pagina::h($info->versao) . '</dd></dl>'
            . $this->alerta('warning', 'Restaurar volta o site ao estado de ' . $quando . ': tudo o que foi criado depois — conteúdo, uploads, contas e configurações — será perdido. Antes, o PBL4 guarda uma cópia do estado atual (tipo "pré-restauração"), confere o pacote (SHA-256 e esquema do banco) e põe o site em manutenção por alguns instantes.')
            . '<form method="post" action="' . $base . '/restaurar" class="row g-3" data-pbl-backup-form>' . $csrf . '<input type="hidden" name="arquivo" value="' . $n . '">'
            . '<div class="col-12 col-md-6"><label for="campo-senha-restaurar" class="form-label">Sua senha, para confirmar</label><input id="campo-senha-restaurar" class="form-control" name="senha" type="password" autocomplete="current-password" maxlength="1024" required></div>'
            . '<div class="col-12 col-md-6">' . $this->campo('campo-palavra', 'palavra', 'Digite ' . self::CONFIRMACAO . ' para confirmar', '', ['obrigatorio' => true, 'autocomplete' => 'off', 'maxlength' => 20]) . '</div>'
            . '<div class="col-12">' . $this->checkbox('campo-confirmar', 'confirmar', 'Entendo que o que foi criado depois desta cópia será perdido.', false) . '</div>'
            . '<div class="col-12"><button type="submit" class="btn btn-danger">Restaurar esta cópia</button> <a class="btn btn-outline-secondary" href="' . $base . '">Cancelar</a></div>'
            . '<div class="col-12" hidden data-pbl-backup-andamento role="status" aria-live="polite"><p class="mb-1"><strong>Restaurando…</strong> Não feche a página.</p>'
            . '<div class="progress" role="progressbar" aria-label="Restaurando" aria-valuetext="Em andamento"><div class="progress-bar progress-bar-striped progress-bar-animated w-100"></div></div></div></form>'
            . '<hr><h2 class="h5">Ou apagar esta cópia</h2>'
            . ($info->protegida ? '<p>Esta cópia é protegida e não pode ser apagada.</p>' : '<form method="post" action="' . $base . '/apagar" class="row g-3">' . $csrf . '<input type="hidden" name="arquivo" value="' . $n . '">'
                . '<div class="col-12 col-md-6">' . $this->campoSenha($sessao, 'campo-senha-apagar') . '</div>'
                . '<div class="col-12">' . $this->checkbox('campo-apagar', 'confirmar', 'Quero apagar esta cópia para sempre.', false) . '</div>'
                . '<div class="col-12"><button type="submit" class="btn btn-outline-danger">Apagar cópia</button></div></form>');

        return Pagina::resposta('Restaurar cópia', $corpo, $status, false, $this->barra($sessao), [['Cópias de segurança', $base], ['Restaurar', null]]);
    }

    // ---------------------------------------------------------------- apoio

    private function senhaOuErro(SessaoAtiva $sessao, Request $requisicao, string $prefixo): ?Response
    {
        $falha = $this->exigirReautenticacao($sessao, $requisicao, $prefixo);
        if ($falha === null) {
            return null;
        }

        return $this->aplicarFalhaDeReautenticacao($this->pagina($sessao, '', $falha['mensagem'], $falha['status']), $falha);
    }

    private function criador(): CriadorDeBackup
    {
        return new CriadorDeBackup($this->servicos->caminhos, ConfigDeBackup::carregar($this->servicos->caminhos), relogio: $this->relogio);
    }

    private function catalogo(): CatalogoDeBackups
    {
        return new CatalogoDeBackups($this->servicos->caminhos, ConfigDeBackup::carregar($this->servicos->caminhos));
    }

    /** @return list<InfoDeBackup> */
    private function catalogoOuVazio(): array
    {
        try {
            return $this->catalogo()->listar(false);
        } catch (ErroDeBackup) {
            return [];
        }
    }

    private function nomeDaConsulta(Request $requisicao): string
    {
        $n = $requisicao->query->all()['arquivo'] ?? '';

        return is_string($n) ? $n : '';
    }

    /** @param array<string, scalar> $detalhe sem segredos */
    private function auditar(SessaoAtiva $sessao, string $acao, array $detalhe): void
    {
        $this->servicos->auditoria->registrar($acao, 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, null, $detalhe);
    }

    private function voltar(string $codigo): Response
    {
        return new RedirectResponse(Painel::PREFIXO . '/copias?ok=' . $codigo, Response::HTTP_SEE_OTHER);
    }

    private function aviso(Request $requisicao): string
    {
        $ok = $requisicao->query->all()['ok'] ?? null;
        $texto = match (true) {
            $ok === 'criada' => 'Cópia criada.',
            $ok === 'config' => 'Configuração salva.',
            $ok === 'apagada' => 'Cópia apagada.',
            $ok === 'restaurada' => 'Cópia restaurada. O estado anterior ficou guardado como cópia "pré-restauração".',
            is_string($ok) && preg_match('/^limpa_(\d{1,3})$/D', $ok, $m) === 1 => (int) $m[1] . ' cópia(s) antiga(s) apagada(s).',
            default => null,
        };

        return $texto === null ? '' : '<div class="alert alert-success" role="status">' . Pagina::h($texto) . '</div>';
    }

    private function inteiro(?string $v): ?int
    {
        return $v !== null && preg_match('/^\d{1,4}$/D', $v) === 1 ? (int) $v : null;
    }

    private function agora(): int
    {
        return $this->relogio === null ? time() : ($this->relogio)();
    }

    private function quando(int $ts, string $fuso): string
    {
        return Pagina::h((new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone($fuso))->format('d/m/Y H:i'));
    }

    private function tamanho(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $i => $u) {
            if ($bytes < 1024 ** ($i + 1) || $u === 'GB') {
                return ($i === 0 ? (string) $bytes : number_format($bytes / 1024 ** $i, 1, ',', '.')) . ' ' . $u;
            }
        }

        return $bytes . ' B';
    }

    /** @param list<InfoDeBackup> $copias */
    private function espaco(array $copias, string $pasta): string
    {
        $usado = array_sum(array_map(static fn (InfoDeBackup $c): int => $c->tamanho, $copias));
        $livre = ValidadorDePasta::espacoLivre($pasta);

        return 'Espaço usado pelas cópias: <strong>' . $this->tamanho($usado) . '</strong>; livre no disco: <strong>' . ($livre === null ? 'não informado' : $this->tamanho($livre)) . '</strong>.';
    }
}
