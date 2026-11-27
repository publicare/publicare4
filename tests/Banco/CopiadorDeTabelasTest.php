<?php

declare(strict_types=1);

namespace Pbl\Testes\Banco;

use Doctrine\DBAL\Connection;
use Pbl\Banco\CarregadorDeConfigBanco;
use Pbl\Banco\Conexao;
use Pbl\Banco\Copia\CopiadorDeTabelas;
use Pbl\Banco\Migracoes\M001Base;
use Pbl\Banco\Migracoes\M002Metadados;
use Pbl\Banco\Migracoes\M003TituloBusca;
use Pbl\Banco\Migracoes\M004BuscaNormalizada;
use Pbl\Banco\Migrador;
use Pbl\Caminhos;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use Pbl\Testes\Ajuda;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Copia de um banco para outro nos dois sentidos (ADR 0028, g): o banco configurado (`PBL_BANCO_*`, ou um SQLite temporário)
 * vai para um arquivo SQLite e volta. A conferência compara contagem e impressão digital de cada tabela.
 */
#[Group('contrato-dialeto')]
final class CopiadorDeTabelasTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;
    private Connection $principal;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        $this->caminhos->garantirDados();
        $config = (new CarregadorDeConfigBanco($this->caminhos))->carregar();
        $extensao = 'pdo_' . $config->driver;
        if (!extension_loaded($extensao)) {
            self::markTestSkipped("A extensão {$extensao} não está carregada.");
        }
        $this->principal = Conexao::abrir($config);
        Ajuda::apagarTabelas($this->principal);
    }

    protected function tearDown(): void
    {
        if (isset($this->principal)) {
            Ajuda::apagarTabelas($this->principal);
            $this->principal->close();
        }
        Ajuda::apagar($this->raiz);
    }

    private function instalar(): Servicos
    {
        (new Instalador($this->caminhos))->instalar('Cópia', 'copia.local', ['login' => 'admin', 'email' => 'a@copia.local', 'nome' => 'Admin', 'senha' => 'senha-de-teste-longa-1']);
        $s = Servicos::doBancoInstalado($this->caminhos);
        $site = $s->sites->porHost('copia.local');
        self::assertNotNull($site);
        $classe = $s->classes->porNome('pagina');
        self::assertNotNull($classe);
        $s->objetos->criar($site->id, $classe, $site->objetoRaizId, 'Ação & "coração" — ünïcødé 日本', 'acao', ['resumo' => "linha 1\nlinha 2", 'corpo' => '<p>texto</p>']);

        return $s;
    }

    private function sqliteMigrado(string $nome): Connection
    {
        $c = Conexao::sqlite($this->raiz . '/' . $nome);
        (new Migrador($c))->migrar();

        return $c;
    }

    public function testIdaEVoltaPreservaTudoEAsSequenciasContinuam(): void
    {
        $servicos = $this->instalar();
        $objetos = (int) $this->principal->fetchOne('SELECT COUNT(*) FROM objeto');
        self::assertGreaterThan(3, $objetos);

        // principal -> SQLite
        $arquivo = $this->sqliteMigrado('copia.sqlite');
        $ida = new CopiadorDeTabelas($this->principal, $arquivo, 3);
        $copiadas = $ida->copiar();
        self::assertSame($objetos, $copiadas['objeto']);
        self::assertSame([], $ida->divergencias());

        // SQLite -> principal vazio (de novo migrado)
        Ajuda::apagarTabelas($this->principal);
        (new Migrador($this->principal))->migrar();
        $volta = new CopiadorDeTabelas($arquivo, $this->principal, 5);
        $volta->copiar();
        self::assertSame([], $volta->divergencias());
        self::assertSame($objetos, (int) $this->principal->fetchOne('SELECT COUNT(*) FROM objeto'));

        // a próxima inserção não pode repetir um id já copiado
        $servicos = Servicos::doBancoInstalado(new Caminhos($this->raiz));
        $site = $servicos->sites->porHost('copia.local');
        $classe = $servicos->classes->porNome('pagina');
        self::assertNotNull($site);
        self::assertNotNull($classe);
        $novo = $servicos->objetos->criar($site->id, $classe, $site->objetoRaizId, 'Depois da cópia', 'depois', ['resumo' => 'x', 'corpo' => '<p>y</p>']);
        self::assertGreaterThan((int) $this->principal->fetchOne('SELECT MAX(id) FROM objeto WHERE id <> ' . $novo->id), $novo->id);
    }

    public function testRecusaDestinoComLinhasOuVersaoDiferente(): void
    {
        $this->instalar();
        $arquivo = $this->sqliteMigrado('cheio.sqlite');
        (new CopiadorDeTabelas($this->principal, $arquivo))->copiar();

        try {
            (new CopiadorDeTabelas($this->principal, $arquivo))->copiar();
            self::fail('copiar para um destino com linhas deveria falhar');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('já tem conteúdo', $e->getMessage());
        }

        $velho = Conexao::sqlite($this->raiz . '/velho.sqlite');
        (new Migrador($velho, [new M001Base(), new M002Metadados(), new M003TituloBusca(), new M004BuscaNormalizada()]))->migrar();
        try {
            (new CopiadorDeTabelas($this->principal, $velho))->copiar();
            self::fail('versões diferentes deveriam falhar');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('mesma versão', $e->getMessage());
        }
    }

    public function testTrocarDeBancoCopiaConfereEPodeVoltar(): void
    {
        $config = (new CarregadorDeConfigBanco($this->caminhos))->carregar();
        // O banco de hoje vem do arquivo, como numa instalação real; o ambiente (usado pelo teste) fica de fora.
        \Pbl\Banco\GravadorDeConfigBanco::gravar($this->caminhos, $config);
        $this->instalar();
        $objetos = (int) $this->principal->fetchOne('SELECT COUNT(*) FROM objeto');

        $troca = new \Pbl\Banco\Copia\TrocaDeBanco($this->caminhos, []);
        $destino = new \Pbl\Banco\ConfigBanco('sqlite', $this->raiz . '/destino.sqlite');
        $plano = $troca->simular($destino);
        self::assertSame($objetos, $plano['tabelas']['objeto']);
        self::assertFileDoesNotExist($this->raiz . '/dados/config/banco.anterior.json', 'simular não grava');

        $copiadas = $troca->trocar($destino);
        self::assertSame($objetos, $copiadas['objeto']);
        $depois = (new CarregadorDeConfigBanco($this->caminhos, []))->carregar();
        self::assertSame('sqlite', $depois->driver);
        self::assertSame($destino->nome, $depois->nome);
        self::assertSame($objetos, (int) Conexao::abrir($depois)->fetchOne('SELECT COUNT(*) FROM objeto'));
        self::assertFileDoesNotExist($this->raiz . '/dados/manutencao.json', 'a manutenção é desligada');

        $troca->voltar();
        $volta = (new CarregadorDeConfigBanco($this->caminhos, []))->carregar();
        self::assertSame($config->driver, $volta->driver);
        self::assertSame($config->nome, $volta->nome);
    }

    public function testNaoTrocaParaOMesmoBancoNemComVariavelDeAmbiente(): void
    {
        $config = (new CarregadorDeConfigBanco($this->caminhos))->carregar();
        \Pbl\Banco\GravadorDeConfigBanco::gravar($this->caminhos, $config);
        $troca = new \Pbl\Banco\Copia\TrocaDeBanco($this->caminhos, ['PBL_BANCO_DRIVER' => 'mysql']);
        self::assertNotSame([], $troca->impedimentos(new \Pbl\Banco\ConfigBanco('sqlite', $this->raiz . '/x.sqlite')));
        $mesmo = new \Pbl\Banco\Copia\TrocaDeBanco($this->caminhos, []);
        self::assertNotSame([], $mesmo->impedimentos($config->ehSqlite() ? new \Pbl\Banco\ConfigBanco('sqlite', $config->nome) : $config));
    }
}
