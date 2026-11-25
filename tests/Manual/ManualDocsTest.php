<?php

declare(strict_types=1);

namespace Pbl\Testes\Manual;

use Pbl\Linguagem\ErroSintaxe;
use Pbl\Linguagem\Parser;
use Pbl\Manual\Compilador;
use Pbl\Manual\FrontMatter;
use PHPUnit\Framework\TestCase;

/** O manual de verdade (docs/usuario): compila, não envelhece, tem inventário completo e as capturas apontam para telas que existem. */
final class ManualDocsTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../..';

    /** @return list<string> */
    private function guias(): array
    {
        $a = glob(self::RAIZ . '/docs/usuario/*.md') ?: [];
        sort($a);

        return $a;
    }

    private function compilador(): Compilador
    {
        return new Compilador(self::RAIZ . '/docs/usuario', self::RAIZ . '/manual/links-externos.json', null, true);
    }

    public function testCompilaSemLinkQuebradoPaginaOrfaOuImagemSemAlt(): void
    {
        $p = $this->compilador()->compilar();
        $slugs = [];
        foreach ($p['capitulos'] as $c) {
            self::assertNotEmpty($c['paginas'], $c['slug'] . ' sem páginas');
            foreach ($c['paginas'] as $pg) {
                $slugs[] = basename($pg['origem']);
                self::assertSame(1, preg_match('/^(?:<ul>)/', $pg['navegacao']), 'anterior/próximo em toda página');
                self::assertDoesNotMatchRegularExpression('/<img(?![^>]*\balt="[^"]+")/', $pg['html'], $pg['origem'] . ': imagem sem alt');
            }
        }
        $publicados = array_map('basename', array_filter($this->guias(), static fn (string $f): bool => !in_array(basename($f), ['README.md', 'capitulos.md', 'INVENTARIO.md'], true)));
        sort($slugs);
        self::assertSame(array_values($publicados), $slugs, 'todo guia de manual está na árvore e nada além');
    }

    public function testNaoEnvelhece(): void
    {
        $achados = [];
        foreach ($this->guias() as $f) {
            if (basename($f) === 'INVENTARIO.md') {
                continue;
            }
            $texto = (string) file_get_contents($f);
            $texto = (string) preg_replace('/\A---\n.*?\n---\n/s', '', $texto);
            // fato histórico que não envelhece (o exemplo do Publicare cita este trecho; ver ConteudoPublicareTest)
            $texto = str_replace('nasceu no Brasil em 2003', 'nasceu no Brasil', $texto);
            foreach (preg_split('/\R/', $texto) ?: [] as $i => $linha) {
                if (preg_match('/(?<![\d.])\d+\.\d+\.\d+(?![\d.])|\b20\d\d\b|\b[Vv]ersão \d|\brc\.?\d|\bphp ?8\.\d|\bPHP 8|(?<![\d.])0\.[0-9](?![\d.])/', $linha) === 1) {
                    $achados[] = basename($f) . ':' . ($i + 1) . ': ' . trim($linha);
                }
            }
        }
        self::assertSame([], $achados, "O manual não leva datas nem números de versão (aponte para o instalador e o CHANGELOG):\n" . implode("\n", $achados));
    }

    public function testNaoHaImagemFixaNoManual(): void
    {
        $achados = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::RAIZ . '/docs/usuario', \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f instanceof \SplFileInfo && preg_match('/\.(png|jpe?g|gif|svg|webp)$/i', $f->getFilename()) === 1) {
                $achados[] = $f->getFilename();
            }
        }
        self::assertSame([], $achados, 'as capturas são geradas na geração do manual, não versionadas');
    }

    public function testOReadmeIndexaTodoGuia(): void
    {
        $readme = (string) file_get_contents(self::RAIZ . '/docs/usuario/README.md');
        foreach ($this->guias() as $f) {
            if (!in_array(basename($f), ['README.md', 'capitulos.md', 'INVENTARIO.md'], true)) {
                self::assertStringContainsString('(' . basename($f) . ')', $readme, basename($f) . ' fora do índice');
            }
        }
    }

    public function testOInventarioCobreTodaTelaEComando(): void
    {
        $inv = (string) file_get_contents(self::RAIZ . '/docs/usuario/INVENTARIO.md');
        $linhas = [];
        foreach (preg_split('/\R/', $inv) ?: [] as $l) {
            if (preg_match('/^\| ([A-Za-z0-9-]+) \| ([a-z0-9.-]+) \|(.*)\|$/', $l, $m) === 1 && $m[1] !== 'Item') {
                $linhas[$m[1]] = [$m[2], trim($m[3])];
            }
        }
        $itens = array_map(static fn (string $f): string => basename($f, '.php'), glob(self::RAIZ . '/app/src/Admin/TelasDe*.php') ?: []);
        $itens[] = 'TelasPublicasDeConta';
        foreach (glob(self::RAIZ . '/app/src/Console/*Comando.php') ?: [] as $f) {
            if (preg_match("/#\[AsCommand\(name: '([^']+)'/", (string) file_get_contents($f), $m) === 1) {
                $itens[] = $m[1];
            }
        }
        foreach ($itens as $item) {
            self::assertArrayHasKey($item, $linhas, "{$item} não está em docs/usuario/INVENTARIO.md");
            [$guia, $obs] = $linhas[$item];
            if ($guia === 'fora') {
                self::assertNotSame('', $obs, "{$item}: diga por que fica fora do manual");
                continue;
            }
            self::assertFileExists(self::RAIZ . '/docs/usuario/' . $guia, "{$item}: guia inexistente");
            if (!str_starts_with($item, 'Telas')) {
                self::assertStringContainsString($item, (string) file_get_contents(self::RAIZ . '/docs/usuario/' . $guia), "{$item}: o guia {$guia} não cita o comando");
            }
        }
        self::assertSame([], array_diff(array_keys($linhas), $itens), 'linha do inventário sem tela ou comando correspondente');
    }

    public function testAsCapturasApontamParaTelasDoPainel(): void
    {
        $capturas = json_decode((string) file_get_contents(self::RAIZ . '/docs/usuario/capturas.json'), true)['capturas'];
        $codigo = '';
        foreach (glob(self::RAIZ . '/app/src/Admin/*.php') ?: [] as $f) {
            $codigo .= file_get_contents($f);
        }
        foreach ($capturas as $c) {
            self::assertStringStartsWith('/_pbl', $c['pagina_do_painel']);
            self::assertContains($c['papel'], ['admin', 'editor', 'autor']);
            self::assertNotSame('', trim($c['alt_padrao']));
            $partes = array_values(array_filter(explode('/', substr($c['pagina_do_painel'], 5)), static fn (string $p): bool => $p !== '' && !ctype_digit($p)));
            foreach ($partes as $parte) {
                self::assertStringContainsString("/{$parte}", $codigo, "{$c['id']}: a rota /{$parte} não existe no painel");
            }
        }
    }

    /** @return array<string, array<string, mixed>> arquivo => meta validada */
    private function metas(): array
    {
        $saida = [];
        foreach ($this->guias() as $f) {
            $nome = basename($f);
            if (in_array($nome, ['README.md', 'capitulos.md', 'INVENTARIO.md'], true)) {
                continue;
            }
            $fm = FrontMatter::ler((string) file_get_contents($f), $nome);
            $saida[$nome] = FrontMatter::validarPagina($fm['meta'], $nome, basename($nome, '.md'));
        }

        return $saida;
    }

    public function testNenhumaPaginaFicaSemPublico(): void
    {
        foreach ($this->metas() as $nome => $m) {
            self::assertNotSame([], $m['publico'], "{$nome} sem público");
            self::assertSame(1, preg_match('/^publico: .+$/m', (string) file_get_contents(self::RAIZ . '/docs/usuario/' . $nome)), "{$nome}: declare publico: no front matter");
        }
    }

    public function testCadaTrilhaTemPaginaDeEntradaECadaPaginaApareceEmSuasTrilhas(): void
    {
        $pacote = $this->compilador()->compilar();
        $trilhas = array_column($pacote['trilhas'], null, 'slug');
        self::assertSame(FrontMatter::PUBLICOS, array_keys($trilhas));
        $chaves = [];
        foreach ($pacote['capitulos'] as $c) {
            foreach ($c['paginas'] as $p) {
                $chaves[$p['origem']] = $c['slug'] . '/' . $p['slug'];
            }
        }
        foreach ($trilhas as $publico => $t) {
            self::assertSame("trilhas/{$publico}", $t['entrada']);
            self::assertContains($t['entrada'], $chaves);
            self::assertNotEmpty($t['paginas'], "trilha {$publico} vazia");
        }
        foreach ($this->metas() as $nome => $m) {
            if ($m['capitulo'] === Compilador::CAPITULO_TRILHAS) {
                continue;
            }
            $chave = $chaves['docs/usuario/' . $nome];
            foreach (FrontMatter::PUBLICOS as $publico) {
                self::assertSame(in_array($publico, $m['publico'], true), in_array($chave, $trilhas[$publico]['paginas'], true), "{$nome} na trilha {$publico}");
            }
        }
    }

    public function testNenhumLinkQuebradoNasPaginasDeTodosOsPublicos(): void
    {
        // O compilador falha com link, âncora ou link para fora do manual quebrados; aqui garantimos que o texto de cada público foi convertido.
        $pacote = $this->compilador()->compilar();
        $total = 0;
        foreach ($pacote['capitulos'] as $c) {
            foreach ($c['paginas'] as $p) {
                $total++;
                self::assertStringNotContainsString('](', $p['html'], $p['origem'] . ': Markdown sem converter');
            }
        }
        self::assertCount($total, array_unique(array_map('basename', array_keys($this->metas()))));
    }

    /** O que o manual afirma sobre a linguagem é conferido contra o parser: todo bloco ```pbl precisa ser PBL4 válido. */
    public function testExemplosPbl4DoManualPassamNoParser(): void
    {
        $blocos = 0;
        foreach ($this->guias() as $f) {
            if (preg_match_all('/^```pbl\n(.*?)\n```$/ms', (string) file_get_contents($f), $m) < 1) {
                continue;
            }
            foreach ($m[1] as $fonte) {
                $blocos++;
                try {
                    (new Parser())->analisar($fonte);
                } catch (ErroSintaxe $e) {
                    self::fail(basename($f) . ': exemplo PBL4 inválido (' . $e->getMessage() . "):\n" . $fonte);
                }
            }
        }
        self::assertGreaterThan(15, $blocos);
    }

    /** Comandos do PBL3 que o PBL4 recusa não podem aparecer como exemplo de PBL4. */
    public function testComandosQueNaoExistemNoPbl4SoAparecemComoNotaDeMigracao(): void
    {
        foreach (['ecoe', 'incluimenu'] as $comando) {
            try {
                (new Parser())->analisar("<@{$comando}@>");
                self::fail("{$comando} passou a existir: atualize o capítulo da linguagem");
            } catch (ErroSintaxe) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (glob(self::RAIZ . '/docs/usuario/pbl4-*.md') ?: [] as $f) {
            preg_match_all('/^```pbl\n(.*?)\n```$/ms', (string) file_get_contents($f), $m);
            self::assertDoesNotMatchRegularExpression('/<@(ecoe|incluimenu)\b/', implode("\n", $m[1]), basename($f));
        }
    }

    public function testCadaVerbeteDoPbl3TemPaginaNoCapituloDaLinguagem(): void
    {
        $texto = '';
        foreach (glob(self::RAIZ . '/docs/usuario/pbl4-*.md') ?: [] as $f) {
            $texto .= strtolower((string) file_get_contents($f));
        }
        foreach (['aleatorio', 'eco', 'ecoe', 'eco_limite', 'filhos', 'incluir', 'incluimenu', 'localizar', 'naolocalizado', 'repetir', 'se', 'senao', 'semfilhos', 'usarobjeto', 'var'] as $verbete) {
            self::assertStringContainsString($verbete, $texto, "falta o verbete {$verbete}");
        }
    }
}
