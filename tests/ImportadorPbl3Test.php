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

        self::assertSame(6, $r->total('objetos importados'), 'raiz, página, subpágina, produto e lixeira: o órfão fica de fora');
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
        $atalho = $c->fetchAssociative("SELECT dados FROM objeto WHERE titulo = 'Atalho'");
        self::assertIsArray($atalho);
        $da = json_decode((string) $atalho['dados'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame((int) $produto['id'], (int) $da['destino_interno'], 'interlink (inteiro no PBL3) vira link interno remapeado');
        self::assertArrayNotHasKey('destino_externo', $da, 'o endereço provisório sai quando o alvo é resolvido');
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

        self::assertSame(6, $r->total('objetos importados'));
        self::assertSame($antes, [$this->contar('SELECT COUNT(*) FROM objeto'), $this->contar('SELECT COUNT(*) FROM usuario'), $this->contar('SELECT COUNT(*) FROM classe'), $this->contar('SELECT COUNT(*) FROM importacao_pbl3')]);
        self::assertSame(0, $this->contar('SELECT COUNT(*) FROM arquivo'));
        self::assertSame([], array_filter(array_keys($r->avisos()), static fn (string $a): bool => str_starts_with($a, 'objeto não importado')), 'a simulação não reprova objetos com arquivo obrigatório');
        self::assertSame([], glob($this->raiz . '/storage/*/*') ?: [], 'nenhum arquivo copiado');
    }

    private function baseDeFabrica(): Pbl3Sintetico
    {
        $base = new Pbl3Sintetico();
        $base->inserir('classe', ['cod_classe' => 1, 'nome' => 'Diretório', 'prefixo' => 'folder', 'descricao' => '', 'temfilhos' => 1]);
        $base->inserir('classe', ['cod_classe' => 4, 'nome' => 'Imagem', 'prefixo' => 'imagem', 'descricao' => '', 'temfilhos' => 0]);
        $base->inserir('classe', ['cod_classe' => 6, 'nome' => 'Link', 'prefixo' => 'link', 'descricao' => '', 'temfilhos' => 0]);
        $base->inserir('classe', ['cod_classe' => 10, 'nome' => 'Notícia', 'prefixo' => 'noticia', 'descricao' => '', 'temfilhos' => 1]);
        $props = [
            [1, 1, 8, 'texto', 'Texto'], [2, 4, 7, 'credito', 'Crédito'], [3, 6, 7, 'descricao', 'Descrição'], [4, 6, 7, 'endereco', 'Endereço'],
            [5, 10, 8, 'texto', 'Texto'], [6, 10, 7, 'subtitulo', 'Sub-Título'], [7, 10, 7, 'veiculo', 'Veículo'], [8, 10, 7, 'autor', 'Autor'],
            [9, 10, 7, 'link', 'Link para a original'], [10, 10, 2, 'destaque', 'Destaque'], [11, 10, 8, 'resumo', 'Resumo'], [12, 1, 2, 'menu_proprio', 'Menu próprio'],
        ];
        foreach ($props as [$cod, $classe, $tipo, $nome, $rotulo]) {
            $base->inserir('propriedade', ['cod_propriedade' => $cod, 'cod_classe' => $classe, 'cod_tipodado' => $tipo, 'nome' => $nome, 'posicao' => $cod, 'rotulo' => $rotulo]);
        }
        $base->objeto(1, 0, 1, 'Raiz inventada', 'raiz');
        $base->objeto(2, 1, 1, 'Seção', 'secao');
        $base->objeto(3, 2, 10, 'Notícia inventada', 'noticia-inventada');
        $base->objeto(4, 2, 6, 'Atalho externo', 'atalho-externo');
        $base->inserir('tbl_text', ['cod_objeto' => 2, 'cod_propriedade' => 1, 'valor' => '<p>Apresentação da seção</p>']);
        $base->inserir('tbl_boolean', ['cod_objeto' => 2, 'cod_propriedade' => 12, 'valor' => '1']);
        $base->inserir('tbl_string', ['cod_objeto' => 3, 'cod_propriedade' => 6, 'valor' => 'Subtítulo inventado']);
        $base->inserir('tbl_string', ['cod_objeto' => 3, 'cod_propriedade' => 7, 'valor' => 'Veículo inventado']);
        $base->inserir('tbl_string', ['cod_objeto' => 3, 'cod_propriedade' => 8, 'valor' => 'Autora inventada']);
        $base->inserir('tbl_string', ['cod_objeto' => 3, 'cod_propriedade' => 9, 'valor' => 'https://exemplo.invalid/original']);
        $base->inserir('tbl_boolean', ['cod_objeto' => 3, 'cod_propriedade' => 10, 'valor' => '1']);
        $base->inserir('tbl_text', ['cod_objeto' => 3, 'cod_propriedade' => 5, 'valor' => '<p>Texto da notícia</p>']);
        $base->inserir('tbl_text', ['cod_objeto' => 3, 'cod_propriedade' => 11, 'valor' => 'Resumo inventado']);
        $base->inserir('tbl_string', ['cod_objeto' => 4, 'cod_propriedade' => 4, 'valor' => 'https://exemplo.invalid/']);
        $base->inserir('tbl_string', ['cod_objeto' => 4, 'cod_propriedade' => 3, 'valor' => 'Descrição própria do atalho']);

        return $base;
    }

    /** @return array<string, string> nome => tipo das propriedades da classe */
    private function propriedadesDe(string $classe): array
    {
        $json = Servicos::doBancoInstalado($this->caminhos)->conexao()->fetchOne('SELECT propriedades FROM classe WHERE nome = ?', [$classe]);
        $saida = [];
        foreach (json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR) as $p) {
            $saida[$p['nome']] = $p['tipo'];
        }

        return $saida;
    }

    public function testCamposExtrasDeClassesDeFabricaSaoPreservados(): void
    {
        $r = $this->importar($this->baseDeFabrica());

        self::assertArrayNotHasKey('propriedade do PBL3 sem lugar no PBL4 (descartada)', $r->avisos());
        self::assertSame(0, $r->total('valores de propriedades descartadas'));
        self::assertSame('html', $this->propriedadesDe('pasta')['texto']);
        self::assertSame('booleano', $this->propriedadesDe('pasta')['menu_proprio']);
        self::assertSame('texto', $this->propriedadesDe('imagem')['credito']);
        $noticia = $this->propriedadesDe('noticia');
        self::assertSame('texto', $noticia['subtitulo']);
        self::assertSame('texto', $noticia['veiculo']);
        self::assertSame('texto', $noticia['autor']);
        self::assertSame('texto', $noticia['link']);
        self::assertSame('booleano', $noticia['destaque']);
        self::assertSame('texto', $this->propriedadesDe('link')['descricao_campo'], 'descricao é nome reservado: ganha sufixo');

        $c = Servicos::doBancoInstalado($this->caminhos)->conexao();
        $n = json_decode((string) $c->fetchOne("SELECT dados FROM objeto WHERE titulo = 'Notícia inventada'"), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Subtítulo inventado', $n['subtitulo']);
        self::assertSame('Veículo inventado', $n['veiculo']);
        self::assertSame('Autora inventada', $n['autor']);
        self::assertSame('https://exemplo.invalid/original', $n['link']);
        self::assertEquals(1, $n['destaque']);
        self::assertSame('Resumo inventado', $n['resumo']);
        $pasta = json_decode((string) $c->fetchOne("SELECT dados FROM objeto WHERE titulo = 'Seção'"), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Apresentação da seção', (string) $pasta['texto']);
        self::assertEquals(1, $pasta['menu_proprio']);
        $atalho = json_decode((string) $c->fetchOne("SELECT dados FROM objeto WHERE titulo = 'Atalho externo'"), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Descrição própria do atalho', $atalho['descricao_campo']);
        self::assertSame(1, $this->contar("SELECT COUNT(*) FROM objeto_indice WHERE chave = 'subtitulo'"), 'campo de texto extra entra no índice de busca');
    }

    public function testCamposExtrasNaoDuplicamAoReimportar(): void
    {
        $this->importar($this->baseDeFabrica());
        $antes = [$this->propriedadesDe('noticia'), $this->propriedadesDe('pasta'), $this->propriedadesDe('imagem'), $this->propriedadesDe('link')];
        $versao = $this->contar("SELECT versao FROM classe WHERE nome = 'noticia'");

        $r = $this->importar($this->baseDeFabrica());

        self::assertSame($antes, [$this->propriedadesDe('noticia'), $this->propriedadesDe('pasta'), $this->propriedadesDe('imagem'), $this->propriedadesDe('link')]);
        self::assertSame($versao, $this->contar("SELECT versao FROM classe WHERE nome = 'noticia'"), 'nada a acrescentar: a classe não muda');
        self::assertSame(0, $r->total('propriedades extras criadas em classes existentes'));
    }

    public function testCamposExtrasNovosChegamEmNovaRodada(): void
    {
        $base = $this->baseDeFabrica();
        $this->importar($base);
        $base->inserir('propriedade', ['cod_propriedade' => 20, 'cod_classe' => 10, 'cod_tipodado' => 7, 'nome' => 'cidade', 'posicao' => 20, 'rotulo' => 'Cidade']);
        $base->objeto(5, 2, 10, 'Outra notícia', 'outra-noticia');
        $base->inserir('tbl_string', ['cod_objeto' => 5, 'cod_propriedade' => 20, 'valor' => 'Cidade inventada']);

        $r = $this->importar($base);

        self::assertSame(1, $r->total('propriedades extras criadas em classes existentes'));
        $dados = json_decode((string) Servicos::doBancoInstalado($this->caminhos)->conexao()->fetchOne("SELECT dados FROM objeto WHERE titulo = 'Outra notícia'"), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Cidade inventada', $dados['cidade']);
    }

    public function testSimularInformaPropriedadesExtrasSemCriarNada(): void
    {
        $r = $this->importar($this->baseDeFabrica(), simular: true);

        self::assertArrayHasKey('propriedade extra criada na classe do PBL4 (campo do PBL3 sem par)', $r->avisos());
        self::assertGreaterThanOrEqual(8, $r->total('propriedades extras criadas em classes existentes'));
        self::assertArrayNotHasKey('texto', $this->propriedadesDe('pasta'));
        self::assertArrayNotHasKey('subtitulo', $this->propriedadesDe('noticia'));
    }

    public function testNomesFisicosMapeadosEColunaComErroDeDigitacao(): void
    {
        $base = new Pbl3Sintetico(['objeto' => 'obj_site', 'usuario' => 'contas'], 'cod_obeto');
        $base->popular();
        $mapa = MapaDeTabelas::padrao()->com(['objeto' => ['nome' => 'obj_site'], 'usuario' => ['nome' => 'contas'], 'tbl_blob' => ['colunas' => ['cod_objeto' => 'cod_obeto']]]);

        $this->arquivos();
        $r = $this->importar($base, mapa: $mapa, opcoes: new Opcoes(simular: false, uploads: $this->uploads));

        self::assertSame(6, $r->total('objetos importados'));
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
