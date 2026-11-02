<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Usuario;
use Pbl\Admin\TelasDeAtualizacao;
use Pbl\Atualizacao\Assinatura;
use Pbl\Atualizacao\EstadoDoPainel;
use Pbl\Atualizacao\Manifesto;
use Pbl\Atualizacao\Verificacao;
use Pbl\Caminhos;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;

/**
 * `TelasDeAtualizacao::avisoHomePage`: o aviso de versão nova na página inicial do painel, só administrador geral, nunca
 * consultando a rede (só lê o cache de 24 h que a tela "Atualização" ou o cron já gravaram).
 */
final class AvisoDeVersaoNaHomeTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;
    private Usuario $admin;
    private Usuario $editor;
    /** @var array{publica: string, privada: string} */
    private array $par;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Meu Site', 'www.exemplo.org');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $this->admin = new Usuario(1, 'admin', 'admin@exemplo.org', 'Admin', true, true);
        $this->editor = new Usuario(2, 'editor', 'editor@exemplo.org', 'Editor', false, true);
        $this->par = Assinatura::gerarPar();
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function configurar(string $url = 'https://exemplo.org/latest.json'): void
    {
        $this->caminhos->garantirDados();
        file_put_contents($this->caminhos->config() . '/atualizacao.json', json_encode(['url' => $url, 'canal' => 'estavel']));
    }

    private function manifesto(string $versao, bool $seguranca = false): Manifesto
    {
        return Manifesto::analisar(json_encode([
            'formato' => 1, 'canal' => 'estavel', 'versao' => $versao, 'data' => '2026-11-01', 'php_minimo' => '8.2',
            'atualizar_a_partir_de' => '0.0.0', 'seguranca' => $seguranca, 'resumo' => 'x',
            'pacote' => ['url' => 'p.zip', 'tamanho' => 10, 'sha256' => str_repeat('a', 64)],
        ], JSON_THROW_ON_ERROR));
    }

    private function guardarCache(string $url, string $instalada, ?Verificacao $v, string $erro = ''): void
    {
        (new EstadoDoPainel($this->caminhos))->guardarVerificacao($url, 'estavel', $instalada, $v, $erro);
    }

    public function testQuemNaoEAdministradorGeralNuncaVeOAviso(): void
    {
        $this->configurar();
        $this->guardarCache('https://exemplo.org/latest.json', '0.1.0', new Verificacao(true, $this->manifesto('0.2.0'), 'Disponível: 0.2.0.'));
        self::assertSame('', TelasDeAtualizacao::avisoHomePage($this->s, $this->editor, '0.1.0', [$this->par['publica']]));
    }

    public function testSemUrlVerificacaoDesligadaOuSemChaveNaoMostraNada(): void
    {
        // sem configurar nada
        self::assertSame('', TelasDeAtualizacao::avisoHomePage($this->s, $this->admin, '0.1.0', [$this->par['publica']]));

        $this->configurar();
        self::assertSame('', TelasDeAtualizacao::avisoHomePage($this->s, $this->admin, '0.1.0', []), 'sem chave (produção, hoje)');

        $this->caminhos->garantirDados();
        file_put_contents($this->caminhos->config() . '/atualizacao.json', json_encode(['url' => 'https://exemplo.org/latest.json', 'verificar' => false]));
        self::assertSame('', TelasDeAtualizacao::avisoHomePage($this->s, $this->admin, '0.1.0', [$this->par['publica']]));
    }

    public function testCacheVencidoOuInexistenteMostraLinkParaVerificar(): void
    {
        $this->configurar();
        $h = TelasDeAtualizacao::avisoHomePage($this->s, $this->admin, '0.1.0', [$this->par['publica']]);
        self::assertStringContainsString('Verificar', $h);
        self::assertStringContainsString('/_pbl/atualizacao', $h);
    }

    public function testVersaoNovaDisponivelMostraOAvisoComLinkEComSeguranca(): void
    {
        $this->configurar();
        $this->guardarCache('https://exemplo.org/latest.json', '0.1.0', new Verificacao(true, $this->manifesto('0.2.0', seguranca: true), 'Disponível: 0.2.0.'));
        $h = TelasDeAtualizacao::avisoHomePage($this->s, $this->admin, '0.1.0', [$this->par['publica']]);
        self::assertStringContainsString('Nova versão disponível', $h);
        self::assertStringContainsString('0.2.0', $h);
        self::assertStringContainsString('Correção de segurança', $h);
        self::assertStringContainsString('/_pbl/atualizacao', $h);
    }

    public function testJaNaUltimaVersaoNaoMostraNada(): void
    {
        $this->configurar();
        $this->guardarCache('https://exemplo.org/latest.json', '0.2.0', new Verificacao(false, null, 'Já está na versão mais recente (0.2.0).'));
        self::assertSame('', TelasDeAtualizacao::avisoHomePage($this->s, $this->admin, '0.2.0', [$this->par['publica']]));
    }

    public function testFalhaDeConsultaAnteriorMostraLinkParaVerificarDeNovo(): void
    {
        $this->configurar();
        $this->guardarCache('https://exemplo.org/latest.json', '0.1.0', null, 'Não foi possível consultar.');
        $h = TelasDeAtualizacao::avisoHomePage($this->s, $this->admin, '0.1.0', [$this->par['publica']]);
        self::assertStringContainsString('Verificar', $h);
    }

    public function testNuncaConsultaARedeAoMontarOAviso(): void
    {
        // um endereço que, se fosse tocado, falharia imediatamente (sem Baixador real nenhuma rede é usada aqui mesmo)
        $this->configurar('https://endereco.invalido.exemplo/latest.json');
        self::assertStringContainsString('Verificar', TelasDeAtualizacao::avisoHomePage($this->s, $this->admin, '0.1.0', [$this->par['publica']]));
    }
}
