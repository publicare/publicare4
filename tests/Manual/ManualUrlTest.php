<?php

declare(strict_types=1);

namespace Pbl\Testes\Manual;

use Pbl\Admin\Pagina;
use Pbl\Conteudo\ConfigDoSite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ADR 0044: endereço do manual (`manual_url`) e o link "Ajuda (manual)" do painel. */
final class ManualUrlTest extends TestCase
{
    public function testPadraoEhOEnderecoPublico(): void
    {
        self::assertSame('https://manual.publicare.org/', ConfigDoSite::padrao('manual_url'));
        self::assertNull(ConfigDoSite::normalizar('manual_url', '  '));
    }

    public function testAceitaHttpsEHttpLocal(): void
    {
        self::assertSame('https://ajuda.exemplo.org/manual/', ConfigDoSite::normalizar('manual_url', ' https://ajuda.exemplo.org/manual/ '));
        self::assertSame('http://localhost:8080/', ConfigDoSite::normalizar('manual_url', 'http://localhost:8080/'));
        self::assertSame('http://127.0.0.1/', ConfigDoSite::normalizar('manual_url', 'http://127.0.0.1/'));
    }

    /** @return list<array{string}> */
    public static function invalidas(): array
    {
        return [
            ['javascript:alert(1)//'], ['data:text/html,x/'], ['http://exemplo.org/'], ['https://u:p@exemplo.org/'],
            ['//exemplo.org/'], ['https://exemplo.org/a b/'], ['https://exemplo.org'], ['https:///'], ['https://' . str_repeat('a', 200) . '.org/'],
        ];
    }

    #[DataProvider('invalidas')]
    public function testRejeita(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConfigDoSite::normalizar('manual_url', $url);
    }

    public function testLinkTemPadraoEscapaValorEAbreEmNovaAba(): void
    {
        Pagina::definirMenu(false, null);
        $html = Pagina::linkDoManual();
        self::assertStringContainsString('href="https://manual.publicare.org/"', $html);
        self::assertStringContainsString('target="_blank" rel="noopener noreferrer"', $html);
        self::assertStringContainsString('abre em nova aba', $html);
        Pagina::definirManual('https://a.exemplo.org/?x=1&y=2/');
        self::assertStringContainsString('href="https://a.exemplo.org/?x=1&amp;y=2/"', Pagina::linkDoManual());
        Pagina::definirManual('');
        self::assertStringContainsString('manual.publicare.org', Pagina::linkDoManual());
    }

    public function testBarraDoPainelTrazOLink(): void
    {
        Pagina::definirMenu(false, null);
        self::assertStringContainsString('Ajuda (manual)', Pagina::barra('Ana', 'tok'));
    }
}
