<?php

declare(strict_types=1);

namespace Pbl\Testes\Manual;

use PHPUnit\Framework\TestCase;

/** A pele `manual`: sem CDN, sem estilo nem script embutido, com tema claro/escuro, impressão e acessibilidade básica no CSS/moldes. */
final class PeleDoManualTest extends TestCase
{
    private const PELE = __DIR__ . '/../../app/recursos/peles/manual';

    /** @return array<string, string> caminho relativo => conteúdo */
    private function arquivos(): array
    {
        $saida = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::PELE, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f instanceof \SplFileInfo && $f->isFile()) {
                $saida[substr($f->getPathname(), strlen(self::PELE) + 1)] = (string) file_get_contents($f->getPathname());
            }
        }
        ksort($saida);

        return $saida;
    }

    public function testTemOsMoldesEOsAssetsPrometidos(): void
    {
        $nomes = array_keys($this->arquivos());
        foreach (['layout.pbl', 'home.pbl', 'padrao.pbl', 'manual_edicao.pbl', 'manual_capitulo.pbl', 'manual_pagina.pbl', 'busca.pbl', 'erro404.pbl', 'topo.pbl', 'cabeca.pbl',
            'assets/manual.css', 'assets/manual.js', 'assets/manual-tema.js', 'assets/manual-impressao.css'] as $esperado) {
            self::assertContains($esperado, $nomes);
        }
    }

    public function testSemCdnNemEstiloOuScriptEmbutido(): void
    {
        foreach ($this->arquivos() as $nome => $texto) {
            if (str_ends_with($nome, '.pbl')) {
                self::assertDoesNotMatchRegularExpression('~\s(?:src|href)="(?:https?:)?//~i', $texto, "{$nome}: recurso externo");
                self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $texto, "{$nome}: atributo style");
                self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $texto, "{$nome}: manipulador inline");
                self::assertDoesNotMatchRegularExpression('/<style\b/i', $texto, "{$nome}: <style> embutido");
                self::assertDoesNotMatchRegularExpression('~<script\b(?![^>]*\ssrc=)~i', $texto, "{$nome}: <script> sem src");
                self::assertDoesNotMatchRegularExpression('/javascript:/i', $texto, "{$nome}: javascript:");
            } else {
                self::assertDoesNotMatchRegularExpression('~@import|url\(\s*[\'"]?(?:https?:)?//~i', $texto, "{$nome}: CSS busca recurso externo");
                self::assertDoesNotMatchRegularExpression('~https?://|\beval\(|document\.write|innerHTML~i', $texto, "{$nome}: URL ou API insegura");
            }
        }
    }

    public function testLandmarksBuscaEPularParaOConteudo(): void
    {
        $a = $this->arquivos();
        self::assertStringContainsString('<a class="pular" href="#conteudo">', $a['topo.pbl']);
        self::assertStringContainsString('role="search"', $a['topo.pbl']);
        self::assertStringContainsString('action="/busca" method="get"', $a['topo.pbl'], 'busca funciona sem JavaScript');
        self::assertStringContainsString('<header class="topo">', $a['topo.pbl']);
        self::assertStringContainsString('<nav class="lateral" id="lateral" aria-label="Trilhas do manual">', $a['layout.pbl']);
        self::assertStringContainsString('<main id="conteudo"', $a['layout.pbl']);
        self::assertSame(1, substr_count($a['manual_pagina.pbl'], '<h1>'));
        self::assertStringContainsString('aria-label="Anterior e próximo"', $a['manual_pagina.pbl']);
        self::assertStringContainsString('<label class="so-leitor" for="busca-q">', $a['topo.pbl']);
    }

    public function testCabecalhoLevaAsQuatroTrilhasEQuemEVoce(): void
    {
        $topo = $this->arquivos()['topo.pbl'];
        self::assertStringContainsString('aria-label="Trilhas por público"', $topo);
        foreach (['quem-e-voce', 'editor', 'administrador', 'desenvolvedor', 'contribuidor'] as $slug) {
            self::assertStringContainsString('trilhas/' . $slug . '/"', $topo);
        }
        (new \Pbl\Linguagem\Parser())->analisar($topo);
    }

    public function testTemaClaroEscuroImpressaoEFoco(): void
    {
        $a = $this->arquivos();
        $css = $a['assets/manual.css'];
        self::assertStringContainsString('prefers-color-scheme: dark', $css);
        self::assertStringContainsString('[data-tema="escuro"]', $css);
        self::assertStringContainsString('[data-tema="claro"]', $css);
        self::assertStringContainsString(':focus-visible', $css);
        self::assertStringContainsString('prefers-reduced-motion', $css);
        self::assertStringContainsString('@media print', $a['assets/manual-impressao.css']);
        self::assertStringContainsString('attr(href)', $a['assets/manual-impressao.css'], 'a impressão mostra o endereço dos links');
        self::assertStringContainsString('localStorage', $a['assets/manual.js']);
        self::assertStringContainsString('window.print', $a['assets/manual.js']);
        self::assertStringContainsString('hidden', $a['topo.pbl'], 'botões de JavaScript começam escondidos (sem JS não há botão morto)');
    }

    public function testAlvosDeToqueEContrasteDosTemas(): void
    {
        $css = $this->arquivos()['assets/manual.css'];
        self::assertMatchesRegularExpression('/min-height:\s*2\.75rem/', $css, 'alvo de toque de pelo menos 44 px');
        foreach (['claro' => ['#ffffff', '#111b2e', '#1d4a99'], 'escuro' => ['#0b1426', '#e8edf7', '#9db8e6']] as $tema => [$fundo, $texto, $link]) {
            self::assertGreaterThanOrEqual(4.5, self::contraste($fundo, $texto), "texto no tema {$tema}");
            self::assertGreaterThanOrEqual(4.5, self::contraste($fundo, $link), "link no tema {$tema}");
        }
        self::assertGreaterThanOrEqual(4.5, self::contraste('#16418a', '#ffffff'), 'texto sobre o destaque claro');
        self::assertGreaterThanOrEqual(4.5, self::contraste('#9db8e6', '#0b1426'), 'texto sobre o destaque escuro');
    }

    private static function luz(string $hex): float
    {
        $c = array_map(static function (string $p): float {
            $v = hexdec($p) / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private static function contraste(string $a, string $b): float
    {
        [$x, $y] = [self::luz($a), self::luz($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }
}
