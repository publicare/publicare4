<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Linguagem\Compilador;
use Pbl\Linguagem\ErroSintaxe;
use Pbl\Linguagem\FonteDeDados;
use Pbl\Linguagem\Resolvedor;
use Pbl\Linguagem\Runtime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinguagemTest extends TestCase
{
    private string $cache;

    protected function setUp(): void
    {
        $this->cache = Ajuda::pastaTemporaria();
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->cache);
    }

    /**
     * @param array<string, mixed> $objeto
     * @param array<string, string> $moldes outros moldes, por nome (layouts e incluir)
     * @param list<array<string, mixed>> $filhos
     */
    private function render(string $molde, array $objeto = [], array $moldes = [], array $filhos = [], ?\DateTimeImmutable $agora = null): string
    {
        $resolvedor = new class ($moldes) implements Resolvedor {
            /** @param array<string, string> $moldes */
            public function __construct(private readonly array $moldes)
            {
            }

            public function fonte(string $nome): string
            {
                return $this->moldes[$nome] ?? throw new \RuntimeException("sem molde {$nome}");
            }
        };
        $fonte = new class ($filhos) implements FonteDeDados {
            /** @param list<array<string, mixed>> $filhos */
            public function __construct(private readonly array $filhos)
            {
            }

            public function filhos(array $objeto, array $filtros): array
            {
                return $this->filtrar($filtros);
            }

            public function localizar(array $objeto, array $filtros): array
            {
                return $this->filtrar($filtros);
            }

            public function objeto(array $objeto, string $id, string $url, string $titulo): ?array
            {
                foreach ($this->filhos as $f) {
                    if (($id !== '' && (string) ($f['id'] ?? '') === $id) || ($titulo !== '' && ($f['titulo'] ?? '') === $titulo) || ($url !== '' && ($f['url'] ?? '') === $url)) {
                        return $f;
                    }
                }

                return null;
            }

            public function temFilhos(array $objeto, ?int $id): bool
            {
                return $this->filhos !== [] && ($id === null || $id > 0);
            }

            /**
             * @param array<string, int|string> $filtros
             * @return list<array<string, mixed>>
             */
            private function filtrar(array $filtros): array
            {
                $lista = $this->filhos;
                if (isset($filtros['classes'])) {
                    $aceitas = explode(',', (string) $filtros['classes']);
                    $lista = array_values(array_filter($lista, static fn (array $f): bool => in_array($f['classe'] ?? '', $aceitas, true)));
                }

                return array_slice($lista, (int) ($filtros['inicio'] ?? 0), (int) ($filtros['limite'] ?? 100));
            }
        };
        $compilador = new Compilador($this->cache);
        $incluidor = fn (string $nome, array $o, Runtime $r): string => ($compilador->carregar($resolvedor->fonte($nome), $resolvedor))($o, $r);

        return ($compilador->carregar($molde, $resolvedor))($objeto, new Runtime($fonte, $incluidor, $agora));
    }

    public function testEcoEscapaPorPadraoEEcocruNao(): void
    {
        $o = ['t' => '<b>"x"</b>'];

        self::assertSame('&lt;b&gt;&quot;x&quot;&lt;/b&gt;', $this->render('<@eco #t@>', $o));
        self::assertSame('<b>"x"</b>', $this->render('<@ecocru #t@>', $o));
        self::assertSame('&lt;b&gt;&quot;x&quot;&lt;/b&gt;', $this->render('<@= #t@>', $o));
    }

    public function testExpressoesLiteraisConcatenacaoEAritmetica(): void
    {
        self::assertSame('olá, Ana!', $this->render('<@eco "olá, " . #n . "!"@>', ['n' => 'Ana']));
        self::assertSame('14', $this->render('<@eco 2 + 3 * 4@>'));
        self::assertSame('20', $this->render('<@eco (2 + 3) * 4@>'));
        self::assertSame('1.5', $this->render('<@eco 3 / 2@>'));
        self::assertSame('', $this->render('<@eco 1 / 0@>'), 'divisão por zero não derruba a página');
        self::assertSame('-3', $this->render('<@eco -3@>'));
        self::assertSame('a"b', $this->render('<@ecocru "a\"b"@>'));
        self::assertSame('x@>y', $this->render('<@ecocru "x@>y"@>'), 'a tag só termina em @> fora de aspas');
    }

    public function testVariaveisSaoDoMoldeENaoGlobaisDoPhp(): void
    {
        $_GET['x'] = 'perigo';
        self::assertSame('5', $this->render('<@var $x = 2 + 3@><@eco $x@>'));
        self::assertSame('', $this->render('<@eco $x@>'), 'variável não definida é vazia');
        unset($_GET['x']);
    }

    public function testSeComparacoesETruthiness(): void
    {
        self::assertSame('s', $this->render('<@se [#n == 10]@>s<@senao@>n<@/se@>', ['n' => '10']));
        self::assertSame('s', $this->render('<@se #n >= 2@>s<@senao@>n<@/se@>', ['n' => 10]), 'compara como número, não texto');
        self::assertSame('n', $this->render('<@se #n != "a"@>n<@senao@>s<@/se@>', ['n' => 'b']));
        self::assertSame('s', $this->render('<@se #a@>s<@senao@>n<@/se@>', ['a' => 'x']));
        self::assertSame('n', $this->render('<@se #a@>s<@senao@>n<@/se@>', ['a' => '']));
        self::assertSame('n', $this->render('<@se #a@>s<@senao@>n<@/se@>', ['a' => '0']));
        self::assertSame('n', $this->render('<@se #ausente@>s<@senao@>n<@/se@>'));
    }

    public function testRepetirNumericoComVariavel(): void
    {
        self::assertSame('[1][2][3]', $this->render('<@repetir $i=[1,3]@>[<@eco $i@>]<@/repetir@>'));
        self::assertSame('', $this->render('<@repetir $i=[5,1]@>x<@/repetir@>'));
    }

    public function testRepetirTemTetoDeIteracoes(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->render('<@repetir $i=[1,999999999]@>x<@/repetir@>');
    }

    public function testFilhosMudamOContextoEMacrosDeLaco(): void
    {
        $filhos = [['titulo' => 'A', 'classe' => 'pagina'], ['titulo' => 'B', 'classe' => 'noticia'], ['titulo' => 'C', 'classe' => 'pagina']];
        $m = '<@filhos@><@eco %INDICE@>/<@eco %FIM@>:<@eco #titulo@>;<@/filhos@>';

        self::assertSame('1/3:A;2/3:B;3/3:C;', $this->render($m, ['titulo' => 'pai'], [], $filhos));
        self::assertSame('1/2:A;2/2:C;', $this->render('<@filhos classes="pagina"@><@eco %INDICE@>/<@eco %FIM@>:<@eco #titulo@>;<@/filhos@>', [], [], $filhos));
        self::assertSame('B;', $this->render('<@filhos inicio=1 limite=1@><@eco #titulo@>;<@/filhos@>', [], [], $filhos));
        self::assertSame('pai', $this->render('<@filhos@><@/filhos@><@eco #titulo@>', ['titulo' => 'pai'], [], $filhos), 'fora do laço volta ao pai');
    }

    public function testFilhosAninhadosIsolamOContexto(): void
    {
        $m = '<@filhos@>(<@eco #titulo@><@filhos@>[<@eco #titulo@>]<@/filhos@>)<@/filhos@>';

        self::assertSame('(A[A])', $this->render($m, [], [], [['titulo' => 'A']]));
    }

    public function testSemfilhosSoApareceQuandoOLacoNaoAchouNada(): void
    {
        $m = '<@filhos@>x<@/filhos@><@semfilhos@>nenhum<@/semfilhos@>';

        self::assertSame('nenhum', $this->render($m));
        self::assertSame('x', $this->render($m, [], [], [['titulo' => 'A']]));
    }

    public function testLocalizarAleatorioEUsarObjetoETemFilho(): void
    {
        $itens = [['id' => 7, 'titulo' => 'Sete', 'url' => '/sete', 'classe' => 'pagina'], ['id' => 8, 'titulo' => 'Oito', 'url' => '/oito', 'classe' => 'noticia']];

        self::assertSame('Oito;', $this->render('<@localizar classes="noticia" busca="oit"@><@eco #titulo@>;<@/localizar@>', [], [], $itens));
        self::assertSame('2:2:', $this->render('<@localizar@><@eco %FIM@>:<@/localizar@>', [], [], $itens));

        $ordenado = $this->render('<@aleatorio@><@eco #id@>,<@/aleatorio@>', [], [], $itens);
        self::assertContains($ordenado, ['7,8,', '8,7,'], 'aleatorio devolve todos, em qualquer ordem');
        self::assertContains($this->render('<@localizaraleatorio@><@eco #id@>,<@/localizaraleatorio@>', [], [], $itens), ['7,8,', '8,7,']);

        self::assertSame('Sete|', $this->render('<@usarobjeto id=7@><@eco #titulo@>|<@/usarobjeto@>', ['titulo' => 'pai'], [], $itens));
        self::assertSame('Oito|pai', $this->render('<@usarobjeto titulo="Oito"@><@eco #titulo@>|<@/usarobjeto@><@eco #titulo@>', ['titulo' => 'pai'], [], $itens));
        self::assertSame('', $this->render('<@usarobjeto id=99@>nunca<@/usarobjeto@>', [], [], $itens), 'objeto inexistente: bloco não roda');

        self::assertSame('tem', $this->render('<@temfilho@>tem<@/temfilho@>', ['id' => 1], [], $itens));
        self::assertSame('', $this->render('<@temfilho@>tem<@/temfilho@>', ['id' => 1]));
    }

    public function testNaolocalizadoEquivaleASemfilhos(): void
    {
        self::assertSame('vazio', $this->render('<@localizar classes="x"@>a<@/localizar@><@naolocalizado@>vazio<@/naolocalizado@>'));
    }

    public function testObjetoNoLacoDeLocalizarMudaOContexto(): void
    {
        $itens = [['id' => 1, 'titulo' => 'A', 'url' => '/a', 'classe' => 'p']];
        $m = '<@localizar@><@usarobjeto id=1@>[<@eco #titulo@>]<@/usarobjeto@><@/localizar@>';

        self::assertSame('[A]', $this->render($m, [], [], $itens));
    }

    public function testComentariosNaoGeramSaidaEPodemTerAspasESimbolos(): void
    {
        self::assertSame('ab', $this->render("a<@-- REVISAR: não portado: usarblob nome=[\"x\"] it's @ > --@>b"));
        self::assertSame("a\nb", $this->render("a\n<@-- c --@>b"));
    }

    public function testMacrosDeData(): void
    {
        $agora = new \DateTimeImmutable('2026-11-01 10:00:00');

        self::assertSame('01/11/2026 01 11 2026', $this->render('<@eco %DATA@> <@eco %DIA@> <@eco %MES@> <@eco %ANO@>', [], [], [], $agora));
    }

    public function testFormatadataEEcoLimite(): void
    {
        self::assertSame('01/11/2026', $this->render('<@formatadata valor=#d formato="d/m/Y"@>', ['d' => '2026-11-01 08:30:00']));
        self::assertSame('2026-11-01', $this->render('<@formatadata valor=#d formato="Y-m-d"@>', ['d' => '01/11/2026']));
        self::assertSame('', $this->render('<@formatadata valor=#d formato="Y"@>', ['d' => 'lixo']));
        self::assertSame('Olá, m', $this->render('<@eco_limite texto=#t limite=6@>', ['t' => 'Olá, mundo']));
        self::assertSame('mundo', $this->render('<@eco_limite texto=#t inicio=5@>', ['t' => 'Olá, mundo']));
        self::assertSame('&lt;b', $this->render('<@eco_limite texto=#t limite=2@>', ['t' => '<b>x']), 'eco_limite também escapa');
    }

    public function testHerancaDeLayoutComBlocosEPadrao(): void
    {
        $base = '<h1><@bloco titulo@>Padrão<@/bloco@></h1><main><@bloco corpo@><@/bloco@></main>';
        $pagina = '<@estende "base"@><@bloco corpo@>Olá <@eco #n@><@/bloco@>';

        self::assertSame('<h1>Padrão</h1><main>Olá Ana</main>', $this->render($pagina, ['n' => 'Ana'], ['base' => $base]));
    }

    public function testHerancaEmMaisDeUmNivel(): void
    {
        $base = '[<@bloco a@>A0<@/bloco@>|<@bloco b@>B0<@/bloco@>]';
        $meio = '<@estende "base"@><@bloco a@>A1<@/bloco@>';
        $folha = '<@estende "meio"@><@bloco b@>B2<@/bloco@>';

        self::assertSame('[A1|B2]', $this->render($folha, [], ['base' => $base, 'meio' => $meio]));
    }

    public function testBlocoDoFilhoPodeUsarSeEFilhos(): void
    {
        $base = '<@bloco c@><@/bloco@>';
        $filha = '<@estende "base"@><@bloco c@><@se #x@><@filhos@><@eco #titulo@>,<@/filhos@><@/se@><@/bloco@>';

        self::assertSame('A,', $this->render($filha, ['x' => 1], ['base' => $base], [['titulo' => 'A']]));
    }

    public function testHerancaCiclicaEConteudoForaDeBlocoDaoErro(): void
    {
        try {
            $this->render('<@estende "a"@>', [], ['a' => '<@estende "a"@>']);
            self::fail('ciclo deveria falhar');
        } catch (ErroSintaxe) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(ErroSintaxe::class);
        $this->render('<@estende "base"@>solto', [], ['base' => 'x']);
    }

    public function testEstendeSoComoPrimeiroComando(): void
    {
        $this->expectException(ErroSintaxe::class);
        $this->render('texto <@estende "base"@>', [], ['base' => 'x']);
    }

    public function testIncluirUsaOMesmoObjetoEVariaveis(): void
    {
        $m = '<@var $v = "ok"@>[<@incluir arquivo="parte"@>]';
        $parte = '<@eco #n@>-<@eco $v@>';

        self::assertSame('[Ana-ok]', $this->render($m, ['n' => 'Ana'], ['parte' => $parte]));
    }

    public function testIncluirCiclicoTemTeto(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->render('<@incluir arquivo="a"@>', [], ['a' => '<@incluir arquivo="a"@>']);
    }

    public function testErroDentroDeLayoutIndicaOArquivo(): void
    {
        try {
            $this->render('<@estende "base"@>', [], ['base' => "ok\n<@exec@>"]);
            self::fail('deveria falhar');
        } catch (ErroSintaxe $e) {
            self::assertSame(2, $e->linha);
            self::assertSame('base', $e->arquivo);
            self::assertStringContainsString('base:', $e->getMessage());
        }
    }

    public function testMoldeTextoComAspasEBarrasNaoViraCodigo(): void
    {
        $m = "it's \\ \\' \$x {\$y} \"z\" %DIA #n";
        self::assertSame($m, $this->render($m));
    }

    public function testPhpCruERejeitadoComLinha(): void
    {
        foreach (["linha 1\n<?php echo 1; ?>", "<@eco \"?>\"@>"] as $molde) {
            try {
                $this->render($molde);
                self::fail('Deveria rejeitar PHP cru');
            } catch (ErroSintaxe $e) {
                self::assertStringContainsString('PHP cru', $e->getMessage());
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function moldesInvalidos(): iterable
    {
        yield 'comando desconhecido' => ['<@exec ls@>'];
        yield 'se sem fechar' => ['<@se #a@>x'];
        yield 'fechamento sem abertura' => ['<@/se@>'];
        yield 'fechamento cruzado' => ['<@se #a@><@filhos@><@/se@><@/filhos@>'];
        yield 'senao solto' => ['<@senao@>'];
        yield 'eco sem valor' => ['<@eco@>'];
        yield 'eco com lixo depois' => ['<@eco #a #b@>'];
        yield 'aspas não fechadas' => ['<@eco "abc@>'];
        yield 'tag não fechada' => ['<@eco #titulo'];
        yield 'macro desconhecida' => ['<@eco %SENHA@>'];
        yield 'parâmetro desconhecido' => ['<@filhos sql="x"@><@/filhos@>'];
        yield 'parâmetro obrigatório' => ['<@formatadata valor=#a@>'];
        yield 'parâmetro repetido' => ['<@filhos limite=1 limite=2@><@/filhos@>'];
        yield 'var sem atribuição' => ['<@var $a@>'];
        yield 'repetir malformado' => ['<@repetir $i=1,3@>x<@/repetir@>'];
        yield 'caractere inesperado' => ['<@eco #a ; #b@>'];
        yield 'bloco sem nome' => ['<@bloco@><@/bloco@>'];
        yield 'fechamento com argumento' => ['<@se #a@><@/se #a@>'];
        yield 'usarobjeto sem alvo' => ['<@usarobjeto@><@/usarobjeto@>'];
        yield 'localizar com parâmetro de filhos inexistente' => ['<@localizar sql="x"@><@/localizar@>'];
        yield 'filhos com parâmetro de localizar' => ['<@filhos pai=1@><@/filhos@>'];
        yield 'fechamento cruzado de coleções' => ['<@localizar@><@/filhos@>'];
    }

    #[DataProvider('moldesInvalidos')]
    public function testMoldesInvalidosViramErroNuncaExit(string $molde): void
    {
        $this->expectException(ErroSintaxe::class);
        (new Compilador($this->cache))->compilar($molde);
    }

    public function testErroTrazALinhaCorreta(): void
    {
        try {
            (new Compilador($this->cache))->compilar("a\nb\n<@eco #x@>\n\n<@exec@>");
            self::fail();
        } catch (ErroSintaxe $e) {
            self::assertSame(5, $e->linha);
        }
    }

    public function testCompilaUmaVezEReaproveitaOArquivoEMudancaDoLayoutRecompila(): void
    {
        $this->render('<@eco #a@>', ['a' => '1']);
        $this->render('<@eco #a@>', ['a' => '2']);
        self::assertCount(1, glob($this->cache . '/*.php') ?: []);

        $filha = '<@estende "base"@><@bloco c@>x<@/bloco@>';
        $this->render($filha, [], ['base' => '1<@bloco c@><@/bloco@>']);
        $saida = $this->render($filha, [], ['base' => '2<@bloco c@><@/bloco@>']);
        self::assertSame('2x', $saida);
        self::assertCount(3, glob($this->cache . '/*.php') ?: []);
    }

    public function testCodigoGeradoSoChamaORuntime(): void
    {
        $molde = '<@eco "system(\'ls\')"@><@var $a = #x . "exec"@><@filhos@><@se [#a == 1]@>y<@/se@><@/filhos@><@incluir arquivo="p"@><@repetir $i=[1,2]@><@/repetir@>';
        $php = (new Compilador($this->cache))->compilar($molde, new class implements Resolvedor {
            public function fonte(string $nome): string
            {
                return '';
            }
        });

        $tokens = token_get_all($php);
        $chamadas = [];
        foreach ($tokens as $i => $t) {
            if (!is_array($t) || $t[0] !== T_STRING) {
                continue;
            }
            $proximo = $tokens[$i + 1] ?? null;
            $anterior = $tokens[$i - 1] ?? null;
            $ehChamada = $proximo === '(' && !(is_array($anterior) && $anterior[0] === T_OBJECT_OPERATOR);
            if ($ehChamada) {
                $chamadas[] = $t[1];
            }
        }

        self::assertSame([], array_values(array_diff($chamadas, ['count', 'ob_start', 'ob_end_clean', 'ob_get_clean'])), 'só funções de buffer e count; o resto é $r->metodo()');
        self::assertSame("system(&#039;ls&#039;)", $this->render('<@eco "system(\'ls\')"@>'));
    }
}
