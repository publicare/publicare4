<?php

declare(strict_types=1);

namespace Pbl\Testes\Banco;

use Doctrine\DBAL\Connection;
use Pbl\Atualizacao\CopiaDeBanco;
use Pbl\Backup\CatalogoDeBackups;
use Pbl\Backup\ConfigDeBackup;
use Pbl\Backup\CriadorDeBackup;
use Pbl\Backup\RestauradorDeBackup;
use Pbl\Banco\CarregadorDeConfigBanco;
use Pbl\Banco\Conexao;
use Pbl\Banco\Copia\InstantaneoDeBanco;
use Pbl\Caminhos;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use Pbl\Testes\Ajuda;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Cópia de segurança e restauração do banco da instalação em qualquer dialeto (ADR 0028, f): o instantâneo é sempre um arquivo SQLite,
 * então o pacote `.zip` é o mesmo para SQLite, MySQL, MariaDB e PostgreSQL.
 */
#[Group('contrato-dialeto')]
final class InstantaneoDeBancoTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;
    private Connection $banco;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        $this->caminhos->garantirDados();
        $config = (new CarregadorDeConfigBanco($this->caminhos))->carregar();
        if (!extension_loaded('pdo_' . $config->driver)) {
            self::markTestSkipped("A extensão pdo_{$config->driver} não está carregada.");
        }
        $this->banco = Conexao::abrir($config);
        Ajuda::apagarTabelas($this->banco);
        (new Instalador($this->caminhos))->instalar('Cópia', 'copia.local');
    }

    protected function tearDown(): void
    {
        if (isset($this->banco)) {
            try {
                Ajuda::apagarTabelas($this->banco);
            } catch (\Throwable) {
                // SQLite: a restauração trocou o arquivo debaixo desta conexão; a pasta de teste some de qualquer jeito
            }
            $this->banco->close();
        }
        Ajuda::apagar($this->raiz);
    }

    private function objetos(): int
    {
        return (int) Conexao::abrir((new CarregadorDeConfigBanco($this->caminhos))->carregar())->fetchOne('SELECT COUNT(*) FROM objeto');
    }

    private function criarObjeto(string $titulo): void
    {
        $s = Servicos::doBancoInstalado($this->caminhos);
        $site = $s->sites->porHost('copia.local') ?? self::fail();
        $s->objetos->criar($site->id, $s->classes->porNome('pagina') ?? self::fail(), $site->objetoRaizId, $titulo, 'u' . bin2hex(random_bytes(3)), ['resumo' => 'r', 'corpo' => '<p>c</p>']);
    }

    public function testTirarEDevolverUmInstantaneo(): void
    {
        $inst = new InstantaneoDeBanco($this->caminhos);
        self::assertTrue($inst->existe());
        $antes = $this->objetos();
        $versao = $inst->versaoDoEsquema();
        self::assertNotNull($versao);
        self::assertGreaterThan(0, $inst->tamanhoAproximado());

        $arquivo = $this->raiz . '/dados/instantaneo.sqlite';
        $inst->tirar($arquivo);
        self::assertTrue(CopiaDeBanco::integro($arquivo));
        self::assertSame($versao, CopiaDeBanco::versaoDoEsquema($arquivo));

        $this->criarObjeto('Depois do instantâneo');
        self::assertSame($antes + 1, $this->objetos());

        $inst->restaurar($arquivo);
        self::assertSame($antes, $this->objetos());
        self::assertSame($versao, $inst->versaoDoEsquema());
        // o banco restaurado continua gravável (as sequências foram reposicionadas)
        $this->criarObjeto('De novo depois de restaurar');
        self::assertSame($antes + 1, $this->objetos());
    }

    public function testBackupCompletoERestauracaoPeloPacote(): void
    {
        $config = new ConfigDeBackup();
        $catalogo = new CatalogoDeBackups($this->caminhos, $config);
        $criador = new CriadorDeBackup($this->caminhos, $config, '0.1.0-teste');
        $antes = $this->objetos();
        file_put_contents($this->caminhos->uploads() . '/a.txt', 'versão do backup');
        $info = $criador->criar('manual');

        $this->criarObjeto('Criado depois do backup');
        file_put_contents($this->caminhos->uploads() . '/a.txt', 'mudou');
        self::assertSame($antes + 1, $this->objetos());

        $r = (new RestauradorDeBackup($this->caminhos, $catalogo, $criador))->restaurar($info->arquivo);
        self::assertSame('0.1.0-teste', $r['versao']);
        self::assertSame($antes, $this->objetos());
        self::assertSame('versão do backup', file_get_contents($this->caminhos->uploads() . '/a.txt'));
    }
}
