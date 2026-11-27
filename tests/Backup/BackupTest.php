<?php

declare(strict_types=1);

namespace Pbl\Testes\Backup;

use Pbl\Backup\AgendaDeBackup;
use Pbl\Backup\CatalogoDeBackups;
use Pbl\Backup\ConfigDeBackup;
use Pbl\Backup\CriadorDeBackup;
use Pbl\Backup\ErroDeBackup;
use Pbl\Backup\InfoDeBackup;
use Pbl\Backup\RestauradorDeBackup;
use Pbl\Backup\RetencaoDeBackups;
use Pbl\Backup\ValidadorDePasta;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use Pbl\Caminhos;
use Pbl\Testes\Ajuda;
use PHPUnit\Framework\TestCase;

final class BackupTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;
    private ConfigDeBackup $config;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::pastaTemporaria();
        $this->caminhos = new Caminhos($this->raiz);
        $this->caminhos->garantirDados();
        $c = \Pbl\Testes\Ajuda::conexao($this->caminhos);
        (new Migrador($c))->migrar();
        $c->close();
        file_put_contents($this->caminhos->uploads() . '/a.txt', 'conteudo A');
        $this->config = new ConfigDeBackup();
    }

    protected function tearDown(): void
    {
        if (isset($this->raiz)) {
            Ajuda::apagar($this->raiz);
        }
    }

    private function criador(?int $hora = null): CriadorDeBackup
    {
        return new CriadorDeBackup($this->caminhos, $this->config, '0.1.0-teste', $hora === null ? null : static fn (): int => $hora);
    }

    private function restaurador(?int $esquema = null): RestauradorDeBackup
    {
        return new RestauradorDeBackup($this->caminhos, new CatalogoDeBackups($this->caminhos, $this->config), $this->criador(), $esquema);
    }

    public function testPadroesSaoSegurosEConfigRoundTrip0600(): void
    {
        $c = ConfigDeBackup::carregar($this->caminhos);
        self::assertFalse($c->ativo);
        self::assertSame('03:00', $c->hora);

        (new ConfigDeBackup(true, 'semanal', '04:30', [1, 5], 3, 10))->gravar($this->caminhos);

        $l = ConfigDeBackup::carregar($this->caminhos);
        self::assertTrue($l->ativo);
        self::assertSame([1, 5], $l->dias);
        self::assertSame('0600', substr(sprintf('%o', fileperms(ConfigDeBackup::arquivo($this->caminhos))), -4));
        file_put_contents(ConfigDeBackup::arquivo($this->caminhos), '{"ativo":true,"hora":"99:99"}');
        self::assertFalse(ConfigDeBackup::carregar($this->caminhos)->ativo, 'valor inválido volta ao padrão desativado');
    }

    public function testConfigRejeitaValoresInvalidos(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConfigDeBackup(true, 'mensal');
    }

    public function testValidadorRecusaTravessiaRaizECodigoEProtegeNaWeb(): void
    {
        $recusadas = 0;
        foreach (['../fora', '.', 'app', 'vendor/x', 'public/b', 'dados/backup/x'] as $ruim) {
            try {
                ValidadorDePasta::resolver($this->caminhos, $ruim);
            } catch (ErroDeBackup) {
                ++$recusadas;
            }
        }
        self::assertSame(6, $recusadas);
        $p = ValidadorDePasta::resolver($this->caminhos, 'dados/backups');
        self::assertFileExists($p . '/.htaccess');
        self::assertStringContainsString('Require all denied', (string) file_get_contents($p . '/.htaccess'));
        $fora = Ajuda::pastaTemporaria();
        try {
            self::assertSame(realpath($fora), ValidadorDePasta::resolver($this->caminhos, $fora));
        } finally {
            Ajuda::apagar($fora);
        }
    }

    public function testCriaPacoteComManifestoEHashesSemIncluirCopiasNemBancoVivo(): void
    {
        $i = $this->criador(1_700_000_000)->criar('manual');

        $arquivo = $this->raiz . '/dados/backups/' . $i->arquivo;
        self::assertMatchesRegularExpression(CatalogoDeBackups::NOME_VALIDO, $i->arquivo);
        self::assertSame('0600', substr(sprintf('%o', fileperms($arquivo)), -4));
        self::assertTrue(CatalogoDeBackups::integro($arquivo));
        $m = CatalogoDeBackups::lerManifesto($arquivo) ?? self::fail();
        self::assertSame('manual', $m['tipo']);
        self::assertSame('0.1.0-teste', $m['versao_pbl4']);
        $caminhos = array_column($m['entradas'], 'caminho');
        self::assertContains('banco/pbl4.sqlite', $caminhos);
        self::assertContains('dados/uploads/a.txt', $caminhos);
        foreach ($caminhos as $c) {
            self::assertStringNotContainsString('backups/', $c);
            self::assertStringNotContainsString('.sqlite', str_replace('banco/pbl4.sqlite', '', $c));
        }
        self::assertDirectoryDoesNotExist($this->raiz . '/dados/backup/trabalho/' . $m['id']);
    }

    public function testCatalogoListaPacotesEProtegeACopiaDoVoltarAtras(): void
    {
        $this->criador(1_700_000_000)->criar('manual');
        file_put_contents($this->raiz . '/dados/backups/pbl4-0.1.0-rc.3-20260101.sqlite', 'x');
        mkdir($this->raiz . '/dados/atualizacao');
        file_put_contents($this->raiz . '/dados/atualizacao/aplicada.json', json_encode([
            'de' => '0.1.0-rc.3', 'para' => '0.1.0-rc.4', 'em' => 1, 'backup' => 'pbl4-0.1.0-rc.3-20260101.sqlite', 'backup_sha256' => 'a',
            'itens' => [], 'sem_anterior' => [],
        ]));

        $lista = (new CatalogoDeBackups($this->caminhos, $this->config))->listar();

        self::assertCount(2, $lista);
        $legado = array_values(array_filter($lista, static fn (InfoDeBackup $i): bool => $i->legado))[0];
        self::assertSame('pre-atualizacao', $legado->tipo);
        self::assertTrue($legado->protegida);
        self::assertSame('0.1.0-rc.3', $legado->versao);
    }

    public function testCaminhoDeRecusaNomeComTravessia(): void
    {
        $this->expectException(ErroDeBackup::class);
        (new CatalogoDeBackups($this->caminhos, $this->config))->caminhoDe('../../etc/passwd.zip');
    }

    private function info(string $nome, string $tipo, int $dias, bool $protegida = false, ?bool $integro = true): InfoDeBackup
    {
        return new InfoDeBackup($nome, $tipo, 1_800_000_000 - $dias * 86400, 1, '1', 1, $integro, false, $protegida);
    }

    public function testRetencaoApagaSoQuemViolaNumeroEDiasEPoupaAMaisRecente(): void
    {
        $copias = [$this->info('a', 'agendada', 0), $this->info('b', 'agendada', 1), $this->info('c', 'agendada', 2), $this->info('d', 'manual', 50), $this->info('e', 'manual', 60)];

        $nomes = array_map(static fn (InfoDeBackup $i): string => $i->arquivo, (new RetencaoDeBackups(2, 30))->planejar($copias, 1_800_000_000));

        self::assertEqualsCanonicalizing(['d', 'e'], $nomes, 'c está além de 2 mas é recente: fica');
        $soNumero = array_map(static fn (InfoDeBackup $i): string => $i->arquivo, (new RetencaoDeBackups(2, 0))->planejar($copias, 1_800_000_000));
        self::assertEqualsCanonicalizing(['c', 'd', 'e'], $soNumero);
        self::assertSame([], (new RetencaoDeBackups(0, 0))->planejar($copias, 1_800_000_000));
    }

    public function testRetencaoNuncaApagaPreAtualizacaoNemUnicaIntegra(): void
    {
        $copias = [$this->info('legado', 'pre-atualizacao', 400, true), $this->info('novo', 'manual', 0), $this->info('velho', 'manual', 400, false, false)];

        $nomes = array_map(static fn (InfoDeBackup $i): string => $i->arquivo, (new RetencaoDeBackups(1, 1))->planejar($copias, 1_800_000_000));

        self::assertSame(['velho'], $nomes);
    }

    public function testAplicarRetencaoApagaArquivoESha(): void
    {
        $a = $this->criador(1_700_000_000)->criar('manual');
        $b = $this->criador(1_700_100_000)->criar('manual');
        $cat = new CatalogoDeBackups($this->caminhos, $this->config);

        $n = RetencaoDeBackups::aplicar($cat, (new RetencaoDeBackups(1, 0))->planejar($cat->listar(), 1_700_200_000));

        self::assertSame(1, $n);
        self::assertFileDoesNotExist($this->raiz . '/dados/backups/' . $a->arquivo . '.sha256');
        self::assertFileExists($this->raiz . '/dados/backups/' . $b->arquivo);
    }

    public function testRestauraBancoEArquivosECriaCopiaPreRestauracao(): void
    {
        \Pbl\Testes\Ajuda::pularSeBancoDeServidor('Cria uma tabela própria do teste (marca); a cópia de banco de servidor leva só as tabelas do Publicare e é coberta por InstantaneoDeBancoTest.');
        $c = \Pbl\Testes\Ajuda::conexao($this->caminhos);
        $c->executeStatement('CREATE TABLE marca (v TEXT)');
        $c->executeStatement("INSERT INTO marca VALUES ('antes')");
        $c->close();
        $copia = $this->criador(1_700_000_000)->criar('manual');
        $c = \Pbl\Testes\Ajuda::conexao($this->caminhos);
        $c->executeStatement("UPDATE marca SET v = 'depois'");
        $c->close();
        file_put_contents($this->caminhos->uploads() . '/a.txt', 'MUDOU');
        file_put_contents($this->caminhos->uploads() . '/nova.txt', 'nova');

        $r = $this->restaurador()->restaurar($copia->arquivo);

        self::assertSame('antes', \Pbl\Testes\Ajuda::conexao($this->caminhos)->fetchOne('SELECT v FROM marca'));
        self::assertSame('conteudo A', file_get_contents($this->caminhos->uploads() . '/a.txt'));
        self::assertFileDoesNotExist($this->caminhos->uploads() . '/nova.txt');
        self::assertStringContainsString('pre-restauracao', $r['copia_anterior']);
        self::assertFileExists($this->raiz . '/dados/backups/' . $r['copia_anterior']);
        self::assertSame([], glob($this->raiz . '/dados/*.antiga-*') ?: []);
        self::assertFalse(\Pbl\Atualizacao\Manutencao::ativa($this->caminhos));
    }

    public function testRestauracaoRecusaPacoteAdulteradoSemTocarNoEstado(): void
    {
        $copia = $this->criador(1_700_000_000)->criar('manual');
        $arq = $this->raiz . '/dados/backups/' . $copia->arquivo;
        file_put_contents($arq, 'x', FILE_APPEND);
        file_put_contents($this->caminhos->uploads() . '/a.txt', 'ATUAL');

        try {
            $this->restaurador()->restaurar($copia->arquivo);
            self::fail('deveria recusar');
        } catch (ErroDeBackup $e) {
            self::assertStringContainsString('integridade', $e->getMessage());
        }
        self::assertSame('ATUAL', file_get_contents($this->caminhos->uploads() . '/a.txt'));
    }

    public function testRestauracaoRecusaEsquemaMaisNovoQueOCodigo(): void
    {
        $copia = $this->criador(1_700_000_000)->criar('manual');

        $this->expectException(ErroDeBackup::class);
        $this->expectExceptionMessage('mais nova');
        $this->restaurador(1)->conferir($copia->arquivo);
    }

    public function testRestauracaoRecusaEntradaForaDoManifestoEHashDeEntradaErrado(): void
    {
        $copia = $this->criador(1_700_000_000)->criar('manual');
        $arq = $this->raiz . '/dados/backups/' . $copia->arquivo;
        $zip = new \ZipArchive();
        $zip->open($arq);
        $zip->addFromString('dados/extra.php', '<?php');
        $zip->close();
        file_put_contents($arq . '.sha256', hash_file('sha256', $arq) . "\n"); // pacote "íntegro" mas com intruso

        $this->expectException(ErroDeBackup::class);
        $this->expectExceptionMessage('fora do manifesto');
        $this->restaurador()->restaurar($copia->arquivo);
    }

    public function testCaminhoSeguro(): void
    {
        foreach (['banco/pbl4.sqlite', 'dados/uploads/x.jpg'] as $ok) {
            self::assertTrue(RestauradorDeBackup::caminhoSeguro($ok));
        }
        foreach (['../x', '/etc/passwd', 'dados/../../x', 'dados//x', 'app/x.php', 'dados\\x'] as $ruim) {
            self::assertFalse(RestauradorDeBackup::caminhoSeguro($ruim), $ruim);
        }
    }

    public function testAgendaDiariaESemanalNoFusoDoSite(): void
    {
        $diaria = new AgendaDeBackup(new ConfigDeBackup(true, 'diaria', '03:00', [1], fuso: 'America/Sao_Paulo'));
        $agora = (new \DateTimeImmutable('2026-03-10 03:30', new \DateTimeZone('America/Sao_Paulo')))->getTimestamp();
        $h = (new \DateTimeImmutable('2026-03-10 03:00', new \DateTimeZone('America/Sao_Paulo')))->getTimestamp();

        self::assertSame($h, $diaria->ultimoHorario($agora));
        self::assertTrue($diaria->vencido($agora, 0));
        self::assertFalse($diaria->vencido($agora, $h), 'já atendido');
        self::assertFalse($diaria->vencido($h + 7 * 3600, 0), 'fora da janela de 6 h');
        self::assertSame($h + 86400, $diaria->proximoHorario($agora));

        $semanal = new AgendaDeBackup(new ConfigDeBackup(true, 'semanal', '03:00', [5])); // sexta
        $quarta = $agora; // 10/03/2026 é terça
        self::assertSame('2026-03-06', gmdate('Y-m-d', ($semanal->ultimoHorario($quarta) ?? 0)));
        self::assertSame('2026-03-13', (new \DateTimeImmutable('@' . $semanal->proximoHorario($quarta)))->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('Y-m-d'));
    }
}
