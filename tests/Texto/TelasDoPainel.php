<?php

declare(strict_types=1);

namespace Pbl\Testes\Texto;

use Pbl\Aplicacao;
use Pbl\Caminhos;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use Pbl\Testes\Ajuda;
use Symfony\Component\HttpFoundation\Request;

/**
 * Instala um site descartável e renderiza as telas do painel como administrador (só leitura), para medir e vigiar o texto.
 * Usada por `tests/TextoDoPainelTest.php` e por `bin/auditar-texto`.
 */
final class TelasDoPainel
{
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;
    private string $token;

    public function __construct()
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Meu Site', 'www.exemplo.org');
        @unlink($this->caminhos->config() . '/atualizacao.json');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
    }

    public function __destruct()
    {
        Ajuda::apagar($this->raiz);
    }

    /**
     * Nome da tela => HTML. Telas sem sessão (entrar) vêm antes de a sessão existir.
     *
     * @return array<string, string>
     */
    public function renderizar(): array
    {
        $site = $this->s->sites->porHost('www.exemplo.org') ?? throw new \RuntimeException('site');
        $inicio = (int) $site->objetoRaizId;
        $admin = $this->s->usuarios->criar('admin', 'admin@exemplo.org', 'Admin', 'uma senha bem longa 2026', true);
        $this->token = $this->s->sessoes()->criar($admin->id);
        $sessao = $this->s->sessoes()->validar($this->token);
        if ($sessao !== null) {
            $this->s->modoElevado()->abrirParaTestes($sessao);
        }
        $classe = $this->s->classes->porNome('pagina');
        $s = $site->id;
        $rotas = [
            'Entrar' => '/_pbl/entrar',
            'Início' => '/_pbl/',
            'Conteúdo' => '/_pbl/conteudo/' . $inicio,
            'Edição' => '/_pbl/objeto/' . $inicio . '/editar',
            'Nova página' => '/_pbl/objeto/' . $inicio . '/criar',
            'Configurações' => "/_pbl/site/$s/configuracoes",
            'Peles' => "/_pbl/site/$s/peles",
            'Redirecionamentos' => "/_pbl/site/$s/redirecionamentos",
            'Busca' => "/_pbl/site/$s/busca",
            'Publicação' => "/_pbl/site/$s/publicacao",
            'Domínios' => "/_pbl/site/$s/dominios",
            'Atualização' => '/_pbl/atualizacao',
            'Cópias de segurança' => '/_pbl/copias',
            'Tarefas agendadas' => '/_pbl/tarefas',
            'Contas' => '/_pbl/contas',
            'Nova conta' => '/_pbl/contas/nova',
            'Tipos de conteúdo' => '/_pbl/classes',
            'Novo tipo' => '/_pbl/classes/nova',
            'Moldes' => '/_pbl/modelos',
            'Revisão' => '/_pbl/revisar',
            'Pendências' => '/_pbl/pendencias',
            'Vencidos' => '/_pbl/vencidos',
            'Lixeira' => '/_pbl/lixeira',
            'Mais' => '/_pbl/mais',
        ];
        if ($classe !== null) {
            $rotas['Tipo (edição)'] = '/_pbl/classe/' . $classe->id;
        }
        $telas = [];
        foreach ($rotas as $nome => $caminho) {
            $semSessao = $nome === 'Entrar';
            $r = (new Aplicacao($this->caminhos))->tratar(Request::create(
                'https://www.exemplo.org' . $caminho,
                'GET',
                [],
                $semSessao ? [] : ['__Host-pbl4' => $this->token],
                [],
                ['REMOTE_ADDR' => '198.51.100.7'],
            ));
            if ($r->getStatusCode() === 200) {
                $telas[$nome] = (string) $r->getContent();
            }
        }

        return $telas;
    }
}
