<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Atualizacao\Configuracao;
use Pbl\Caminhos;
use Pbl\Instalacao\Instalador;
use PHPUnit\Framework\TestCase;

/** `bin/pbl instalar` e o assistente deixam dados/config/atualizacao.json pronto (estável), sem pisar no que já existe. */
final class AtualizacaoPadraoNaInstalacaoTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    public function testInstalarCriaOArquivoEstavelELeituraDevolveOQueFoiGravado(): void
    {
        (new Instalador($this->caminhos))->instalar('Meu Site', 'www.exemplo.org');

        $c = Configuracao::ler($this->caminhos);
        self::assertSame(Configuracao::URL_ESTAVEL, $c->url);
        self::assertSame('estavel', $c->canal);
        self::assertTrue($c->verificar);
    }

    public function testInstalarPreservaOArquivoJaGravadoPeloInstalarPhp(): void
    {
        $this->caminhos->garantirDados();
        $json = '{"url":"https://atualizacoes.publicare.org/latest-beta.json","canal":"beta","verificar":true}';
        file_put_contents($this->caminhos->config() . '/atualizacao.json', $json);

        (new Instalador($this->caminhos))->instalar('Meu Site', 'www.exemplo.org');

        self::assertSame($json, file_get_contents($this->caminhos->config() . '/atualizacao.json'));
        self::assertSame('beta', Configuracao::ler($this->caminhos)->canal);
    }
}
