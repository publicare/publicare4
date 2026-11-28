<?php

declare(strict_types=1);

namespace Pbl\Instalacao;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\Segredo;
use Pbl\Acesso\SenhaPolitica;
use Pbl\Admin\Pagina;
use Pbl\Banco\ConfigBanco;
use Pbl\Banco\ConfigDeBancoInvalida;
use Pbl\Banco\Conexao;
use Pbl\Banco\ErroDeConexao;
use Pbl\Banco\GravadorDeConfigBanco;
use Pbl\Caminhos;
use Pbl\Conteudo\Modelos;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assistente de instalação pelo navegador, em `/_pbl/instalar` (fluxo 3). Só existe enquanto o PBL4 não está instalado.
 *
 * Um instalador aberto deixaria qualquer visitante assumir um site recém-enviado. Por isso exige **prova de posse**: quem
 * instala cria um arquivo vazio `dados/instalar.liberar` (só quem tem acesso à pasta, por FTP ou painel, consegue). Além
 * disso: CSRF, conferência de origem, tudo escapado, e a instalação é atômica (se algo falha, dá para tentar de novo).
 */
final class InstaladorWeb
{
    public const PREFIXO = '/_pbl/instalar';
    public const ARQUIVO_DE_POSSE = 'instalar.liberar';

    public function __construct(private readonly Caminhos $caminhos)
    {
    }

    public static function atende(Request $requisicao): bool
    {
        $caminho = rtrim($requisicao->getPathInfo(), '/');

        return $caminho === self::PREFIXO;
    }

    public function tratar(Request $requisicao): Response
    {
        $rede = new Rede($this->caminhos);
        $seguro = $rede->seguro($requisicao);
        if ((new Instalador($this->caminhos))->instalado()) {
            return Pagina::resposta('Não encontrado', '<p>Página não encontrada.</p>', Response::HTTP_NOT_FOUND, $seguro);
        }
        $requisitos = new Requisitos($this->caminhos);
        if (!$requisitos->tudoCerto()) {
            return $this->resposta($requisitos, $requisicao, $seguro, null, [], Response::HTTP_OK);
        }
        if (!in_array($requisicao->getMethod(), ['GET', 'POST'], true)) {
            return Pagina::resposta('Não encontrado', '<p>Página não encontrada.</p>', Response::HTTP_NOT_FOUND, $seguro);
        }
        try {
            $csrf = new Csrf(Segredo::obter($this->caminhos));
        } catch (\RuntimeException $e) {
            return Pagina::resposta('Não foi possível preparar a instalação', '<p class="erro">' . Pagina::h($e->getMessage()) . '</p>', Response::HTTP_INTERNAL_SERVER_ERROR, $seguro);
        }
        if ($requisicao->getMethod() === 'POST') {
            return $this->instalar($requisicao, $requisitos, $rede, $csrf, $seguro);
        }

        return $this->resposta($requisitos, $requisicao, $seguro, $csrf, []);
    }

    private function instalar(Request $requisicao, Requisitos $requisitos, Rede $rede, Csrf $csrf, bool $seguro): Response
    {
        if (!$this->temPosse() || !$rede->origemConfere($requisicao, true) || !$csrf->conferirEntrar((string) $requisicao->request->get('_csrf', ''))) {
            return Pagina::resposta('Pedido recusado', '<p>O pedido não pôde ser confirmado. Volte, confira a prova de posse e tente de novo.</p>', Response::HTTP_FORBIDDEN, $seguro);
        }
        $campos = [
            'site' => mb_substr(trim((string) $requisicao->request->get('site', '')), 0, 200),
            'dominio' => mb_substr(strtolower(trim((string) $requisicao->request->get('dominio', ''))), 0, 253),
            'login' => mb_substr(trim((string) $requisicao->request->get('login', '')), 0, 64),
            'email' => mb_substr(trim((string) $requisicao->request->get('email', '')), 0, 255),
            'nome' => mb_substr(trim((string) $requisicao->request->get('nome', '')), 0, 200),
        ];
        $campos += [
            'banco_tipo' => in_array((string) $requisicao->request->get('banco_tipo', 'sqlite'), ['sqlite', 'mysql', 'pgsql'], true) ? (string) $requisicao->request->get('banco_tipo', 'sqlite') : 'sqlite',
            'banco_host' => mb_substr(trim((string) $requisicao->request->get('banco_host', '')), 0, 253),
            'banco_porta' => mb_substr(trim((string) $requisicao->request->get('banco_porta', '')), 0, 5),
            'banco_nome' => mb_substr(trim((string) $requisicao->request->get('banco_nome', '')), 0, 64),
            'banco_usuario' => mb_substr(trim((string) $requisicao->request->get('banco_usuario', '')), 0, 64),
        ];
        $bancoSenha = mb_substr((string) $requisicao->request->get('banco_senha', ''), 0, 1024);
        $marcados = $requisicao->request->all('modelos');
        $modelos = array_values(array_filter(Modelos::NOMES, static fn (string $m): bool => in_array($m, $marcados, true)));
        $campos['modelos'] = implode(',', $modelos);
        $senha = mb_substr((string) $requisicao->request->get('senha', ''), 0, SenhaPolitica::MAXIMO + 1);
        $senha2 = mb_substr((string) $requisicao->request->get('senha2', ''), 0, SenhaPolitica::MAXIMO + 1);

        $erro = null;
        if ($senha !== $senha2) {
            $erro = 'As duas senhas não são iguais.';
        } else {
            $erro = SenhaPolitica::problema($senha, $campos['login'], $campos['email']);
        }
        if ($erro === null) {
            $erro = $this->prepararBanco($campos, $bancoSenha);
        }
        if ($erro === null) {
            try {
                (new Instalador($this->caminhos))->instalar($campos['site'], $campos['dominio'], [
                    'login' => $campos['login'], 'email' => $campos['email'], 'nome' => $campos['nome'] === '' ? $campos['login'] : $campos['nome'], 'senha' => $senha,
                ], $modelos, PelesDistribuidas::PADRAO);
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                $erro = $e->getMessage();
            }
        }
        if ($erro !== null) {
            return $this->resposta($requisitos, $requisicao, $seguro, $csrf, $campos, Response::HTTP_UNPROCESSABLE_ENTITY, $erro);
        }

        // Instalado: publica as páginas de exemplo e desliga a prova de posse (o instalador também some pela trava).
        $aviso = '';
        try {
            $servicos = Servicos::doBancoInstalado($this->caminhos);
            foreach ($servicos->sites->todos() as $site) {
                $servicos->publicador->publicarSite($site);
            }
        } catch (\Throwable) {
            // O site já está instalado (o instalador não volta): a publicação pode ser refeita depois, sem refazer nada.
            $aviso = Pagina::alerta('warning', 'O PBL4 foi instalado, mas não conseguiu publicar as páginas de exemplo agora. '
                . 'Dê permissão de escrita à pasta <code>dados/cache/</code> e rode <code>php bin/pbl publicar</code> (ou republique pela administração).', '', true);
        }
        @unlink($this->caminhos->dados() . '/' . self::ARQUIVO_DE_POSSE);

        return Pagina::resposta('PBL4 instalado', PassosDoInstalador::barra(PassosDoInstalador::PRONTO)
            . ProximosPassos::html($this->caminhos, $campos['login'], $aviso, (string) $requisicao->server->get('DOCUMENT_ROOT', ''), $seguro), Response::HTTP_OK, $seguro);
    }

    /**
     * @param array<string, string> $campos
     */
    private function resposta(Requisitos $requisitos, Request $requisicao, bool $seguro, ?Csrf $csrf, array $campos, int $status = Response::HTTP_OK, ?string $erro = null): Response
    {
        $linhas = '';
        foreach ($requisitos->verificar() as $item) {
            $marca = $item['ok'] ? '✔' : ($item['obrigatorio'] ? '✘' : '⚠');
            $legenda = $item['ok'] ? 'Certo' : ($item['obrigatorio'] ? 'Corrigir' : 'Aviso');
            $linhas .= '<tr><td class="text-center ' . ($item['ok'] ? 'ok' : ($item['obrigatorio'] ? 'no' : 'aviso')) . '"><span aria-hidden="true">' . $marca . '</span><span class="visually-hidden">' . $legenda . '</span></td>'
                . '<td><strong>' . Pagina::h($item['nome']) . '</strong><br>' . Pagina::h($item['detalhe']) . '</td></tr>';
        }
        $tabela = '<div class="table-responsive"><table class="table table-sm pbl-requisitos"><caption class="visually-hidden">Requisitos do servidor</caption><tbody>' . $linhas . '</tbody></table></div>';
        $titulo1 = '<h2 class="h5" id="passo-1-titulo" tabindex="-1">1. Boas-vindas e requisitos do servidor</h2>'
            . '<p>Vamos instalar o Publicare 4 em poucos passos. Primeiro, conferimos se o servidor tem o que é preciso (<strong>Requisitos do servidor</strong>).</p>';
        $barra = PassosDoInstalador::barra(PassosDoInstalador::REQUISITOS);
        if (!$requisitos->tudoCerto() || $csrf === null) {
            return Pagina::resposta('Instalar o PBL4', $barra . $titulo1 . $tabela . Pagina::alerta('danger', 'Corrija os itens marcados com ✘ e recarregue esta página. Os marcados com ⚠ são avisos: não impedem a instalação.'), $status, $seguro);
        }
        if (!$this->temPosse()) {
            return Pagina::resposta('Instalar o PBL4', $barra . $titulo1 . $tabela
                . '<h2 class="h5 mt-4">Prove que você é o dono do servidor</h2>'
                . '<p>Para ninguém mais poder instalar no seu lugar, crie um arquivo <strong>vazio</strong> chamado <code>' . self::ARQUIVO_DE_POSSE
                . '</code> dentro da pasta <code>dados/</code> do site (por FTP ou pelo painel da hospedagem) e recarregue esta página.</p>'
                . '<p class="text-body-secondary">Se você instalou com o <code>instalar.php</code>, este arquivo já foi criado por ele: se esta mensagem aparece, ele ainda não existe; crie-o à mão.</p>', $status, $seguro);
        }
        $erroPasso = $erro === null ? null : PassosDoInstalador::passoDoErro($erro);
        $passoInicial = $erroPasso ?? PassosDoInstalador::REQUISITOS;
        $modelosMarcados = array_values(array_filter(explode(',', $campos['modelos'] ?? '')));
        $v = static fn (string $campo, string $padrao = ''): string => $campos[$campo] ?? $padrao;
        $nav = static fn (string $classe = ''): string => '<div class="pbl-nav-js d-grid d-sm-flex gap-2 mt-3' . $classe . '" hidden>'
            . '<button type="button" class="btn btn-outline-secondary btn-lg" data-pbl-voltar>Voltar</button>'
            . '<button type="button" class="btn btn-primary btn-lg" data-pbl-proximo>Continuar</button></div>';
        $painel = static fn (int $n, string $conteudo, string $extra = ''): string => '<section class="pbl-painel" data-passo="' . $n . '" aria-labelledby="passo-' . $n . '-titulo"' . $extra . '>' . $conteudo . '</section>';
        $h2 = static fn (int $n, string $texto): string => '<h2 class="h5" id="passo-' . $n . '-titulo" tabindex="-1">' . $n . '. ' . Pagina::h($texto) . '</h2>';

        $erroHtml = $erro === null ? '' : Pagina::alerta('danger', $erro . ' ' . PassosDoInstalador::comoCorrigir($erro) . ' (Passo ' . $erroPasso . ': ' . (PassosDoInstalador::TITULOS[$erroPasso] ?? '') . '.)', 'erro-formulario');
        $corpo = PassosDoInstalador::barra($passoInicial) . $erroHtml
            . '<form method="post" action="' . self::PREFIXO . '" autocomplete="off" data-pbl-instalar data-passo-inicial="' . $passoInicial . '"' . ($erro === null ? '' : ' aria-describedby="erro-formulario"') . '>'
            . '<input type="hidden" name="_csrf" value="' . Pagina::h($csrf->paraEntrar()) . '">'
            . $painel(1, $titulo1 . $tabela . '<p class="ok"><span aria-hidden="true">✔</span> Arquivo encontrado: <code>' . self::ARQUIVO_DE_POSSE . '</code>. Você provou que é o dono do servidor.</p>' . $nav(' pbl-nav-primeiro'))
            . $painel(2, $h2(2, 'Seu site: nome e endereço')
                . '<p class="text-body-secondary">O nome aparece no topo do site (exemplo: Prefeitura de Exemplo). O endereço (domínio) é o que as pessoas digitam no navegador: só o endereço, sem https:// nem barra no fim (exemplo: www.meusite.org.br). Já preenchemos com o que você usou para chegar aqui.</p>'
                . Pagina::campo('i-site', 'site', 'Nome do site', $v('site'), ['maxlength' => 200, 'obrigatorio' => true, 'ajuda' => 'Exemplo: Prefeitura de Exemplo.'])
                . Pagina::campo('i-dominio', 'dominio', 'Endereço (domínio) do site', $v('dominio', $requisicao->getHost()), ['maxlength' => 253, 'obrigatorio' => true, 'ajuda' => 'Só o endereço, sem https:// nem barra no fim. Já preenchemos com o que você usou para chegar aqui.'])
                . $nav())
            . $painel(3, $h2(3, 'Conta do administrador')
                . '<p class="text-body-secondary">Esta é a conta com acesso a tudo; você poderá criar outras depois. O login tem de 3 a 64 letras minúsculas, números, ponto, hífen ou sublinhado, sem espaços (exemplo: ana.silva). A senha precisa de pelo menos ' . SenhaPolitica::MINIMO . ' caracteres: uma frase longa vale mais que símbolos.</p>'
                . Pagina::campo('i-login', 'login', 'Login do administrador', $v('login'), ['maxlength' => 64, 'obrigatorio' => true, 'autocomplete' => 'username', 'ajuda' => 'De 3 a 64 letras minúsculas, números, ponto, hífen ou sublinhado. Exemplo: ana.silva.'])
                . Pagina::campo('i-nome', 'nome', 'Nome', $v('nome'), ['maxlength' => 200, 'autocomplete' => 'name', 'ajuda' => 'Opcional: como você quer ser chamado.'])
                . Pagina::campo('i-email', 'email', 'E-mail', $v('email'), ['tipo' => 'email', 'maxlength' => 255, 'obrigatorio' => true, 'autocomplete' => 'email'])
                . Pagina::campo('i-senha', 'senha', 'Senha', '', ['tipo' => 'password', 'obrigatorio' => true, 'autocomplete' => 'new-password', 'ajuda' => 'Mínimo de ' . SenhaPolitica::MINIMO . ' caracteres. Uma frase longa vale mais que símbolos.'])
                . '<p class="pbl-forca" id="pbl-forca" aria-live="polite" data-pbl-forca></p>'
                . Pagina::campo('i-senha2', 'senha2', 'Repita a senha', '', ['tipo' => 'password', 'obrigatorio' => true, 'autocomplete' => 'new-password'])
                . '<div class="form-check mb-3 pbl-mostrar-js" hidden><input class="form-check-input" type="checkbox" id="i-mostrar" data-pbl-mostrar-senha><label class="form-check-label" for="i-mostrar">Mostrar a senha enquanto digito</label></div>'
                . $nav())
            . $painel(4, $h2(4, 'Modelos opcionais')
                . '<p class="text-body-secondary">Tipos de conteúdo extras, com exemplos. Pode pular: dá para ligar depois, em Modelos, na administração.</p>' . $this->caixasDeModelos($modelosMarcados) . $nav())
            . $painel(5, $h2(5, 'Banco de dados') . $this->painelDoBanco(Pagina::campo(...), $campos) . $nav())
            . $painel(6, $h2(6, 'Revisão e instalar')
                . '<p>Confira os dados. Se algo estiver errado, use <strong>Voltar</strong>. Ao clicar em <strong>Instalar</strong>, o Publicare cria o banco, o site e a sua conta. Se algo falhar, nada fica pela metade e você pode tentar de novo.</p>'
                . '<dl class="pbl-revisao" data-pbl-revisao><dt>Resumo</dt><dd>Confira os campos dos passos anteriores e clique em Instalar.</dd></dl>'
                . '<div class="pbl-acoes pbl-acoes-instalar"><button type="button" class="btn btn-outline-secondary btn-lg" data-pbl-voltar hidden>Voltar</button><button type="submit" class="btn btn-success btn-lg">Instalar</button></div>')
            . '</form><noscript><p class="text-body-secondary mt-3">Sem JavaScript, todos os passos aparecem de uma vez: preencha tudo e clique em Instalar no fim.</p></noscript>';

        return Pagina::resposta('Instalar o PBL4', $corpo, $status, $seguro);
    }

    /**
     * Escolha do banco (passo 5). SQLite é o padrão e não precisa de nada. Nos outros, testa a conexão e, só então, grava o `banco.json`
     * (0600); a senha do banco nunca volta para a tela.
     *
     * @param array<string, string> $c
     * @return string|null mensagem de erro, ou null se o banco está pronto para a instalação
     */
    private function prepararBanco(array $c, string $senha): ?string
    {
        $arquivo = $this->caminhos->config() . '/banco.json';
        if ($c['banco_tipo'] === 'sqlite') {
            @unlink($arquivo); // uma tentativa anterior com outro banco não deve mandar nesta
            return null;
        }
        try {
            $config = new ConfigBanco($c['banco_tipo'], $c['banco_nome'], $c['banco_host'], $c['banco_porta'] === '' ? null : (int) $c['banco_porta'], $c['banco_usuario'], $senha);
            $conexao = Conexao::abrir($config);
            $conexao->fetchOne('SELECT 1');
            if ($conexao->createSchemaManager()->tablesExist(['objeto'])) {
                return 'Esse banco já tem um site do Publicare instalado.';
            }
        } catch (ConfigDeBancoInvalida | ErroDeConexao $e) {
            return 'Não deu para usar o banco de dados: ' . $e->getMessage();
        } catch (\Throwable) {
            return 'Não deu para usar o banco de dados. Confira servidor, nome, usuário e senha.';
        }
        GravadorDeConfigBanco::gravar($this->caminhos, $config);

        return null;
    }

    /** @param array<string, string> $v */
    private function painelDoBanco(callable $campo, array $v): string
    {
        $tipo = $v['banco_tipo'] ?? 'sqlite';
        $opcao = static fn (string $valor, string $rotulo, bool $ok): string => '<option value="' . $valor . '"' . ($tipo === $valor ? ' selected' : '') . ($ok ? '' : ' disabled') . '>' . Pagina::h($rotulo . ($ok ? '' : ' (indisponível neste servidor)')) . '</option>';

        return '<p class="text-body-secondary">Onde o Publicare guarda os textos e as contas. O SQLite não precisa de servidor nem de senha: serve para a maioria dos sites. Escolha outro só se a hospedagem já oferece um banco para você.</p>'
            . '<div class="mb-3"><label class="form-label" for="i-banco-tipo">Banco de dados</label>'
            . '<select class="form-select" id="i-banco-tipo" name="banco_tipo" data-pbl-banco-tipo>'
            . $opcao('sqlite', 'SQLite (padrão, sem servidor)', extension_loaded('pdo_sqlite'))
            . $opcao('mysql', 'MySQL ou MariaDB', extension_loaded('pdo_mysql'))
            . $opcao('pgsql', 'PostgreSQL', extension_loaded('pdo_pgsql'))
            . '</select></div>'
            . '<div data-pbl-banco-servidor' . ($tipo === 'sqlite' ? ' hidden' : '') . '>'
            . $campo('i-banco-host', 'banco_host', 'Servidor', $v['banco_host'] ?? '', ['maxlength' => 253, 'ajuda' => 'Exemplo: localhost, ou o endereço que a hospedagem informou.'])
            . $campo('i-banco-porta', 'banco_porta', 'Porta', $v['banco_porta'] ?? '', ['maxlength' => 5, 'ajuda' => 'Deixe em branco para usar a padrão (3306 no MySQL, 5432 no PostgreSQL).'])
            . $campo('i-banco-nome', 'banco_nome', 'Nome do banco', $v['banco_nome'] ?? '', ['maxlength' => 64, 'ajuda' => 'O banco precisa existir e estar vazio.'])
            . $campo('i-banco-usuario', 'banco_usuario', 'Usuário do banco', $v['banco_usuario'] ?? '', ['maxlength' => 64, 'autocomplete' => 'off'])
            . $campo('i-banco-senha', 'banco_senha', 'Senha do banco', '', ['tipo' => 'password', 'autocomplete' => 'new-password', 'ajuda' => 'Fica guardada só no servidor, em dados/config/, com acesso restrito ao dono.'])
            . '</div>';
    }

    /** @param list<string> $marcados */
    private function caixasDeModelos(array $marcados): string
    {
        $html = '';
        foreach (Modelos::catalogo() as $m) {
            $id = 'i-modelo-' . $m['nome'];
            $html .= '<div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="' . $id . '" name="modelos[]" value="' . Pagina::h($m['nome']) . '"' . (in_array($m['nome'], $marcados, true) ? ' checked' : '') . '>'
                . '<label class="form-check-label" for="' . $id . '"><strong>' . Pagina::h($m['titulo']) . '</strong><br><span class="text-body-secondary">' . Pagina::h($m['descricao']) . '</span></label></div>';
        }

        return $html;
    }

    private function temPosse(): bool
    {
        return is_file($this->caminhos->dados() . '/' . self::ARQUIVO_DE_POSSE);
    }
}
