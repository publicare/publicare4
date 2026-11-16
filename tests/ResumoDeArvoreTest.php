<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Atualizacao\ResumoDeArvore;
use PHPUnit\Framework\TestCase;

final class ResumoDeArvoreTest extends TestCase
{
    private string $raiz;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        mkdir($this->raiz . '/app/src', 0775, true);
        file_put_contents($this->raiz . '/app/src/a.txt', 'a');
        file_put_contents($this->raiz . '/LICENSE', 'MIT');
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    public function testEDeterministicoEIndependenteDaOrdemDosItens(): void
    {
        $x = ResumoDeArvore::dosItens($this->raiz, ['app', 'LICENSE']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $x);
        self::assertSame($x, ResumoDeArvore::dosItens($this->raiz, ['LICENSE', 'app']));
    }

    public function testMudaComConteudoNomeArquivoExtraELink(): void
    {
        $base = ResumoDeArvore::dosItens($this->raiz, ['app', 'LICENSE']);
        file_put_contents($this->raiz . '/app/src/a.txt', 'b');
        $conteudo = ResumoDeArvore::dosItens($this->raiz, ['app', 'LICENSE']);
        self::assertNotSame($base, $conteudo);
        rename($this->raiz . '/app/src/a.txt', $this->raiz . '/app/src/c.txt');
        $nome = ResumoDeArvore::dosItens($this->raiz, ['app', 'LICENSE']);
        self::assertNotSame($conteudo, $nome);
        file_put_contents($this->raiz . '/app/src/extra.txt', '');
        $extra = ResumoDeArvore::dosItens($this->raiz, ['app', 'LICENSE']);
        self::assertNotSame($nome, $extra);
        symlink('/etc/passwd', $this->raiz . '/app/src/l');
        $link = ResumoDeArvore::dosItens($this->raiz, ['app', 'LICENSE']);
        self::assertNotSame($extra, $link);
        unlink($this->raiz . '/app/src/l');
        symlink('/etc/hosts', $this->raiz . '/app/src/l');
        self::assertNotSame($link, ResumoDeArvore::dosItens($this->raiz, ['app', 'LICENSE']));
    }

    public function testItemAusenteNaoEntraNoResumo(): void
    {
        self::assertSame(ResumoDeArvore::dosItens($this->raiz, ['app']), ResumoDeArvore::dosItens($this->raiz, ['app', 'vendor']));
    }
}
