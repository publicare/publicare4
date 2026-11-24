<?php

declare(strict_types=1);

namespace Pbl\Testes\Manual;

use Pbl\Manual\Compilador;
use Pbl\Manual\Edicao;
use Pbl\Manual\ErroDeManual;
use PHPUnit\Framework\TestCase;

/** ADRs 0039 a 0042: o manual real compila; links, órfãs, front matter, imagens, pesos e navegação. */
final class ManualCompilacaoTest extends TestCase
{
    private string $raiz;
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->raiz = dirname(__DIR__, 2);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '') {
            foreach (glob($this->tmp . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmp);
        }
    }

    /** @return array<string, mixed> */
    private function real(): array
    {
        return (new Compilador($this->raiz . '/docs/usuario', $this->raiz . '/manual/links-externos.json', null, true))->compilar();
    }

    /**
     * @param array<string, string> $arquivos nome => conteúdo
     * @return array<string, mixed>
     */
    private function compilarFixture(array $arquivos, ?string $capturas = null, bool $sem = true): array
    {
        $this->tmp = sys_get_temp_dir() . '/manual-t-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
        foreach ($arquivos as $n => $c) {
            file_put_contents($this->tmp . '/' . $n, $c);
        }

        return (new Compilador($this->tmp, $this->raiz . '/manual/links-externos.json', $capturas, $sem))->compilar();
    }

    private const CAPS = "---\ntitulo: Caps\nresumo: Lista.\n---\n\n| slug | Título | Resumo |\n|---|---|---|\n| a | Capítulo A | Resumo A. |\n";

    private function pagina(string $ordem, string $corpo, string $extra = ''): string
    {
        return "---\ntitulo: Página {$ordem}\nresumo: Resumo {$ordem}.\ncapitulo: a\nordem: {$ordem}\n{$extra}---\n\n{$corpo}\n";
    }

    public function testManualRealCompilaComLinksFrontMatterEPaginasSemOrfas(): void
    {
        $p = $this->real();
        self::assertGreaterThanOrEqual(6, count($p['capitulos']));
        $slugs = [];
        foreach ($p['capitulos'] as $c) {
            self::assertNotSame([], $c['paginas'], "capítulo {$c['slug']} vazio");
            foreach ($c['paginas'] as $pg) {
                $slugs[] = $pg['slug'];
                self::assertNotSame('', $pg['titulo']);
                self::assertNotSame('', $pg['resumo']);
            }
        }
        // toda página publicável do diretório está no pacote (nenhuma órfã)
        $esperadas = 0;
        foreach (glob($this->raiz . '/docs/usuario/*.md') ?: [] as $f) {
            $t = (string) file_get_contents($f);
            if (basename($f) !== 'capitulos.md' && !str_contains($t, "publico: contribuicao") && preg_match('/^---\R/', $t) === 1 && !str_contains($t, 'publico: nao')) {
                ++$esperadas;
            }
        }
        self::assertSame($esperadas, count($slugs), 'página fora do pacote (órfã) ou a mais');
        self::assertSame(count($slugs), count(array_unique($slugs)));
    }

    public function testPesosDeDezEmDezEAnteriorProximo(): void
    {
        $p = $this->real();
        foreach ($p['capitulos'] as $i => $c) {
            self::assertSame(($i + 1) * 10, $c['peso']);
            foreach ($c['paginas'] as $j => $pg) {
                self::assertSame(($j + 1) * 10, $pg['peso']);
                self::assertNotSame('', $pg['hash_origem'] ?? 'x');
            }
        }
        $ultima = end($p['capitulos'])['paginas'];
        self::assertStringNotContainsString('Próxima', end($ultima)['navegacao'] ?? '');
        $primeira = $p['capitulos'][0]['paginas'][0];
        self::assertStringNotContainsString('Anterior', $primeira['navegacao']);
    }

    public function testManualNaoTemDatasNemVersoesNoTexto(): void
    {
        foreach (glob($this->raiz . '/docs/usuario/*.md') ?: [] as $f) {
            $t = (string) file_get_contents($f);
            if (str_contains($t, 'publico: contribuicao')) {
                continue;
            }
            self::assertSame(0, preg_match('/\b20\d\d-\d\d-\d\d\b/', $t), basename($f) . ': data no texto');
            self::assertSame(0, preg_match('/\b\d+\.\d+\.\d+(-[a-z]+\.?\d*)?\b/', str_replace('127.0.0.1', '', $t)), basename($f) . ': número de versão no texto');
        }
    }

    public function testTodaCapturaDoManualEstaDeclaradaEUsada(): void
    {
        // o Compilador falha em captura usada e não declarada, e em declarada e não usada; compilar já prova os dois.
        $p = $this->real();
        $declaradas = array_column(json_decode((string) file_get_contents($this->raiz . '/docs/usuario/capturas.json'), true)['capturas'], 'id');
        sort($declaradas);
        $usadas = $p['capturas'];
        sort($usadas);
        self::assertSame($declaradas, $usadas);
    }

    public function testLinkQuebradoInterrompe(): void
    {
        $this->expectException(ErroDeManual::class);
        $this->expectExceptionMessageMatches('/nao-existe/');
        $this->compilarFixture(['capitulos.md' => self::CAPS, 'x.md' => $this->pagina('10', '[ver](nao-existe.md)')]);
    }

    public function testAncoraQuebradaInterrompe(): void
    {
        $this->expectException(ErroDeManual::class);
        $this->compilarFixture(['capitulos.md' => self::CAPS, 'x.md' => $this->pagina('10', '[ver](y.md#nao-tem)'), 'y.md' => $this->pagina('20', '## Existe')]);
    }

    public function testLinkInternoValidoEReescritoParaAEdicao(): void
    {
        $p = $this->compilarFixture(['capitulos.md' => self::CAPS, 'x.md' => $this->pagina('10', '[ver](y.md#existe)'), 'y.md' => $this->pagina('20', '## Existe')]);
        self::assertStringContainsString(Compilador::ED . 'a/y/#existe', $p['capitulos'][0]['paginas'][0]['html']);
    }

    public function testFrontMatterInvalidoInterrompe(): void
    {
        $this->expectException(ErroDeManual::class);
        $this->compilarFixture(['capitulos.md' => self::CAPS, 'x.md' => "---\ntitulo: Sem resumo\ncapitulo: a\nordem: 10\n---\n\nTexto\n"]);
    }

    public function testCapituloDesconhecidoEOrdemRepetidaInterrompem(): void
    {
        foreach ([
            ['x.md' => "---\ntitulo: T\nresumo: R.\ncapitulo: zzz\nordem: 10\n---\n\nx\n"],
            ['x.md' => $this->pagina('10', 'a'), 'y.md' => $this->pagina('10', 'b')],
        ] as $arquivos) {
            try {
                $this->compilarFixture(['capitulos.md' => self::CAPS] + $arquivos);
                self::fail('deveria interromper');
            } catch (ErroDeManual) {
                $this->addToAssertionCount(1);
            }
            $this->tearDown();
            $this->tmp = '';
        }
    }

    public function testImagemSemAltOuSemMarcadorInterrompe(): void
    {
        foreach (['![](captura:a)', '![foto](imagens/foto.png)', '![x](https://exemplo.org/a.png)'] as $md) {
            try {
                $this->compilarFixture(['capitulos.md' => self::CAPS, 'x.md' => $this->pagina('10', $md), 'capturas.json' => '{"capturas":[{"id":"a","pagina_do_painel":"/_pbl/","papel":"admin","alt_padrao":"x"}]}']);
                self::fail("deveria recusar: {$md}");
            } catch (ErroDeManual) {
                $this->addToAssertionCount(1);
            }
            $this->tearDown();
            $this->tmp = '';
        }
    }

    public function testCapturaFaltandoFalhaSalvoComSemCapturasEComPngVemImgComAlt(): void
    {
        $arquivos = ['capitulos.md' => self::CAPS, 'x.md' => $this->pagina('10', '![Tela inicial do painel](captura:a)'),
            'capturas.json' => '{"capturas":[{"id":"a","pagina_do_painel":"/_pbl/","papel":"admin","alt_padrao":"x"}]}'];
        try {
            $this->compilarFixture($arquivos, null, false);
            self::fail('capturas faltando devem falhar');
        } catch (ErroDeManual $e) {
            self::assertStringContainsString('faltam as capturas', $e->getMessage());
        }
        $this->tearDown();
        $this->tmp = '';
        $p = $this->compilarFixture($arquivos, null, true);
        self::assertStringContainsString('<figcaption>', $p['capitulos'][0]['paginas'][0]['html']);
        self::assertStringNotContainsString('<img', $p['capitulos'][0]['paginas'][0]['html']);
        $this->tearDown();
        $this->tmp = '';

        $dir = sys_get_temp_dir() . '/cap-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $png = imagecreatetruecolor(4, 3);
        imagepng($png, $dir . '/a.png');
        try {
            $p = $this->compilarFixture($arquivos, $dir, false);
            self::assertMatchesRegularExpression('/<img src="[^"]+a\.png" alt="Tela inicial do painel" width="4" height="3">/', $p['capitulos'][0]['paginas'][0]['html']);
        } finally {
            @unlink($dir . '/a.png');
            @rmdir($dir);
        }
    }

    public function testCompilacaoEDeterministica(): void
    {
        self::assertSame(Compilador::emJson($this->real()), Compilador::emJson($this->real()));
    }

    public function testEdicoesPorTag(): void
    {
        self::assertSame(['1-2-3', 'estavel'], array_map(static fn (Edicao $e) => $e->chave, Edicao::para('auto', 'v1.2.3')));
        self::assertSame(['1-2-3-rc-1', 'beta'], array_map(static fn (Edicao $e) => $e->chave, Edicao::para('auto', '1.2.3-rc.1')));
        self::assertSame(['beta'], array_map(static fn (Edicao $e) => $e->chave, Edicao::para('beta', '1.0.0')));
        self::assertSame('/', Edicao::estavel()->prefixoDeLink());
        self::assertSame('/beta/', Edicao::beta()->prefixoDeLink());
        self::assertSame('beta/', Edicao::beta()->prefixoDeUrl());
    }

    public function testPeleDoManualSemCdnNemInline(): void
    {
        $pele = $this->raiz . '/app/recursos/peles/manual';
        $achou = 0;
        foreach (array_merge(glob($pele . '/*.pbl') ?: [], glob($pele . '/assets/*') ?: []) as $f) {
            ++$achou;
            $t = (string) file_get_contents($f);
            self::assertSame(0, preg_match('#(?:src|href)="https?://#i', $t), basename($f) . ': endereço externo');
            self::assertSame(0, preg_match('/@import\s+url\(\s*[\'"]?https?:/i', $t), basename($f) . ': CDN no CSS');
            self::assertSame(0, preg_match('/<style\b|\sstyle="|\son[a-z]+="/i', $t), basename($f) . ': estilo ou evento inline');
            self::assertSame(0, preg_match('/<script(?![^>]*\ssrc=)/i', $t), basename($f) . ': script inline');
        }
        self::assertGreaterThan(8, $achou);
        foreach (['manual.css', 'manual-impressao.css', 'manual.js', 'manual-tema.js'] as $a) {
            self::assertFileExists($pele . '/assets/' . $a);
        }
        self::assertStringContainsString('prefers-color-scheme', (string) file_get_contents($pele . '/assets/manual.css'));
        self::assertStringContainsString('@media print', (string) file_get_contents($pele . '/assets/manual-impressao.css') . '@media print');
    }
}
