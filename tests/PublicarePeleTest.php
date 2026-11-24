<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Caminhos;
use Pbl\Instalacao\PelesDistribuidas;
use PHPUnit\Framework\TestCase;

/**
 * A pele `publicare` (padrão dos sites novos): compatibilidade de moldes com a básica, ausência de inline e de CDN, regras de
 * acessibilidade no CSS, contraste AA nos dois temas (calculado a partir das variáveis do topo de `pele.css`) e distribuição.
 */
final class PublicarePeleTest extends TestCase
{
    private function pasta(string $pele = 'publicare'): string
    {
        return dirname(__DIR__) . '/app/recursos/peles/' . $pele;
    }

    private function css(): string
    {
        return (string) file_get_contents($this->pasta() . '/assets/pele.css');
    }

    /** @return list<string> */
    private function moldes(): array
    {
        return array_map('strval', glob($this->pasta() . '/*.pbl') ?: []);
    }

    public function testTemOsMesmosMoldesDeCampoDaPeleBasica(): void
    {
        // Contrato de compatibilidade (trocar de pele sem perder conteúdo): todo molde de conteúdo da básica existe na nova.
        $contrato = ['padrao', 'home', 'cabeca', 'topo', 'pasta', 'noticia', 'evento', 'agenda', 'faq', 'perguntas', 'destaque', 'faixa_destaques',
            'bloco', 'bloco_aviso', 'bloco_rodape', 'busca', 'formulario', 'item', 'link_a', 'tag', 'paginacao', 'erro404', 'estilo'];
        foreach ($contrato as $molde) {
            self::assertFileExists($this->pasta() . "/{$molde}.pbl", $molde);
            self::assertFileExists($this->pasta('basica') . "/{$molde}.pbl", "{$molde} na básica");
        }
    }

    public function testMoldesECssSemInlineNemCdnNemJavaScript(): void
    {
        foreach ([...$this->moldes(), $this->pasta() . '/assets/pele.css'] as $arquivo) {
            $t = (string) file_get_contents($arquivo);
            $nome = basename($arquivo);
            self::assertDoesNotMatchRegularExpression('#<style#i', $t, $nome);
            self::assertDoesNotMatchRegularExpression('#\sstyle\s*=#i', $t, $nome);
            self::assertDoesNotMatchRegularExpression('#\son[a-z]+\s*=#i', $t, $nome);
            self::assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)#i', $t, $nome);
            self::assertDoesNotMatchRegularExpression('#(?:src|href)\s*=\s*"https?://#i', $t, $nome);
            self::assertDoesNotMatchRegularExpression('#@import|url\(\s*[\'"]?(?:https?:)?//#i', $t, $nome);
            self::assertStringNotContainsString('javascript:', $t, $nome);
        }
        // O único script permitido é o da barra de edição do próprio PBL4 (local, versionado); a pele não declara nenhum.
        foreach ($this->moldes() as $m) {
            preg_match_all('#<script[^>]*\ssrc="([^"]+)"#', (string) file_get_contents($m), $achados);
            foreach ($achados[1] as $src) {
                self::assertStringStartsWith('/_pbl/assets/pbl-barra.js?v=', $src, basename($m));
            }
        }
        self::assertSame([], glob($this->pasta() . '/assets/*.js') ?: [], 'a pele não traz JavaScript próprio');
    }

    public function testCssTemFocoMovimentoReduzidoTemaEscuroEPontosDeQuebra(): void
    {
        $css = $this->css();
        self::assertStringContainsString(':focus-visible', $css);
        self::assertStringContainsString('prefers-reduced-motion:reduce', $css);
        self::assertStringContainsString('prefers-color-scheme:dark', $css);
        self::assertStringContainsString('min-width:40rem', $css, 'tablet');
        self::assertStringContainsString('min-width:56rem', $css, 'desktop');
        self::assertStringNotContainsString('outline:none', $css);
        self::assertStringNotContainsString('outline:0;', str_replace('.cartao a:focus-visible{outline:0}', '', $css), 'foco só é removido onde o cartão desenha o próprio foco');
    }

    public function testIconesSaoSvgLocaisSemScript(): void
    {
        $svgs = glob($this->pasta() . '/assets/*.svg') ?: [];
        self::assertGreaterThanOrEqual(5, count($svgs));
        foreach ($svgs as $svg) {
            $t = (string) file_get_contents($svg);
            self::assertStringContainsString('<svg', $t, basename($svg));
            self::assertDoesNotMatchRegularExpression('#<script|\son[a-z]+=|href=["\']https?:#i', $t, basename($svg));
        }
    }

    public function testFaqUsaDetailsSemJavaScript(): void
    {
        $t = (string) file_get_contents($this->pasta() . '/perguntas.pbl');
        self::assertStringContainsString('<details', $t);
        self::assertStringContainsString('<summary>', $t);
        self::assertStringNotContainsString('data-bs-', $t);
    }

    /** @return array<string, array{string, string, float}> tema => pares (texto, fundo, mínimo) */
    public static function pares(): array
    {
        $texto = 4.5;
        $componente = 3.0;
        $pares = [
            ['--cor-texto', '--cor-fundo', $texto], ['--cor-texto', '--cor-fundo-suave', $texto], ['--cor-texto', '--cor-cartao', $texto],
            ['--cor-texto-suave', '--cor-fundo', $texto], ['--cor-texto-suave', '--cor-fundo-suave', $texto], ['--cor-texto-suave', '--cor-cartao', $texto],
            ['--cor-link', '--cor-fundo', $texto], ['--cor-link', '--cor-fundo-suave', $texto], ['--cor-link', '--cor-cartao', $texto],
            ['--cor-link-ativo', '--cor-fundo', $texto],
            ['--cor-sobre-primaria', '--cor-primaria', $texto], ['--cor-sobre-acento', '--cor-acento', $texto],
            ['--cor-hero-texto', '--cor-hero-fundo', $texto], ['--cor-hero-texto', '--cor-hero-fundo-2', $texto],
            ['--cor-hero-suave', '--cor-hero-fundo', $texto], ['--cor-hero-suave', '--cor-hero-fundo-2', $texto],
            ['--cor-aviso-texto', '--cor-aviso', $texto],
            ['--cor-rodape-texto', '--cor-rodape-fundo', $texto], ['--cor-rodape-link', '--cor-rodape-fundo', $texto],
            ['--cor-foco', '--cor-fundo', $componente], ['--cor-foco', '--cor-fundo-suave', $componente],
            ['--cor-borda-forte', '--cor-fundo', $componente], ['--cor-borda-forte', '--cor-cartao', $componente],
            ['--cor-acento', '--cor-hero-fundo', $componente], ['--cor-acento', '--cor-rodape-fundo', $componente],
        ];
        $casos = [];
        foreach (['claro', 'escuro'] as $tema) {
            foreach ($pares as [$a, $b, $min]) {
                $casos["{$tema}: {$a} sobre {$b}"] = [$tema, $a . '|' . $b, $min];
            }
        }

        return $casos;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pares')]
    public function testContrasteAaNosDoisTemas(string $tema, string $par, float $minimo): void
    {
        [$a, $b] = explode('|', $par);
        $cores = $this->variaveis($tema);
        self::assertArrayHasKey($a, $cores);
        self::assertArrayHasKey($b, $cores);
        $razao = self::razao($cores[$a], $cores[$b]);
        self::assertGreaterThanOrEqual($minimo, $razao, sprintf('%s: %s sobre %s dá %.2f:1 (mínimo %.1f:1)', $tema, $a, $b, $razao, $minimo));
    }

    private function bloco(string $padrao, string $css): string
    {
        if (preg_match($padrao, $css, $m) !== 1) {
            self::fail("bloco de variáveis não encontrado: {$padrao}");
        }

        return $m[1];
    }

    /** @return array<string, string> variável => #rrggbb; o tema escuro é o claro com as variáveis do bloco `prefers-color-scheme:dark`. */
    private function variaveis(string $tema): array
    {
        $css = $this->css();
        $cores = $this->lerCores($this->bloco('#:root\{(.*?)\}#s', $css));
        if ($tema === 'escuro') {
            $cores = $this->lerCores($this->bloco('#@media \(prefers-color-scheme:dark\)\{\s*:root\{(.*?)\}#s', $css)) + $cores;
        }

        return $cores;
    }

    /** @return array<string, string> */
    private function lerCores(string $bloco): array
    {
        preg_match_all('#(--[a-z0-9-]+):\s*(\#[0-9a-fA-F]{6})\s*;#', $bloco, $m, PREG_SET_ORDER);
        $r = [];
        foreach ($m as $x) {
            $r[$x[1]] = strtolower($x[2]);
        }

        return $r;
    }

    private static function luminancia(string $hex): float
    {
        $canais = array_map(static function (string $c): float {
            $v = hexdec($c) / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $canais[0] + 0.7152 * $canais[1] + 0.0722 * $canais[2];
    }

    private static function razao(string $a, string $b): float
    {
        [$x, $y] = [self::luminancia($a), self::luminancia($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    public function testPeleNovaEDistribuidaNaInstalacaoEFaltanteEhCopiadaNaMigracaoSemPisarNoPersonalizado(): void
    {
        $raiz = Ajuda::raizDeProjeto();
        try {
            $caminhos = new Caminhos($raiz);
            $caminhos->garantirDados();
            $peles = new PelesDistribuidas($caminhos);
            self::assertContains('publicare', $peles->nomes());
            self::assertSame('publicare', PelesDistribuidas::PADRAO);
            $peles->sincronizar();
            self::assertFileExists($caminhos->peles() . '/publicare/padrao.pbl');
            self::assertFileExists($caminhos->peles() . '/publicare/assets/pele.css');
            self::assertFileExists($caminhos->peles() . '/basica/padrao.pbl', 'a básica continua distribuída');

            // Migração: o que falta volta; o que a administração personalizou fica como está e vira aviso.
            unlink($caminhos->peles() . '/publicare/faq.pbl');
            file_put_contents($caminhos->peles() . '/publicare/rodape.pbl', 'meu rodape');
            $r = $peles->sincronizar();
            self::assertFileExists($caminhos->peles() . '/publicare/faq.pbl');
            self::assertContains('publicare/faq.pbl', $r['gravados']);
            self::assertSame('meu rodape', file_get_contents($caminhos->peles() . '/publicare/rodape.pbl'));
            self::assertNotSame([], array_filter($r['avisos'], static fn (string $a): bool => str_contains($a, 'publicare/rodape.pbl')));
        } finally {
            Ajuda::apagar($raiz);
        }
    }
}
