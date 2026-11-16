<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Caminhos;
use PHPUnit\Framework\TestCase;

final class CaminhosTest extends TestCase
{
    public function testSeparaCodigoSubstituivelDeDados(): void
    {
        $c = new Caminhos('/srv/site/');

        self::assertSame('/srv/site/app', $c->app());
        self::assertSame('/srv/site/dados', $c->dados());
        foreach ([$c->config(), $c->cache(), $c->uploads(), $c->peles(), $c->banco()] as $caminho) {
            self::assertStringStartsWith($c->dados() . '/', $caminho);
        }
    }

    public function testGarantirDadosCriaPastasSoEmDados(): void
    {
        $raiz = $this->raizTemporaria();
        try {
            $c = new Caminhos($raiz);

            $c->garantirDados();

            self::assertDirectoryExists($c->cache());
            self::assertDirectoryExists($c->uploads());
            self::assertDirectoryDoesNotExist($c->app());
        } finally {
            Ajuda::apagar($raiz);
        }
    }

    public function testGarantirDadosProtegeADadosComHtaccess(): void
    {
        $raiz = $this->raizTemporaria();
        try {
            $c = new Caminhos($raiz);

            $c->garantirDados();

            $arquivo = $c->dados() . '/.htaccess';
            self::assertFileExists($arquivo);
            self::assertStringContainsString('Require all denied', (string) file_get_contents($arquivo));

            file_put_contents($arquivo, "# ajustado pelo dono\n");
            $c->garantirDados();
            self::assertSame("# ajustado pelo dono\n", file_get_contents($arquivo), 'não sobrescreve');
        } finally {
            Ajuda::apagar($raiz);
        }
    }

    public function testGarantirDadosCriaPastasSemPermissaoParaOutros(): void
    {
        $raiz = $this->raizTemporaria();
        $umask = umask(0);
        try {
            $c = new Caminhos($raiz);

            $c->garantirDados();

            foreach ([$c->config(), $c->cache(), $c->uploads(), $c->peles()] as $pasta) {
                self::assertSame(0750, fileperms($pasta) & 0777, $pasta);
            }
        } finally {
            umask($umask);
            Ajuda::apagar($raiz);
        }
    }

    private function raizTemporaria(): string
    {
        $raiz = sys_get_temp_dir() . '/pbl4-' . bin2hex(random_bytes(4));
        mkdir($raiz);

        return $raiz;
    }

    /** @return iterable<string, array{string, bool}> */
    public static function urls(): iterable
    {
        yield 'raiz' => ['', true];
        yield 'simples' => ['sobre', true];
        yield 'aninhada' => ['alfa/um/fundo', true];
        yield 'hífen no meio' => ['a-b-c', true];
        yield 'hífen duplo' => ['a--b', true];
        yield 'número' => ['2026/11/01-edital', true];
        yield 'index é reservada (página inicial no cache)' => ['index', false];
        yield 'index dentro de pasta pode' => ['guia/index', true];
        yield 'hífen no começo' => ['-a', false];
        yield 'hífen no fim' => ['a-', false];
        yield 'barra no começo' => ['/a', false];
        yield 'barra no fim' => ['a/', false];
        yield 'barra dupla' => ['a//b', false];
        yield 'maiúscula' => ['A', false];
        yield 'ponto' => ['a.html', false];
        yield 'traversal' => ['../x', false];
        yield 'nova linha no fim' => ["a\n", false];
        yield 'espaço' => ['a b', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('urls')]
    public function testUrlValida(string $url, bool $esperado): void
    {
        self::assertSame($esperado, Caminhos::urlValida($url));
    }

    public function testUrlsHostisNaoEstouramOLimiteDoPcreNemDemoram(): void
    {
        $hostis = [
            implode('/', array_fill(0, 3, str_repeat('a', 30))) . '!',
            implode('/', array_fill(0, 10, str_repeat('a', 30))) . '!',
            implode('/', array_fill(0, 14, str_repeat('a', 20))) . '!',
            str_repeat('a-', 140) . '!',
            str_repeat('a', 5000) . '!',
            str_repeat('a/', 5000),
        ];
        $inicio = hrtime(true);
        foreach ($hostis as $url) {
            self::assertFalse(Caminhos::urlValida($url));
            if (strlen($url) <= Caminhos::URL_TAMANHO_MAXIMO) {
                preg_match(Caminhos::URL_VALIDA, $url);
                self::assertSame(PREG_NO_ERROR, preg_last_error(), 'sem "Backtrack limit exhausted": ' . substr($url, 0, 40));
            }
        }
        self::assertLessThan(50, (hrtime(true) - $inicio) / 1_000_000, 'todas juntas em menos de 50 ms');
    }
}
