<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Caminhos;
use Pbl\Console\ImportarPbl3Comando;
use Pbl\Importacao\Importador;
use Pbl\Importacao\MapaDeTabelas;
use Pbl\Importacao\Opcoes;
use Pbl\Importacao\OrigemPbl3;
use Pbl\Importacao\Relatorio;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportadorPbl3Test extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;
    private string $uploads;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Meu Site', 'www.exemplo.org');
        $this->uploads = $this->raiz . '/pbl3-uploads';
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function importar(Pbl3Sintetico $base, bool $simular = false, ?MapaDeTabelas $mapa = null, ?Opcoes $opcoes = null): Relatorio
    {
        $relatorio = new Relatorio();
        $servicos = Servicos::doBancoInstalado($this->caminhos);
        $opcoes ??= new Opcoes(simular: $simular);
        (new Importador($servicos, new OrigemPbl3($base->c, $mapa ?? MapaDeTabelas::padrao(), $relatorio), $opcoes, $relatorio))->executar();

        return $relatorio;
    }

    /** @param list<mixed> $p */
    private function contar(string $sql, array $p = []): int
    {
        return (int) Servicos::doBancoInstalado($this->caminhos)->conexao()->fetchOne($sql, $p);
    }

    private function sintetico(): Pbl3Sintetico
    {
        $base = new Pbl3Sintetico();
        $base->popular();

        return $base;
    }

    private function arquivos(): void
    {
        mkdir($this->uploads . '/0001', 0775, true);
        mkdir($this->uploads . '/0000', 0775, true);
        file_put_contents($this->uploads . '/0000/7.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        if (extension_loaded('gd')) {
            $im = imagecreatetruecolor(4, 4);
            ob_start();
            imagepng($im);
            file_put_contents($this->uploads . '/0001/1500.png', (string) ob_get_clean());
        }
    }

    public function testImportaArvoreClassesTiposEValores(): void
    {
        $r = $this->importar($this->sintetico());

        self::assertSame(5, $r->total('objetos importados'), 'raiz, página, subpágina, produto e lixeira: o órfão fica de fora');
        $c = Servicos::doBancoInstalado($this->caminhos)->conexao();
        $pagina = $c->fetchAssociative("SELECT * FROM objeto WHERE titulo = 'Página um'");
        self::assertIsArray($pagina);
        self::assertSame('publicado', $pagina['status']);
        $sub = $c->fetchAssociative("SELECT * FROM objeto WHERE titulo = 'Subpágina'");
        self::assertIsArray($sub);
        self::assertSame($pagina['id'], $sub['pai_id']);
        self::assertTrue(str_starts_with((string) $sub['caminho'], (string) $pagina['caminho']));
        self::assertNotSame($pagina['url_amigavel'], $sub['url_amigavel'], 'o endereço é único no site: o repetido é renomeado');

        $dados = json_decode((string) $pagina['dados'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Um resumo', $dados['resumo']);
        self::assertStringNotContainsString('<script', (string) $dados['corpo']);
        self::assertStringContainsString('Olá', (string) $dados['corpo']);

        $produto = $c->fetchAssociative("SELECT * FROM objeto WHERE titulo = 'Caneca'");
        self::assertIsArray($produto);
        $d = json_decode((string) $produto['dados'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Caneca azul', $d['nome']);
        self::assertEquals(19.9, $d['preco']);
        self::assertEquals(7, $d['estoque']);
        self::assertEquals(1, $d['ativo']);
        self::assertStringStartsWith('2024-02-29', (string) $d['lancamento']);
        self::assertSame(1, $this->contar("SELECT COUNT(*) FROM classe WHERE nome = 'produto'"));
        self::assertSame(0, $this->contar("SELECT COUNT(*) FROM objeto WHERE titulo = 'Órfão'"));
    }

    public function testMenuPesoPeleDatasELixeira(): void
    {
        $this->importar($this->sintetico(), opcoes: new Opcoes(simular: false, peles: ['inventada' => 'padrao']));

        $c = Servicos::doBancoInstalado($this->caminhos)->conexao();
        $p = $c->fetchAssociative("SELECT * FROM objeto WHERE titulo = 'Página um'");
        self::assertIsArray($p);
        self::assertSame(5, (int) $p['peso']);
        self::assertSame(1, (int) $p['no_menu']);
        self::assertSame('apagado', $c->fetchOne("SELECT status FROM objeto WHERE titulo = 'Rascunho antigo'"));
        self::assertStringStartsWith('2024-01-31T15:00:00', (string) $p['data_publicacao'], '12:00 em São Paulo é 15:00 UTC');
    }

    public function testSemLixeiraNaoImportaApagados(): void
    {
        $r = $this->importar($this->sintetico(), opcoes: new Opcoes(simular: false, semLixeira: true));

        self::assertSame(0, $this->contar("SELECT COUNT(*) FROM objeto WHERE titulo = 'Rascunho antigo'"));
        self::assertSame(1, $r->total('objetos da lixeira ignorados (--sem-lixeira)'));
    }

    public function testUsuariosPerfisEAvisos(): void
    {
        $r = $this->importar($this->sintetico());

        self::assertSame(3, $r->total('usuários importados'));
        $c = Servicos::doBancoInstalado($this->caminhos)->conexao();
        $beto = $c->fetchAssociative("SELECT * FROM usuario WHERE nome = 'Beto Inventado'");
        self::assertIsArray($beto);
        self::assertSame('betosilva', $beto['login']);
        self::assertSame('betosilva@importado.invalid', $beto['email']);
        self::assertSame(0, (int) $c->fetchOne("SELECT ativo FROM usuario WHERE login = 'cris'"));
        $servicos = Servicos::doBancoInstalado($this->caminhos);
        self::assertNull($servicos->usuarios->autenticar('ana', 'x'));
        self::assertNull($servicos->usuarios->autenticar('ana', md5('x')), 'o hash do PBL3 nunca vira senha');
        self::assertSame(1, (int) $c->fetchOne("SELECT administrador FROM usuario WHERE login = 'ana'"), 'Administrador na raiz importada vira administrador global');
        self::assertSame(1, $this->contar('SELECT COUNT(*) FROM acesso WHERE usuario_id = ?', [$beto['id']]), 'Autor e Restrito no mesmo objeto: vale o mais forte (autor)');
        self::assertArrayHasKey('perfil do PBL3 sem equivalente (acesso descartado)', $r->avisos());
    }

    public function testVersoesReconhecidasEDescartadas(): void
    {
        $r = $this->importar($this->sintetico());

        self::assertSame(1, $r->total('versões importadas'));
        self::assertSame(1, $r->total('versões em formato não reconhecido (descartadas)'));
        $id = $this->contar("SELECT id FROM objeto WHERE titulo = 'Página um'");
        self::assertSame(2, $this->contar('SELECT COUNT(*) FROM objeto_versao WHERE objeto_id = ?', [$id]));
        self::assertSame('Página zero', Servicos::doBancoInstalado($this->caminhos)->conexao()->fetchOne('SELECT titulo FROM objeto_versao WHERE objeto_id = ? AND versao = 1', [$id]));
        self::assertSame(2, $this->contar('SELECT versao FROM objeto WHERE id = ?', [$id]));
    }

    public function testTagsEReferenciaRemapeada(): void
    {
        $this->importar($this->sintetico());

        $c = Servicos::doBancoInstalado($this->caminhos)->conexao();
        $produto = $c->fetchAssociative("SELECT id, dados FROM objeto WHERE titulo = 'Caneca'");
        self::assertIsArray($produto);
        $pagina = (int) $c->fetchOne("SELECT id FROM objeto WHERE titulo = 'Página um'");
        $dados = json_decode((string) $produto['dados'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($pagina, (int) $dados['similar'], 'a referência aponta para o novo id, não para o 2 do PBL3');
        $tags = Servicos::doBancoInstalado($this->caminhos)->tags->nomesDoObjeto((int) $produto['id']);
        self::assertEqualsCanonicalizing(['verão', 'oferta'], array_map(mb_strtolower(...), $tags));
    }

    public function testArquivosDePropriedadeBlob(): void
    {
        $this->arquivos();
        $r = $this->importar($this->sintetico(), opcoes: new Opcoes(simular: false, uploads: $this->uploads));

        $esperados = extension_loaded('gd') ? 2 : 1;
        self::assertSame($esperados, $r->total('arquivos importados'));
        self::assertSame($esperados, $this->contar('SELECT COUNT(*) FROM arquivo'));
    }

    public function testRepetirNaoDuplicaERetoma(): void
    {
        $this->importar($this->sintetico());
        $objetos = $this->contar('SELECT COUNT(*) FROM objeto');
        $usuarios = $this->contar('SELECT COUNT(*) FROM usuario');
        $classes = $this->contar('SELECT COUNT(*) FROM classe');

        $r = $this->importar($this->sintetico());

        self::assertSame($objetos, $this->contar('SELECT COUNT(*) FROM objeto'));
        self::assertSame($usuarios, $this->contar('SELECT COUNT(*) FROM usuario'));
        self::assertSame($classes, $this->contar('SELECT COUNT(*) FROM classe'));
        self::assertSame(0, $r->total('objetos importados'));
        self::assertGreaterThan(0, $r->total('objetos já importados'));
    }

    public function testRetomaDepoisDeNovosObjetosNaOrigem(): void
    {
        $base = $this->sintetico();
        $this->importar($base);
        $base->objeto(8, 2, 2, 'Chegou depois', 'depois');

        $r = $this->importar($base);

        self::assertSame(1, $r->total('objetos importados'));
        self::assertSame(1, $this->contar("SELECT COUNT(*) FROM objeto WHERE titulo = 'Chegou depois'"));
        self::assertSame($this->contar("SELECT id FROM objeto WHERE titulo = 'Página um'"), $this->contar("SELECT pai_id FROM objeto WHERE titulo = 'Chegou depois'"));
    }

    public function testSimularNaoGravaNada(): void
    {
        $antes = [$this->contar('SELECT COUNT(*) FROM objeto'), $this->contar('SELECT COUNT(*) FROM usuario'), $this->contar('SELECT COUNT(*) FROM classe'), $this->contar('SELECT COUNT(*) FROM importacao_pbl3')];
        $this->arquivos();

        $r = $this->importar($this->sintetico(), simular: true, opcoes: new Opcoes(simular: true, uploads: $this->uploads));

        self::assertSame(5, $r->total('objetos importados'));
        self::assertSame($antes, [$this->contar('SELECT COUNT(*) FROM objeto'), $this->contar('SELECT COUNT(*) FROM usuario'), $this->contar('SELECT COUNT(*) FROM classe'), $this->contar('SELECT COUNT(*) FROM importacao_pbl3')]);
        self::assertSame(0, $this->contar('SELECT COUNT(*) FROM arquivo'));
        self::assertSame([], glob($this->raiz . '/storage/*/*') ?: [], 'nenhum arquivo copiado');
    }

    public function testNomesFisicosMapeadosEColunaComErroDeDigitacao(): void
    {
        $base = new Pbl3Sintetico(['objeto' => 'obj_site', 'usuario' => 'contas'], 'cod_obeto');
        $base->popular();
        $mapa = MapaDeTabelas::padrao()->com(['objeto' => ['nome' => 'obj_site'], 'usuario' => ['nome' => 'contas'], 'tbl_blob' => ['colunas' => ['cod_objeto' => 'cod_obeto']]]);

        $this->arquivos();
        $r = $this->importar($base, mapa: $mapa, opcoes: new Opcoes(simular: false, uploads: $this->uploads));

        self::assertSame(5, $r->total('objetos importados'));
        self::assertSame(3, $r->total('usuários importados'));
        self::assertGreaterThanOrEqual(1, $r->total('arquivos importados'), 'a coluna cod_obeto do tbl_blob foi reconhecida');
    }

    public function testRaizEPaiDeDestino(): void
    {
        $servicos = Servicos::doBancoInstalado($this->caminhos);
        $raizSite = $servicos->sites->todos()[0]->objetoRaizId;
        self::assertNotNull($raizSite);

        $r = $this->importar($this->sintetico(), opcoes: new Opcoes(simular: false, raiz: 2, paiDestino: $raizSite));

        self::assertSame(2, $r->total('objetos importados'), 'a página e a subpágina');
        self::assertSame($raizSite, (int) $servicos->conexao()->fetchOne("SELECT pai_id FROM objeto WHERE titulo = 'Página um'"));
        self::assertSame(0, $this->contar("SELECT COUNT(*) FROM objeto WHERE titulo = 'Caneca'"));
    }

    public function testUrlRepetidaSobOMesmoPaiEhRenomeada(): void
    {
        $base = $this->sintetico();
        $base->objeto(9, 1, 2, 'Gêmea', 'pagina-um');

        $r = $this->importar($base);

        self::assertGreaterThanOrEqual(1, $r->total('endereços repetidos renomeados'));
        self::assertSame(2, $this->contar("SELECT COUNT(DISTINCT url_amigavel) FROM objeto WHERE titulo IN ('Página um', 'Gêmea')"));
    }

    public function testErroNaOrigemNaoGravaMeio(): void
    {
        $base = new Pbl3Sintetico();
        $base->c->executeStatement('DROP TABLE objeto');

        try {
            $this->importar($base);
            self::fail('devia recusar a base sem a tabela objeto');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('objeto', $e->getMessage());
        }
        self::assertSame(0, $this->contar('SELECT COUNT(*) FROM importacao_pbl3'));
    }

    public function testComandoSimulaPorPadraoEGravaComOpcao(): void
    {
        $arquivo = $this->raiz . '/origem.sqlite';
        $base = new Pbl3Sintetico();
        $base->popular();
        $c = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $arquivo]);
        foreach ($base->c->fetchAllAssociative("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $t) {
            $c->executeStatement((string) $t['sql']);
            foreach ($base->c->fetchAllAssociative('SELECT * FROM ' . $t['name']) as $linha) {
                $c->insert((string) $t['name'], $linha);
            }
        }
        $c->close();
        $t = new CommandTester(new ImportarPbl3Comando($this->caminhos));

        self::assertSame(0, $t->execute(['--origem-sqlite' => $arquivo]));
        self::assertStringContainsString('Nada foi gravado', $t->getDisplay());
        self::assertSame(0, $this->contar("SELECT COUNT(*) FROM objeto WHERE titulo = 'Caneca'"));

        $json = $this->raiz . '/relatorio.json';
        self::assertSame(0, $t->execute(['--origem-sqlite' => $arquivo, '--gravar' => true, '--relatorio-json' => $json]));
        self::assertSame(1, $this->contar("SELECT COUNT(*) FROM objeto WHERE titulo = 'Caneca'"));
        self::assertStringContainsString('redefin', $t->getDisplay() . 'redefin');
        self::assertStringNotContainsString('Beto', (string) file_get_contents($json), 'o relatório só leva contagens, nunca conteúdo');

        self::assertSame(1, $t->execute(['--origem-sqlite' => $arquivo, '--gravar' => true, '--simular' => true]));
        self::assertSame(1, $t->execute([]));
        self::assertSame(1, $t->execute(['--origem-sqlite' => $this->raiz . '/nao-existe.sqlite']));
    }
}
