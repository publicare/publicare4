<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Perfil;
use Pbl\Acesso\SessaoRepositorio;
use Pbl\Aplicacao;
use Pbl\Caminhos;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Instalação de teste e atalhos HTTP para os testes das telas de contas e de domínios (fase 5d). */
trait AdminAuxiliar
{
    private const SENHA = 'uma senha bem longa 2026';
    private const ORIGEM = ['HTTP_ORIGIN' => 'https://www.exemplo.org'];
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;
    private int $siteId;
    private int $inicioId;
    /** @var array<string, int> login => id */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Meu Site', 'www.exemplo.org');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $site = $this->s->sites->porHost('www.exemplo.org');
        self::assertNotNull($site);
        $this->siteId = $site->id;
        $this->inicioId = (int) $site->objetoRaizId;
        $u = $this->s->usuarios;
        $this->ids['admin'] = $u->criar('admin', 'admin@exemplo.org', 'Admin', self::SENHA, true)->id;
        $this->ids['admin2'] = $u->criar('admin2', 'admin2@exemplo.org', 'Segundo Admin', self::SENHA, true)->id;
        $this->ids['editor'] = $u->criar('editor', 'editor@exemplo.org', 'Editor', self::SENHA)->id;
        $this->ids['leitor'] = $u->criar('leitor', 'leitor@exemplo.org', 'Leitor', self::SENHA)->id;
        $u->conceder($this->ids['editor'], $this->inicioId, Perfil::Editor);
        $u->conceder($this->ids['leitor'], $this->inicioId, Perfil::Leitor);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function token(string $login): string
    {
        return $this->s->sessoes()->criar($this->ids[$login]);
    }

    private function csrf(string $token): string
    {
        return $this->s->csrf()->daSessao(SessaoRepositorio::hash($token));
    }

    /**
     * @param array<string, mixed> $campos
     * @param array<string, string> $cabecalhos
     */
    private function req(string $metodo, string $caminho, ?string $token = null, array $campos = [], array $cabecalhos = []): Response
    {
        $cookies = $token === null ? [] : ['__Host-pbl4' => $token];
        $r = Request::create('https://www.exemplo.org' . $caminho, $metodo, $campos, $cookies, [], ['REMOTE_ADDR' => '198.51.100.7'] + $cabecalhos);

        return (new Aplicacao($this->caminhos))->tratar($r);
    }

    /** @param array<string, mixed> $campos */
    private function post(string $token, string $caminho, array $campos, bool $comCsrf = true): Response
    {
        return $this->req('POST', $caminho, $token, ($comCsrf ? ['_csrf' => $this->csrf($token)] : []) + $campos, self::ORIGEM);
    }

    private function html(Response $r): string
    {
        return (string) $r->getContent();
    }

    /** @return list<array{quando: string, ator: string, acao: string, objeto_id: ?int, detalhe: ?string}> */
    private function auditoria(string $acao): array
    {
        return array_values(array_filter($this->s->auditoria->recentes(500), static fn (array $l): bool => $l['acao'] === $acao));
    }

    /** A sessão ainda vale? (o painel responde 200; sem sessão, 303 para entrar) */
    private function sessaoViva(string $token): bool
    {
        return $this->req('GET', '/_pbl/', $token)->getStatusCode() === 200;
    }
}
