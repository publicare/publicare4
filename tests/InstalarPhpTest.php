<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Atualizacao\Assinatura;
use Pbl\Atualizacao\Chaves;
use Pbl\Instalacao\GeradorDoInstalador;
use Pbl\Instalacao\Requisitos;
use PHPUnit\Framework\TestCase;

/**
 * instalar.php (etapa 1): o arquivo de teste é GERADO do modelo com um par de chaves de TESTE e um transporte que lê de uma pasta
 * de fixtures (um "servidor" falso local). As chaves de produção não são trocáveis em runtime: só o gerador troca, e este
 * teste confere que o arquivo de produção continua com as chaves de Chaves::* e sem nenhum caminho para chave externa.
 */
final class InstalarPhpTest extends TestCase
{
    private const MODELO = __DIR__ . '/../infra/instalador/instalar.php';
    private static string $pasta;
    private static string $fixtures;
    /** @var array{publica: string, privada: string} */
    private static array $par;
    private string $site;

    public static function setUpBeforeClass(): void
    {
        self::$pasta = Ajuda::pastaTemporaria();
        self::$fixtures = self::$pasta . '/fixtures';
        mkdir(self::$fixtures);
        self::$par = Assinatura::gerarPar();
        file_put_contents(self::$pasta . '/instalar-teste.php', GeradorDoInstalador::paraTeste((string) file_get_contents(self::MODELO), [self::$par['publica']], [str_repeat('0', 64)], self::$fixtures));
        require_once self::$pasta . '/instalar-teste.php';
    }

    public static function tearDownAfterClass(): void
    {
        Ajuda::apagar(self::$pasta);
    }

    protected function setUp(): void
    {
        $this->site = Ajuda::pastaTemporaria();
        foreach (glob(self::$fixtures . '/*') ?: [] as $f) {
            unlink($f);
        }
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->site);
    }

    // ---------- fixtures

    /**
     * @param list<array{0: string, 1: string, 2?: int, 3?: int}> $entradas nome, conteúdo, atributos externos, "versão feita por"
     */
    private static function zip(array $entradas, bool $comprimir = true): string
    {
        $locais = '';
        $central = '';
        foreach ($entradas as $e) {
            [$nome, $dados] = $e;
            $attr = $e[2] ?? (0100644 << 16);
            $feito = $e[3] ?? 3;
            $metodo = $comprimir && $dados !== '' ? 8 : 0;
            $comp = $metodo === 8 ? (string) gzdeflate($dados) : $dados;
            $crc = crc32($dados) & 0xFFFFFFFF;
            $offset = strlen($locais);
            $locais .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $metodo, 0, 0x21, $crc, strlen($comp), strlen($dados), strlen($nome), 0) . $nome . $comp;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, ($feito << 8) | 20, 20, 0, $metodo, 0, 0x21, $crc, strlen($comp), strlen($dados), strlen($nome), 0, 0, 0, 0, $attr, $offset) . $nome;
        }

        return $locais . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($entradas), count($entradas), strlen($central), strlen($locais), 0);
    }

    /** @return list<array{0: string, 1: string}> */
    private static function arquivosBons(): array
    {
        return [['app/src/Versao.php', "<?php // v\n"], ['bin/pbl', "#!/usr/bin/env php\n"], ['public/index.php', "<?php // index\n"], ['.htaccess', "# pbl\n"]];
    }

    /**
     * @param list<array{0: string, 1: string, 2?: int, 3?: int}>|null $arquivos
     * @param array<string, mixed> $manifestoExtra
     */
    private static function publicar(?array $arquivos = null, array $manifestoExtra = [], ?string $chavePrivada = null, bool $assinar = true, string $versao = '0.1.0'): string
    {
        $arquivos ??= self::arquivosBons();
        $hashes = [];
        foreach ($arquivos as $a) {
            $hashes[$a[0]] = hash('sha256', $a[1]);
        }
        $zip = self::zip(array_merge([[ 'PACOTE.json', (string) json_encode(['versao' => $versao, 'arquivos' => $hashes])]], $arquivos));
        file_put_contents(self::$fixtures . "/pbl4-{$versao}.zip", $zip);
        $manifesto = array_replace_recursive([
            'formato' => 1, 'canal' => 'estavel', 'versao' => $versao, 'data' => '2026-11-16', 'php_minimo' => '8.2', 'atualizar_a_partir_de' => '0.0.0',
            'pacote' => ['url' => "pbl4-{$versao}.zip", 'tamanho' => strlen($zip), 'sha256' => hash('sha256', $zip)],
        ], $manifestoExtra);
        $json = json_encode($manifesto, JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents(self::$fixtures . '/latest.json', $json);
        if ($assinar) {
            file_put_contents(self::$fixtures . '/latest.json.sig', Assinatura::assinar($json, $chavePrivada ?? self::$par['privada']) . "\n");
        }

        return $json;
    }

    /** @return list<string> o que há na pasta do site, ordenado */
    private function conteudo(): array
    {
        $l = array_values(array_diff(scandir($this->site) ?: [], ['.', '..']));
        sort($l);

        return $l;
    }

    private function recusa(string $trecho, callable $acao): void
    {
        try {
            $acao();
            self::fail('deveria recusar: ' . $trecho);
        } catch (\PblxErro $e) {
            self::assertStringContainsString($trecho, $e->getMessage());
            self::assertStringNotContainsString($this->site, $e->getMessage(), 'a mensagem não revela o caminho do servidor');
            self::assertStringNotContainsString(self::$pasta, $e->getMessage());
        }
    }

    // ---------- requisitos

    /** @return array<string, mixed> */
    private function ambienteBom(): array
    {
        return ['php' => '8.3.1', 'extensoes' => array_fill_keys(array_merge(array_keys(\PBLX_EXTENSOES), ['openssl', 'curl', 'zip']), true),
            'gravavel' => true, 'open_basedir' => '', 'dentro_open_basedir' => true, 'allow_url_fopen' => true];
    }

    /** @param array<string, mixed> $amb */
    private function falhas(array $amb): string
    {
        $nomes = [];
        foreach (pblx_requisitos($amb) as $i) {
            if (!$i['ok'] && $i['obrigatorio']) {
                $nomes[] = $i['nome'];
            }
        }

        return implode('|', $nomes);
    }

    public function testRequisitosOkEListaFaltasComComoCorrigir(): void
    {
        self::assertTrue(pblx_requisitos_ok(pblx_requisitos($this->ambienteBom())));

        $velho = ['php' => '8.1.9'] + $this->ambienteBom();
        self::assertStringContainsString('PHP 8.2', $this->falhas($velho));
        $itens = pblx_requisitos($velho);
        self::assertStringContainsString('versão do PHP', $itens[0]['detalhe']);

        $semSodium = $this->ambienteBom();
        $semSodium['extensoes']['sodium'] = false;
        self::assertSame('Extensão sodium', $this->falhas($semSodium));

        $semEscrita = ['gravavel' => false] + $this->ambienteBom();
        self::assertStringContainsString('Escrita', $this->falhas($semEscrita));
        $semBasedir = ['dentro_open_basedir' => false] + $this->ambienteBom();
        self::assertStringContainsString('open_basedir', $this->falhas($semBasedir));
    }

    public function testRedeExigeCurlOuAllowUrlFopenComOpenssl(): void
    {
        $a = $this->ambienteBom();
        $a['extensoes']['curl'] = false;
        self::assertSame('', $this->falhas($a), 'allow_url_fopen + openssl basta');
        $a['allow_url_fopen'] = false;
        self::assertStringContainsString('Conexão HTTPS', $this->falhas($a));
        $b = $this->ambienteBom();
        $b['allow_url_fopen'] = false;
        self::assertSame('', $this->falhas($b), 'curl basta');
        $c = $this->ambienteBom();
        $c['extensoes']['curl'] = false;
        $c['extensoes']['openssl'] = false;
        self::assertStringContainsString('Conexão HTTPS', $this->falhas($c));
    }

    public function testZipAusenteNaoImpedeOLeitorProprioDaConta(): void
    {
        $a = $this->ambienteBom();
        $a['extensoes']['zip'] = false;
        $itens = pblx_requisitos($a);
        $zip = array_values(array_filter($itens, static fn (array $i): bool => $i['nome'] === 'Extensão zip'));

        self::assertFalse($zip[0]['obrigatorio']);
        self::assertTrue(pblx_requisitos_ok($itens));
    }

    public function testListaDeExtensoesIgualAoRequisitosDoApp(): void
    {
        $doApp = (new \ReflectionClass(Requisitos::class))->getConstant('EXTENSOES');

        self::assertIsArray($doApp);
        self::assertEqualsCanonicalizing(array_keys($doApp), array_keys(\PBLX_EXTENSOES));
    }

    public function testAmbienteRealDoTesteEhValido(): void
    {
        $amb = pblx_ambiente($this->site);

        self::assertTrue($amb['gravavel']);
        self::assertTrue(pblx_requisitos_ok(pblx_requisitos($amb)));
    }

    // ---------- assinatura, hash e tamanho

    public function testAssinaturaBoaInstalaOPacoteEDeixaSoOEsperado(): void
    {
        self::publicar();

        $r = pblx_instalar_pacote($this->site, 'estavel');

        self::assertSame('0.1.0', $r['versao']);
        self::assertSame(['.htaccess', 'app', 'bin', 'public'], $this->conteudo(), 'sem temporário nem PACOTE.json');
        self::assertSame("<?php // v\n", file_get_contents($this->site . '/app/src/Versao.php'));
        self::assertTrue(is_executable($this->site . '/bin/pbl'));
    }

    public function testManifestoAdulteradoEhRecusadoEnadaSeInstala(): void
    {
        $json = self::publicar();
        file_put_contents(self::$fixtures . '/latest.json', str_replace('2026-11-16', '2026-11-17', $json));

        $this->recusa('assinatura do manifesto NÃO confere', fn () => pblx_instalar_pacote($this->site, 'estavel'));
        self::assertSame([], $this->conteudo());
    }

    public function testAssinaturaDeChaveDesconhecidaEhRecusada(): void
    {
        self::publicar(null, [], Assinatura::gerarPar()['privada']);

        $this->recusa('assinatura do manifesto NÃO confere', fn () => pblx_instalar_pacote($this->site, 'estavel'));
        self::assertSame([], $this->conteudo());
    }

    public function testSemAssinaturaOuComLixoNaAssinaturaEhRecusado(): void
    {
        self::publicar(null, [], null, false);
        $this->recusa('código 404', fn () => pblx_instalar_pacote($this->site, 'estavel'));
        file_put_contents(self::$fixtures . '/latest.json.sig', 'isso-nao-e-base64!!');
        $this->recusa('assinatura do manifesto NÃO confere', fn () => pblx_instalar_pacote($this->site, 'estavel'));
        self::assertSame([], $this->conteudo());
    }

    public function testManifestoTrazendoChaveNovaEIgnoradoENaoVira(): void
    {
        $nova = Assinatura::gerarPar();
        self::publicar(null, ['chaves' => ['uso' => $nova['publica']]], $nova['privada']);

        $this->recusa('assinatura do manifesto NÃO confere', fn () => pblx_instalar_pacote($this->site, 'estavel'));
        self::assertSame([], $this->conteudo());
    }

    public function testHashErradoEhRecusado(): void
    {
        self::publicar(null, ['pacote' => ['sha256' => str_repeat('a', 64)]]);

        $this->recusa('SHA-256', fn () => pblx_instalar_pacote($this->site, 'estavel'));
        self::assertSame([], $this->conteudo());
    }

    public function testTamanhoErradoEhRecusado(): void
    {
        $json = self::publicar();
        $tamanho = (int) filesize(self::$fixtures . '/pbl4-0.1.0.zip');
        self::publicar(null, ['pacote' => ['tamanho' => $tamanho + 1]]);

        $this->recusa('tamanho errado', fn () => pblx_instalar_pacote($this->site, 'estavel'));
        self::assertNotSame('', $json);
        self::assertSame([], $this->conteudo());
    }

    public function testCanalDoManifestoTemQueSerOPedido(): void
    {
        self::publicar(null, ['canal' => 'beta']);

        $this->recusa('não é do canal pedido', fn () => pblx_instalar_pacote($this->site, 'estavel'));
    }

    public function testPhpMinimoDoManifestoEhRespeitado(): void
    {
        self::publicar(null, ['php_minimo' => '99.0']);

        $this->recusa('precisa do PHP 99.0', fn () => pblx_instalar_pacote($this->site, 'estavel'));
    }

    public function testManifestoMalFormadoEhRecusado(): void
    {
        foreach (['formato' => 2, 'versao' => 'x', 'canal' => 'qualquer'] as $campo => $valor) {
            self::publicar(null, [$campo => $valor]);
            $this->recusa('manifesto', fn () => pblx_instalar_pacote($this->site, 'estavel'));
        }
        self::publicar(null, ['pacote' => ['tamanho' => 999_999_999]]);
        $this->recusa('pacote inválido', fn () => pblx_instalar_pacote($this->site, 'estavel'));
    }

    // ---------- rede: política de URL

    public function testSoHttpsDoHostOficialEPermitido(): void
    {
        $base = 'https://atualizacoes.publicare.org';
        pblx_url_permitida($base . '/latest.json', $base);
        $this->addToAssertionCount(1);
        foreach (['http://atualizacoes.publicare.org/latest.json', 'https://evil.example/latest.json', 'https://u:p@atualizacoes.publicare.org/x',
            'https://atualizacoes.publicare.org:8443/x', 'ftp://atualizacoes.publicare.org/x', 'file:///etc/passwd', '//atualizacoes.publicare.org/x', 'https://atualizacoes.publicare.org.evil.example/x'] as $url) {
            $this->recusa('Endereço recusado', static fn () => pblx_url_permitida($url, $base));
        }
    }

    public function testResolverRedirecionamentoRelativoNuncaTrocaDeHost(): void
    {
        self::assertSame('https://a.org/pacotes/x.zip', pblx_resolver('/pacotes/x.zip', 'https://a.org/latest.json'));
        self::assertSame('https://a.org/pbl4.zip', pblx_resolver('pbl4.zip', 'https://a.org/latest.json'));
        $outro = pblx_resolver('https://evil.example/x.zip', 'https://a.org/latest.json');
        $this->recusa('Endereço recusado', static fn () => pblx_url_permitida($outro, 'https://a.org'));
    }

    public function testUrlDoPacoteEmOutroHostNoManifestoAssinadoEhRecusada(): void
    {
        self::publicar(null, ['pacote' => ['url' => 'https://evil.example/pbl4-0.1.0.zip']]);

        $this->recusa('Endereço recusado', fn () => pblx_instalar_pacote($this->site, 'estavel'));
        self::assertSame([], $this->conteudo());
    }

    // ---------- zip malicioso (manifesto e hash corretos: o ataque está dentro do zip)

    /** @param list<array{0: string, 1: string, 2?: int, 3?: int}> $maus */
    private function pacoteMau(array $maus): void
    {
        $arquivos = array_merge(self::arquivosBons(), $maus);
        self::publicar($arquivos);
    }

    public function testZipSlipECaminhosPerigososSaoRecusados(): void
    {
        foreach (['../evil.php', 'app/../../evil.php', '/etc/evil.php', 'app\\..\\evil.php', 'C:evil.php', 'app//x.php', "app/x\0.php", './app/x.php'] as $nome) {
            $this->pacoteMau([[$nome, 'x']]);
            $this->recusa('', fn () => pblx_instalar_pacote($this->site, 'estavel'));
            self::assertSame([], $this->conteudo(), "nome: {$nome}");
            self::assertFileDoesNotExist(dirname($this->site) . '/evil.php');
        }
    }

    public function testItemForaDaListaDoPacoteEhRecusado(): void
    {
        foreach (['dados/config/instalado.lock', 'instalar.php', 'index.php', '.git/config'] as $nome) {
            $this->pacoteMau([[$nome, 'x']]);
            $this->recusa('fora do permitido', fn () => pblx_instalar_pacote($this->site, 'estavel'));
            self::assertSame([], $this->conteudo());
        }
    }

    public function testLinkSimbolicoNoZipEhRecusado(): void
    {
        $this->pacoteMau([['app/atalho', '/etc/passwd', 0120777 << 16]]);

        $this->recusa('link simbólico', fn () => pblx_instalar_pacote($this->site, 'estavel'));
        self::assertSame([], $this->conteudo());
    }

    public function testBombaDeZipEArquivoQueMenteNoTamanhoSaoRecusados(): void
    {
        $zip = self::zip([['PACOTE.json', '{}'], ['app/bomba', str_repeat('0', 300_000)]]);
        // O cabeçalho passa a dizer que o arquivo é pequeno: gzinflate com limite não pode estourar.
        $p = strpos($zip, 'app/bomba');
        self::assertNotFalse($p);
        $mentira = $zip;
        $central = strrpos($mentira, "PK\x01\x02");
        self::assertNotFalse($central);
        $mentira = substr_replace($mentira, pack('V', 100), $central + 24, 4);
        file_put_contents($this->site . '/z.zip', $mentira);
        mkdir($this->site . '/x');

        $this->recusa('corrompido', fn () => pblx_extrair($this->site . '/z.zip', $this->site . '/x', '0.1.0'));
        self::assertSame([], array_values(array_diff(scandir($this->site . '/x') ?: [], ['.', '..'])), 'nada gravado');
    }

    public function testArquivoMaiorQueOLimiteEhRecusadoSemDescomprimir(): void
    {
        $zip = self::zip([['PACOTE.json', '{}'], ['app/grande', 'x']]);
        $central = strrpos($zip, "PK\x01\x02");
        self::assertNotFalse($central);
        file_put_contents($this->site . '/z.zip', substr_replace($zip, pack('V', \PBLX_MAX_ARQUIVO + 1), $central + 24, 4));
        mkdir($this->site . '/x');

        $this->recusa('não aceita', fn () => pblx_extrair($this->site . '/z.zip', $this->site . '/x', '0.1.0'));
    }

    public function testArquivoAdulteradoDentroDoZipNaoConferePeloPacoteJson(): void
    {
        $zip = self::zip([['PACOTE.json', (string) json_encode(['versao' => '0.1.0', 'arquivos' => ['app/a.php' => hash('sha256', 'bom')]])], ['app/a.php', 'mau']]);
        file_put_contents($this->site . '/z.zip', $zip);
        mkdir($this->site . '/x');

        $this->recusa('não confere com o PACOTE.json', fn () => pblx_extrair($this->site . '/z.zip', $this->site . '/x', '0.1.0'));
    }

    public function testPacoteJsonDeOutraVersaoOuComArquivoExtraEhRecusado(): void
    {
        $zip = self::zip([['PACOTE.json', (string) json_encode(['versao' => '0.9.9', 'arquivos' => ['app/a.php' => hash('sha256', 'a')]])], ['app/a.php', 'a']]);
        file_put_contents($this->site . '/z.zip', $zip);
        mkdir($this->site . '/x');
        $this->recusa('PACOTE.json esperado', fn () => pblx_extrair($this->site . '/z.zip', $this->site . '/x', '0.1.0'));

        $extra = self::zip([['PACOTE.json', (string) json_encode(['versao' => '0.1.0', 'arquivos' => []])], ['app/a.php', 'a']]);
        file_put_contents($this->site . '/z.zip', $extra);
        $this->recusa('não bate', fn () => pblx_extrair($this->site . '/z.zip', $this->site . '/x', '0.1.0'));
    }

    public function testZipInvalidoOuCriptografadoOuDuplicadoEhRecusado(): void
    {
        file_put_contents($this->site . '/z.zip', 'isso não é um zip, só texto comprido o bastante para passar do tamanho mínimo');
        $this->recusa('zip válido', fn () => pblx_extrair($this->site . '/z.zip', $this->site . '/x', '0.1.0'));

        file_put_contents($this->site . '/z.zip', self::zip([['PACOTE.json', '{}'], ['app/a', '1'], ['app/a', '2']]));
        $this->recusa('repete', fn () => pblx_extrair($this->site . '/z.zip', $this->site . '/x', '0.1.0'));
    }

    public function testLeitorDeZipFuncionaSemCompressaoTambem(): void
    {
        file_put_contents($this->site . '/z.zip', self::zip([['PACOTE.json', (string) json_encode(['versao' => '0.1.0', 'arquivos' => ['app/a.php' => hash('sha256', 'ola')]])], ['app/a.php', 'ola']], false));
        mkdir($this->site . '/x');

        pblx_extrair($this->site . '/z.zip', $this->site . '/x', '0.1.0');

        self::assertSame('ola', file_get_contents($this->site . '/x/app/a.php'));
    }

    // ---------- já instalado, pasta ocupada e falha no meio

    public function testRecusaSeJaInstaladoOuSeAPastaJaTemOPublicare(): void
    {
        self::publicar();
        mkdir($this->site . '/dados/config', 0775, true);
        touch($this->site . '/dados/config/instalado.lock');
        $this->recusa('já está instalado', fn () => pblx_instalar_pacote($this->site, 'estavel'));
        self::assertTrue(\pblx_ja_instalado($this->site));

        unlink($this->site . '/dados/config/instalado.lock');
        mkdir($this->site . '/app');
        $this->recusa('já existe', fn () => pblx_instalar_pacote($this->site, 'estavel'));
        self::assertFileDoesNotExist($this->site . '/bin/pbl');
    }

    public function testFalhaNoMeioDesfazTudoERestauraOHtaccessAntigo(): void
    {
        self::publicar();
        file_put_contents($this->site . '/.htaccess', "# meu htaccess\n");
        file_put_contents($this->site . '/foto.jpg', 'x');
        $antes = $this->conteudo();

        foreach ([1, 2, 3, 4, 5] as $falhaNoPasso) {
            $n = 0;
            $renomear = static function (string $de, string $para) use (&$n, $falhaNoPasso): bool {
                return ++$n === $falhaNoPasso ? false : rename($de, $para);
            };
            try {
                pblx_instalar_pacote($this->site, 'estavel', $renomear);
                self::fail("passo {$falhaNoPasso} deveria falhar");
            } catch (\PblxErro $e) {
                self::assertStringNotContainsString($this->site, $e->getMessage());
            }
            self::assertSame($antes, $this->conteudo(), "falha no passo {$falhaNoPasso}: nada de lixo");
            self::assertSame("# meu htaccess\n", file_get_contents($this->site . '/.htaccess'));
        }
    }

    public function testHtaccessExistenteEGuardadoAoInstalarComSucesso(): void
    {
        self::publicar();
        file_put_contents($this->site . '/.htaccess', "# meu htaccess\n");

        $r = pblx_instalar_pacote($this->site, 'estavel');

        self::assertTrue($r['htaccess_antigo']);
        self::assertSame("# meu htaccess\n", file_get_contents($this->site . '/.htaccess.antes-pbl4'));
        self::assertSame("# pbl\n", file_get_contents($this->site . '/.htaccess'));
    }

    public function testFalhaNoDownloadNaoDeixaTemporario(): void
    {
        // sem fixtures: o "servidor" responde 404
        $this->recusa('código 404', fn () => pblx_instalar_pacote($this->site, 'estavel'));

        self::assertSame([], $this->conteudo());
    }

    // ---------- layout, exposição, posse, CSRF e Origin

    public function testDetectaOLayout(): void
    {
        mkdir($this->site . '/public_html', 0775, true);
        self::assertSame('raiz', pblx_layout($this->site));
        self::assertSame('raiz', pblx_layout($this->site . '/public_html'));
        mkdir($this->site . '/private');
        self::assertSame('hestia', pblx_layout($this->site . '/public_html'));
        mkdir($this->site . '/app/src', 0775, true);
        touch($this->site . '/app/src/Versao.php');
        mkdir($this->site . '/public');
        self::assertSame('public', pblx_layout($this->site . '/public'));
    }

    public function testExposicaoDePastasInternasBloqueiaOuAvisa(): void
    {
        self::assertSame('protegido', pblx_decidir_exposicao(['/app' => 403, '/dados' => 404]));
        self::assertSame('exposto', pblx_decidir_exposicao(['/app' => 403, '/dados' => 200]));
        self::assertSame('exposto', pblx_decidir_exposicao(['/app' => null, '/dados' => 200]));
        self::assertSame('incerto', pblx_decidir_exposicao(['/app' => null, '/dados' => 403]));
    }

    public function testCsrfEOriginSaoConferidos(): void
    {
        $t = bin2hex(random_bytes(16));
        self::assertTrue(pblx_csrf_confere($t, $t));
        self::assertFalse(pblx_csrf_confere($t, strrev($t)));
        self::assertFalse(pblx_csrf_confere('', ''), 'sem cookie não vale');
        self::assertFalse(pblx_csrf_confere('abc', 'abc'), 'formato inválido');

        self::assertTrue(pblx_origem_confere('site.org', 'https://site.org', ''));
        self::assertTrue(pblx_origem_confere('site.org:8080', 'http://site.org:8080', ''));
        self::assertTrue(pblx_origem_confere('site.org', '', 'https://site.org/instalar.php'), 'Referer quando não há Origin');
        self::assertFalse(pblx_origem_confere('site.org', 'https://evil.example', ''));
        self::assertFalse(pblx_origem_confere('site.org', '', ''), 'sem Origin nem Referer');
        self::assertFalse(pblx_origem_confere('site.org', 'null', ''));
    }

    /**
     * Roda pblx_web num processo à parte, simulando o servidor; devolve o HTML.
     *
     * @param array<string, string> $post
     * @param array<string, string> $cookie
     */
    private function web(string $metodo, array $post = [], array $cookie = [], string $host = 'meu.site', string $origin = ''): string
    {
        $codigo = '$_SERVER = ["REQUEST_METHOD" => $argv[2], "HTTP_HOST" => $argv[3], "HTTP_ORIGIN" => $argv[4], "SCRIPT_NAME" => "/instalar.php"];'
            . '$_POST = json_decode($argv[5], true); $_COOKIE = json_decode($argv[6], true); require $argv[1]; ob_start(); pblx_web($argv[7]); echo ob_get_clean();';
        $saida = [];
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' ' . implode(' ', array_map('escapeshellarg', [self::$pasta . '/instalar-teste.php', $metodo, $host, $origin, json_encode($post) ?: '{}', json_encode($cookie) ?: '{}', $this->site])) . ' 2>&1', $saida);

        return implode("\n", $saida);
    }

    public function testSemPosseONadaEGravadoEPedeOArquivoDePosse(): void
    {
        $html = $this->web('GET');

        self::assertStringContainsString('Falta uma confirmação', $html);
        self::assertStringContainsString(\PBLX_POSSE, $html);
        self::assertStringNotContainsString('<form', $html);
        $post = $this->web('POST', ['acao' => 'instalar', 'csrf' => str_repeat('a', 32)], ['pblx_csrf' => str_repeat('a', 32)], 'meu.site', 'https://meu.site');
        self::assertStringContainsString('Falta uma confirmação', $post, 'sem posse o POST nem chega à ação');
        self::assertSame([], $this->conteudo());
    }

    public function testComPosseMostraOFormularioEPostSemCsrfOuOrigemEhRecusado(): void
    {
        touch($this->site . '/' . \PBLX_POSSE);
        self::assertStringContainsString('name="csrf"', $this->web('GET'));
        $t = str_repeat('b', 32);

        $semCookie = $this->web('POST', ['acao' => 'instalar', 'csrf' => $t], [], 'meu.site', 'https://meu.site');
        $origemErrada = $this->web('POST', ['acao' => 'instalar', 'csrf' => $t], ['pblx_csrf' => $t], 'meu.site', 'https://evil.example');
        $tokenErrado = $this->web('POST', ['acao' => 'instalar', 'csrf' => 'c' . substr($t, 1)], ['pblx_csrf' => $t], 'meu.site', 'https://meu.site');

        foreach ([$semCookie, $origemErrada, $tokenErrado] as $html) {
            self::assertStringContainsString('Pedido recusado', $html);
        }
        self::assertSame([\PBLX_POSSE], $this->conteudo(), 'nada baixado nem extraído');
    }

    public function testSiteJaInstaladoRecusaERequisitosFaltandoNaoGravam(): void
    {
        mkdir($this->site . '/dados/config', 0775, true);
        touch($this->site . '/dados/config/instalado.lock');
        self::assertStringContainsString('já está instalado', $this->web('GET'));
    }

    public function testPreparaDadosMovendoAPosseParaOAssistente(): void
    {
        touch($this->site . '/' . \PBLX_POSSE);

        pblx_preparar_dados($this->site, true);

        self::assertFileExists($this->site . '/dados/' . \PBLX_POSSE);
        self::assertFileDoesNotExist($this->site . '/' . \PBLX_POSSE);
        self::assertSame('0750', substr(sprintf('%o', fileperms($this->site . '/dados')), -4));
    }

    // ---------- ponta a ponta no terminal (processo à parte, como o instalar.sh faz)

    public function testExecucaoNoTerminalInstalaEApagaASiMesmo(): void
    {
        self::publicar();
        copy(self::$pasta . '/instalar-teste.php', $this->site . '/instalar.php');

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->site . '/instalar.php') . ' 2>&1', $saida, $codigo);

        self::assertSame(0, $codigo, implode("\n", $saida));
        self::assertSame(['.htaccess', 'app', 'bin', 'dados', 'public'], $this->conteudo(), 'o instalar.php se apagou e não sobrou temporário');
        self::assertSame('0750', substr(sprintf('%o', fileperms($this->site . '/dados')), -4));
        self::assertStringNotContainsString($this->site, implode("\n", $saida), 'a saída não revela o caminho do servidor');
    }

    public function testExecucaoNoTerminalComAssinaturaRuimNaoInstalaNada(): void
    {
        $json = self::publicar();
        file_put_contents(self::$fixtures . '/latest.json', $json . ' ');
        copy(self::$pasta . '/instalar-teste.php', $this->site . '/instalar.php');

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->site . '/instalar.php') . ' 2>&1', $saida, $codigo);

        self::assertSame(1, $codigo);
        self::assertSame(['instalar.php'], $this->conteudo());
        self::assertStringContainsString('assinatura', implode("\n", $saida));
    }

    // ---------- o arquivo de produção: chaves fixas, sem caminho para chave externa

    public function testChavesEmbutidasSaoAsDeProducaoESoOGeradorAsTroca(): void
    {
        $modelo = (string) file_get_contents(self::MODELO);

        self::assertSame($modelo, GeradorDoInstalador::producao($modelo, '0.1.0'), 'o modelo commitado é exatamente a saída de produção do gerador');
        foreach (array_merge(Chaves::USO, Chaves::RECUPERACAO) as $chave) {
            self::assertStringContainsString("'{$chave}'", $modelo);
        }
        self::assertSame(1, preg_match("/const PBLX_CHAVES_USO = \['[0-9a-f]{64}'\];/", $modelo));
        $teste = (string) file_get_contents(self::$pasta . '/instalar-teste.php');
        foreach (array_merge(Chaves::USO, Chaves::RECUPERACAO) as $chave) {
            self::assertStringNotContainsString($chave, $teste, 'o arquivo de teste não carrega as chaves de produção');
        }
    }

    public function testProducaoNaoLeAmbienteParametroNemExecutaConteudoBaixado(): void
    {
        $codigo = (string) file_get_contents(self::MODELO);
        $tokens = array_filter(token_get_all($codigo), 'is_array');
        $proibidos = ['eval', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'getenv', 'putenv', 'include', 'include_once', 'require', 'require_once', 'assert', 'create_function', 'unserialize', 'extract', 'parse_str'];
        foreach ($tokens as [$id, $texto]) {
            $nome = strtolower($texto);
            if (in_array($id, [T_STRING, T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                self::assertNotContains($nome, $proibidos, "uso proibido: {$nome}");
            }
            if ($id === T_VARIABLE) {
                self::assertNotContains($texto, ['$_GET', '$_REQUEST', '$_ENV', '$_FILES'], 'chave/URL não vêm de parâmetro');
            }
        }
        self::assertDoesNotMatchRegularExpression('/\$argv|ARGV/', (string) preg_replace('/\$_SERVER\[\'argv\'\]/', '', $codigo), 'o terminal só aceita --beta');
    }

    public function testSintaxeNaoPassaDoPhp74(): void
    {
        $proibidos = ['T_MATCH', 'T_ENUM', 'T_READONLY', 'T_NULLSAFE_OBJECT_OPERATOR', 'T_ATTRIBUTE'];
        $codigo = (string) file_get_contents(self::MODELO);
        foreach (token_get_all($codigo) as $t) {
            if (!is_array($t)) {
                continue;
            }
            foreach ($proibidos as $nome) {
                self::assertFalse(defined($nome) && $t[0] === constant($nome), "sintaxe de PHP 8 em instalar.php: {$nome}");
            }
        }
        self::assertDoesNotMatchRegularExpression('/\b(str_contains|str_starts_with|str_ends_with|array_is_list|mb_str_pad)\s*\(/', $codigo);
        self::assertDoesNotMatchRegularExpression('/\bfunction\s+\w+\s*\([^)]*\b(?:int|string|array|bool|float)\s*\|/', $codigo, 'sem tipos união');
        self::assertDoesNotMatchRegularExpression('/\)\s*:\s*(?:mixed|never|static)\b/', $codigo);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg(self::MODELO) . ' 2>&1', $saida, $codigoSaida);
        self::assertSame(0, $codigoSaida);
    }

    public function testNadaEGravadoForaDaPastaDoSite(): void
    {
        $codigo = (string) preg_replace('/^\s*(\/\/|\*|\/\*).*$/m', '', (string) file_get_contents(self::MODELO));

        self::assertStringNotContainsString('sys_get_temp_dir', $codigo, 'o temporário fica dentro da pasta do site');
        self::assertStringNotContainsString('tempnam', $codigo);
        self::assertStringContainsString('.pbl-instalar-tmp-', $codigo);
    }
}
