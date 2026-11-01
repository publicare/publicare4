<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Linguagem\Compilador;
use Pbl\Linguagem\ErroSintaxe;
use Pbl\Linguagem\Runtime;
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

    /** @param array<string, mixed> $contexto */
    private function render(string $molde, array $contexto = []): string
    {
        return ($this->compilador()->carregar($molde))($contexto, new Runtime());
    }

    private function compilador(): Compilador
    {
        return new Compilador($this->cache);
    }

    public function testEcoEscapaPorPadrao(): void
    {
        self::assertSame('&lt;b&gt;&quot;x&quot;&lt;/b&gt;', $this->render('<@eco t@>', ['t' => '<b>"x"</b>']));
    }

    public function testEcocruNaoEscapa(): void
    {
        self::assertSame('<b>ok</b>', $this->render('<@ecocru t@>', ['t' => '<b>ok</b>']));
    }

    public function testSeESenao(): void
    {
        $m = '<@se a@>sim<@senao@>nao<@/se@>';
        self::assertSame('sim', $this->render($m, ['a' => 'x']));
        self::assertSame('nao', $this->render($m, ['a' => '']));
        self::assertSame('nao', $this->render($m, []));
    }

    public function testRepetirUsaContextoDoItem(): void
    {
        $m = '<@repetir filhos@>[<@eco n@>]<@/repetir@>';
        $ctx = ['n' => 'pai', 'filhos' => [['n' => 'a'], ['n' => 'b']]];

        self::assertSame('[a][b]', $this->render($m, $ctx));
    }

    public function testRepetirAninhadoEIsoladoDoPai(): void
    {
        $m = '<@repetir filhos@><@repetir filhos@>(<@eco n@>)<@/repetir@><@/repetir@>';
        $ctx = ['filhos' => [['filhos' => [['n' => '1'], ['n' => '2']]]]];

        self::assertSame('(1)(2)', $this->render($m, $ctx));
    }

    public function testMoldeTextoComAspasEBarrasNaoViraCodigo(): void
    {
        $m = "it's \\ \\' \$x {\$y} \"z\"";
        self::assertSame($m, $this->render($m));
    }

    public function testPhpCruERejeitadoComLinha(): void
    {
        try {
            $this->compilador()->compilar("linha 1\n<?php echo 1; ?>");
            self::fail('Deveria rejeitar PHP cru');
        } catch (ErroSintaxe $e) {
            self::assertSame(2, $e->linha);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function moldesInvalidos(): iterable
    {
        yield 'comando desconhecido' => ['<@exec ls@>'];
        yield 'se sem fechar' => ['<@se a@>x'];
        yield 'fechamento sem abertura' => ['<@/se@>'];
        yield 'fechamento cruzado' => ['<@se a@><@repetir filhos@><@/se@><@/repetir@>'];
        yield 'senao solto' => ['<@senao@>'];
        yield 'eco sem campo' => ['<@eco@>'];
        yield 'repetir de fonte desconhecida' => ['<@repetir usuarios@><@/repetir@>'];
        yield 'tag malformada' => ['<@eco titulo'];
        yield 'campo com caracteres perigosos' => ['<@eco a.b@>'];
    }

    /** @dataProvider moldesInvalidos */
    #[\PHPUnit\Framework\Attributes\DataProvider('moldesInvalidos')]
    public function testMoldesInvalidosViramErroNuncaExit(string $molde): void
    {
        $this->expectException(ErroSintaxe::class);
        $this->compilador()->compilar($molde);
    }

    public function testCompilaUmaVezEReaproveitaOArquivo(): void
    {
        $this->render('<@eco a@>', ['a' => '1']);
        $this->render('<@eco a@>', ['a' => '2']);

        self::assertCount(1, glob($this->cache . '/*.php') ?: []);
    }
}
