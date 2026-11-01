<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Aplicacao;
use Pbl\Banco\Conexao;
use Pbl\Caminhos;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** Fase 0: instalar → criar classe/objeto → renderizar → publicar → servir do cache. */
final class TracerBulletTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Site de teste', 'teste.local');
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function aplicacao(): Aplicacao
    {
        return new Aplicacao($this->caminhos);
    }

    private function pegar(string $url, string $host = 'teste.local'): \Symfony\Component\HttpFoundation\Response
    {
        return $this->aplicacao()->tratar(Request::create("http://{$host}{$url}"));
    }

    public function testAntesDePublicarNaoHaPaginaNoCache(): void
    {
        self::assertSame(404, $this->pegar('/')->getStatusCode());
    }

    public function testInstalarDuasVezesFalha(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Instalador($this->caminhos))->instalar('Outro', 'outro.local');
    }

    public function testPublicarEServirDoCache(): void
    {
        $servicos = Servicos::doBancoInstalado($this->caminhos);
        $total = 0;
        foreach ($servicos->sites->todos() as $site) {
            $total += $servicos->publicador->publicarSite($site);
        }
        self::assertSame(2, $total);

        $inicio = $this->pegar('/');
        self::assertSame(200, $inicio->getStatusCode());
        self::assertStringContainsString('<h1>Site de teste</h1>', (string) $inicio->getContent());
        self::assertStringContainsString('href="/sobre"', (string) $inicio->getContent(), 'lista os filhos');

        $sobre = $this->pegar('/sobre');
        self::assertSame(200, $sobre->getStatusCode());
        self::assertStringContainsString('<h1>Sobre</h1>', (string) $sobre->getContent());
    }

    public function testTituloMaliciosoEEscapadoNoMolde(): void
    {
        $servicos = Servicos::doBancoInstalado($this->caminhos);
        $site = $servicos->sites->porHost('teste.local');
        self::assertNotNull($site);
        $classe = $servicos->classes->porNome('pagina');
        self::assertNotNull($classe);
        $servicos->objetos->criar($site->id, $classe, $site->objetoRaizId, '<script>alert(1)</script>', 'xss', ['resumo' => '"><img src=x>']);
        $servicos->publicador->publicarSite($site);

        $html = (string) $this->pegar('/xss')->getContent();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<img src=x>', $html);
    }

    public function testRascunhoNaoEPublicado(): void
    {
        $servicos = Servicos::doBancoInstalado($this->caminhos);
        $site = $servicos->sites->porHost('teste.local');
        $classe = $servicos->classes->porNome('pagina');
        self::assertNotNull($site);
        self::assertNotNull($classe);
        $servicos->objetos->criar($site->id, $classe, $site->objetoRaizId, 'Segredo', 'segredo', [], 'privado');
        $servicos->publicador->publicarSite($site);

        self::assertSame(404, $this->pegar('/segredo')->getStatusCode());
    }

    public function testDominioDesconhecidoDa404(): void
    {
        self::assertSame(404, $this->pegar('/', 'nao-configurado.local')->getStatusCode());
    }

    public function testTraversalNaUrlNaoSaiDoCache(): void
    {
        $servicos = Servicos::doBancoInstalado($this->caminhos);
        foreach ($servicos->sites->todos() as $site) {
            $servicos->publicador->publicarSite($site);
        }

        foreach (['/..%2f..%2fconfig/instalado.lock', '/%2e%2e/%2e%2e/dados/config/instalado', '/sobre/../../x', '/sobre.html', '/Sobre'] as $url) {
            self::assertSame(404, $this->pegar($url)->getStatusCode(), $url);
        }
    }

    public function testSemInstalacaoRetorna503(): void
    {
        $vazio = Ajuda::pastaTemporaria();
        try {
            $r = (new Aplicacao(new Caminhos($vazio)))->tratar(Request::create('http://x.local/'));
            self::assertSame(503, $r->getStatusCode());
        } finally {
            Ajuda::apagar($vazio);
        }
    }

    public function testDadosDoSiteFicamForaDeApp(): void
    {
        self::assertFileExists($this->caminhos->banco());
        self::assertFileExists($this->caminhos->peles() . '/basica/padrao.pbl');
        self::assertSame([], glob($this->raiz . '/app/*.sqlite') ?: [], 'nada é gravado em app/');
    }

    public function testPaginaVencidaDeixaDeSerServidaSemNovaPublicacaoELimpaNaProxima(): void
    {
        $servicos = Servicos::doBancoInstalado($this->caminhos);
        $site = $servicos->sites->porHost('teste.local');
        $classe = $servicos->classes->porNome('pagina');
        self::assertNotNull($site);
        self::assertNotNull($classe);
        $amanha = gmdate('c', time() + 86400);
        $servicos->objetos->criar($site->id, $classe, $site->objetoRaizId, 'Edital', 'edital', [], 'publicado', ['data_validade' => $amanha]);
        $servicos->publicador->publicarSite($site);
        self::assertSame(200, $this->pegar('/edital')->getStatusCode());

        // o tempo passa: a validade fica no passado, ninguém republicou
        $arquivo = $this->caminhos->pagina($site->id, 'edital');
        self::assertFileExists($arquivo . '.expira');
        file_put_contents($arquivo . '.expira', gmdate('c', time() - 60));
        self::assertSame(404, $this->pegar('/edital')->getStatusCode(), 'vencida: não é servida mesmo com o arquivo ainda no cache');

        // próxima publicação remove do cache o que deixou de estar visível
        $con = Conexao::sqlite($this->caminhos->banco());
        $con->executeStatement('UPDATE objeto SET data_validade = ? WHERE url_amigavel = ?', [gmdate('c', time() - 60), 'edital']);
        $servicos->publicador->publicarSite($site);
        self::assertFileDoesNotExist($arquivo);
        self::assertFileDoesNotExist($arquivo . '.expira');
    }

    public function testDespublicarRemoveDoCacheNaProximaPublicacao(): void
    {
        $servicos = Servicos::doBancoInstalado($this->caminhos);
        $servicos->publicador->publicarSite($servicos->sites->todos()[0]);
        self::assertSame(200, $this->pegar('/sobre')->getStatusCode());

        Conexao::sqlite($this->caminhos->banco())->executeStatement("UPDATE objeto SET status = 'privado' WHERE url_amigavel = 'sobre'");
        $servicos->publicador->publicarSite($servicos->sites->todos()[0]);

        self::assertSame(404, $this->pegar('/sobre')->getStatusCode());
    }

    public function testUrlAntigaRedirecionaEPaginaOrfaSaiDoCache(): void
    {
        $servicos = Servicos::doBancoInstalado($this->caminhos);
        $site = $servicos->sites->porHost('teste.local');
        $classe = $servicos->classes->porNome('pagina');
        self::assertNotNull($site);
        self::assertNotNull($classe);
        $guia = $servicos->objetos->criar($site->id, $classe, $site->objetoRaizId, 'Guia', 'guia', ['resumo' => 'r']);
        $servicos->publicador->publicarSite($site);
        self::assertSame(200, $this->pegar('/guia')->getStatusCode());

        $servicos->objetos->atualizar($guia->id, ['url_amigavel' => 'manual'], 'novo endereço');
        $servicos->publicador->publicarSite($site);

        self::assertFileDoesNotExist($this->caminhos->pagina($site->id, 'guia'), 'a página do endereço antigo saiu do cache');
        self::assertSame(200, $this->pegar('/manual')->getStatusCode());
        self::assertSame(200, $this->pegar('/')->getStatusCode(), 'a página inicial continua');
        $antigo = $this->pegar('/guia?aba=2');
        self::assertSame(301, $antigo->getStatusCode());
        self::assertSame('/manual?aba=2', $antigo->headers->get('Location'), 'redireciona para caminho relativo e mantém a consulta');

        // tira do ar: o endereço antigo não redireciona para uma página que não existe
        $servicos->objetos->atualizar($guia->id, ['status' => 'privado']);
        $servicos->publicador->publicarSite($site);
        self::assertSame(404, $this->pegar('/guia')->getStatusCode());
        self::assertSame(404, $this->pegar('/manual')->getStatusCode());
    }

    public function testRedirecionamentoNuncaVaiParaForaDoSite(): void
    {
        $servicos = Servicos::doBancoInstalado($this->caminhos);
        $site = $servicos->sites->porHost('teste.local');
        $classe = $servicos->classes->porNome('pagina');
        self::assertNotNull($site);
        self::assertNotNull($classe);
        $o = $servicos->objetos->criar($site->id, $classe, $site->objetoRaizId, 'X', 'x', []);
        $servicos->objetos->atualizar($o->id, ['url_amigavel' => 'y']);
        $servicos->publicador->publicarSite($site);

        $r = $this->pegar('/x?next=//evil.example');

        self::assertSame(301, $r->getStatusCode());
        self::assertStringStartsWith('/y', (string) $r->headers->get('Location'));
        self::assertStringNotContainsString('://', explode('?', (string) $r->headers->get('Location'))[0]);
        self::assertStringStartsNotWith('//', (string) $r->headers->get('Location'));
    }
}
