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
            self::assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)(?![^>]*type="application/ld\+json")#i', $t, $nome);
            self::assertDoesNotMatchRegularExpression('#(?:src|href)\s*=\s*"https?://#i', $t, $nome);
            self::assertDoesNotMatchRegularExpression('#@import|url\(\s*[\'"]?(?:https?:)?//#i', $t, $nome);
            self::assertStringNotContainsString('javascript:', $t, $nome);
        }
        // Os únicos scripts permitidos: a barra de edição do PBL4 e o tema.js da própria pele (locais, versionados, sem inline).
        foreach ($this->moldes() as $m) {
            preg_match_all('#<script[^>]*\ssrc="([^"]+)"#', (string) file_get_contents($m), $achados);
            foreach ($achados[1] as $src) {
                self::assertMatchesRegularExpression('#^(/_pbl/assets/pbl-barra\.js\?v=|<@eco \#pele_url@>/assets/tema\.js\?v=)#', $src, basename($m));
            }
        }
        self::assertSame(['tema.js'], array_map('basename', glob($this->pasta() . '/assets/*.js') ?: []), 'o único JavaScript da pele é o do tema');
        $js = (string) file_get_contents($this->pasta() . '/assets/tema.js');
        self::assertDoesNotMatchRegularExpression('#\b(eval|innerHTML|document\.write|XMLHttpRequest|fetch)\b|https?://#', $js, 'o tema.js só guarda a escolha, sem rede nem HTML dinâmico');
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
            ['--cor-hero-sobre-botao', '--cor-hero-botao', $texto], ['--cor-hero-botao', '--cor-hero-fundo', $componente], ['--cor-hero-botao', '--cor-hero-fundo-2', $componente],
            ['--cor-hero-borda', '--cor-hero-fundo', $componente], ['--cor-hero-borda', '--cor-hero-fundo-2', $componente],
            ['--cor-hero-foco', '--cor-hero-fundo', $componente], ['--cor-hero-foco', '--cor-hero-fundo-2', $componente],
            ['--cor-acento', '--cor-rodape-fundo', $componente],
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

    /** Identidade: azul-marinho como cor forte, verde só em detalhes, nenhum laranja nem verde-azulado, nos dois temas. */
    #[\PHPUnit\Framework\Attributes\DataProvider('temas')]
    public function testAzulComoCorForteSemLaranjaNemVerdeAzulado(string $tema): void
    {
        $cores = $this->variaveis($tema);
        foreach ($cores as $nome => $hex) {
            [$h, $s] = self::matizESaturacao($hex);
            self::assertFalse($s >= 0.12 && $h >= 168 && $h <= 205, "{$tema}: {$nome} {$hex} é verde-azulado (matiz {$h}°)");
            self::assertFalse($s >= 0.5 && $h >= 12 && $h <= 38, "{$tema}: {$nome} {$hex} é laranja (a pele agora é azul; o dourado do foco fica acima de 38°)");
        }
        foreach (['--cor-primaria', '--cor-link', '--cor-hero-fundo', '--cor-hero-fundo-2', '--cor-rodape-fundo'] as $nome) {
            [$h, $s] = self::matizESaturacao($cores[$nome]);
            self::assertTrue($h >= 205 && $h <= 235 && $s >= 0.3, "{$tema}: {$nome} {$cores[$nome]} deveria ser azul");
        }
        [$h] = self::matizESaturacao($cores['--cor-acento']);
        self::assertTrue($h >= 120 && $h <= 165, "{$tema}: o acento é um verde discreto");
        foreach (['--cor-fundo', '--cor-fundo-suave', '--cor-cartao', '--cor-texto'] as $nome) {
            [$h, $s] = self::matizESaturacao($cores[$nome]);
            self::assertTrue($s < 0.25 || ($h >= 205 && $h <= 235), "{$tema}: neutro {$nome} {$cores[$nome]} tem de ser cinza ou azul-acinzentado");
        }
    }

    /** O tema escolhido na página (data-tema) tem de dar as mesmas cores do escuro automático. */
    public function testEscuroEscolhidoIgualAoEscuroAutomatico(): void
    {
        $css = $this->css();
        $auto = $this->lerCores($this->bloco('#@media \(prefers-color-scheme:dark\)\{\s*:root:not\(\[data-tema="claro"\]\)\{(.*?)\}#s', $css));
        $escolhido = $this->lerCores($this->bloco('#:root\[data-tema="escuro"\]\{(.*?)\}#s', $css));
        self::assertNotSame([], $auto);
        self::assertSame($auto, $escolhido);
    }

    /** @return array<string, array{string}> */
    public static function temas(): array
    {
        return ['claro' => ['claro'], 'escuro' => ['escuro']];
    }

    /** @return array{float, float} matiz (graus) e saturação HSL */
    private static function matizESaturacao(string $hex): array
    {
        [$r, $g, $b] = array_map(static fn (string $c): float => hexdec($c) / 255, str_split(ltrim($hex, '#'), 2));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $d = $max - $min;
        if ($d == 0.0) {
            return [0.0, 0.0];
        }
        $l = ($max + $min) / 2;
        $s = $d / (1 - abs(2 * $l - 1));
        $h = match ($max) {
            $r => fmod(($g - $b) / $d, 6),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };

        return [fmod($h * 60 + 360, 360), $s];
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
            $cores = $this->lerCores($this->bloco('#@media \(prefers-color-scheme:dark\)\{\s*:root:not\(\[data-tema="claro"\]\)\{(.*?)\}#s', $css)) + $cores;
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

    /** Quem instalou a versão anterior (laranja) recebe a nova na atualização: os hashes antigos têm de estar em anteriores.json. */
    public function testVersaoLaranjaDosArquivosAlteradosEstaRegistradaEmAnteriores(): void
    {
        $json = json_decode((string) file_get_contents(dirname(__DIR__) . '/app/recursos/peles/anteriores.json'), true);
        self::assertIsArray($json);
        foreach (['assets/pele.css', 'estilo.pbl', 'topo.pbl', 'assets/marca.svg', 'assets/og-publicare.png'] as $arquivo) {
            self::assertNotSame([], $json['publicare/' . $arquivo] ?? [], "publicare/{$arquivo}: falta o hash da versão anterior");
            self::assertNotContains(hash_file('sha256', $this->pasta() . '/' . $arquivo), $json['publicare/' . $arquivo], "publicare/{$arquivo}: a versão atual não pode estar entre as antigas");
        }
    }
}
