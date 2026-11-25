<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Conteudo\HtmlSeguro;
use Pbl\Demo\ConteudoPublicare;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * O texto do site de exemplo não pode afirmar o que o produto não faz: cada afirmação de {@see ConteudoPublicare::afirmacoes()}
 * precisa estar no texto, ter fonte no repositório e apontar para algo que existe (comando de `bin/pbl`, classe, arquivo ou trecho
 * de arquivo). A verificação é de existência: a semântica da frase continua sendo revisada por pessoas, no PR.
 */
final class ConteudoPublicareTest extends TestCase
{
    private static function raiz(): string
    {
        return dirname(__DIR__);
    }

    private static function textoTodo(): string
    {
        $t = ConteudoPublicare::inicio()['corpo'];
        foreach (ConteudoPublicare::paginas() as $p) {
            $t .= "\n" . $p['corpo'];
        }
        foreach (ConteudoPublicare::perguntas() as [$p, $r]) {
            $t .= "\n" . $p . "\n" . $r;
        }
        foreach (ConteudoPublicare::guias() as $n) {
            $t .= "\n" . $n['corpo'];
        }

        return $t;
    }

    /** @return list<string> subcomandos registrados em bin/pbl (nome no atributo AsCommand) */
    private static function comandos(): array
    {
        $nomes = [];
        foreach (glob(self::raiz() . '/app/src/Console/*.php') ?: [] as $f) {
            if (preg_match("#AsCommand\\(name: '([^']+)'#", (string) file_get_contents($f), $m) === 1) {
                $nomes[] = $m[1];
            }
        }

        return $nomes;
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function afirmacoes(): array
    {
        $casos = [];
        foreach (ConteudoPublicare::afirmacoes() as $a) {
            $casos[$a['texto']] = [$a];
        }

        return $casos;
    }

    public function testListaTemNomesUnicosEMuitasAfirmacoes(): void
    {
        $lista = ConteudoPublicare::afirmacoes();
        self::assertGreaterThan(50, count($lista));
        self::assertCount(count($lista), array_unique(array_column($lista, 'texto')), 'afirmações repetidas');
    }

    /** @param array{texto: string, fonte: string, verificacao: array<string, mixed>} $a */
    #[DataProvider('afirmacoes')]
    public function testAfirmacaoEstaNoTextoTemFonteEORecursoExiste(array $a): void
    {
        self::assertStringContainsString($a['texto'], self::textoTodo(), 'a frase-chave não está no conteúdo');
        self::assertNotSame('', $a['fonte']);
        self::assertTrue(file_exists(self::raiz() . '/' . $a['fonte']) || is_dir(self::raiz() . '/' . $a['fonte']), "fonte inexistente: {$a['fonte']}");
        $v = $a['verificacao'];
        self::assertCount(1, $v, 'uma verificação por afirmação');
        if (isset($v['comando'])) {
            self::assertContains($v['comando'], self::comandos(), "bin/pbl não tem o comando {$v['comando']}");
        } elseif (isset($v['classe'])) {
            self::assertTrue(class_exists($v['classe']) || enum_exists($v['classe']), "classe inexistente: {$v['classe']}");
        } elseif (isset($v['arquivo'])) {
            self::assertFileExists(self::raiz() . '/' . $v['arquivo']);
        } elseif (isset($v['contem'])) {
            [$arquivo, $trecho] = $v['contem'];
            self::assertFileExists(self::raiz() . '/' . $arquivo);
            self::assertStringContainsString($trecho, (string) file_get_contents(self::raiz() . '/' . $arquivo), "{$arquivo} não tem o trecho esperado");
        } else {
            self::fail('verificação desconhecida');
        }
    }

    public function testComandosCitadosNoTextoEstaoRegistrados(): void
    {
        preg_match_all('#bin/pbl ([a-z][a-z0-9-]*)#', self::textoTodo(), $m);
        self::assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $comando) {
            self::assertContains($comando, self::comandos(), "o texto cita bin/pbl {$comando}, que não existe");
        }
    }

    public function testPerguntasFrequentesSaoDe8A12(): void
    {
        $n = count(ConteudoPublicare::perguntas());
        self::assertGreaterThanOrEqual(8, $n);
        self::assertLessThanOrEqual(12, $n);
        $temas = implode(' ', array_column(ConteudoPublicare::perguntas(), 0));
        foreach (['licença', 'requisitos', 'instalo', 'atualizo', 'cópia de segurança', 'Publicare 3', 'sites', 'acessível', 'seguro', 'contribuir', 'beta'] as $tema) {
            self::assertStringContainsStringIgnoringCase($tema, $temas, "falta pergunta sobre {$tema}");
        }
    }

    public function testNaoPrometeNumerosNemComparacoesNemUsoFicticio(): void
    {
        $t = self::textoTodo();
        foreach (['1.200', '1200 pessoas', 'milhares de', 'mais rápido que', 'melhor que', 'o melhor', 'garantimos', '100%', 'sem falhas'] as $proibido) {
            self::assertStringNotContainsStringIgnoringCase($proibido, $t, $proibido);
        }
        self::assertStringNotContainsString('rc2', $t);
    }

    /** Regra do dono: o exemplo não tem dado que envelheça (versão, data, contagem, estado transitório). */
    public function testNaoTemDadoQueEnvelheca(): void
    {
        $t = str_replace('Apache-2.0', 'Apache', strip_tags(self::textoTodo()));
        self::assertDoesNotMatchRegularExpression('#\b\d+\.\d+(\.\d+)?(-[a-z]+(\.\d+)?)?\b#i', $t, 'número de versão (do PHP, do programa ou de pré-release)');
        self::assertDoesNotMatchRegularExpression('#\brc\.?[ \d]|\b20\d{2}\b(?! *\))#i', preg_replace('#nasceu no Brasil em 2003#iu', '', $t) ?? $t, 'data ou pré-release');
        foreach (['por enquanto', 'ainda não existe', 'em construção', 'hoje já', 'está sendo', 'estado do projeto'] as $transitorio) {
            self::assertStringNotContainsStringIgnoringCase($transitorio, $t, $transitorio);
        }
        self::assertArrayNotHasKey('estado-do-projeto', ConteudoPublicare::paginas());
        self::assertArrayHasKey('como-acompanhar', ConteudoPublicare::paginas());
        foreach (ConteudoPublicare::guias() as $g) {
            self::assertArrayNotHasKey('data', $g, 'guia sem data de publicação');
        }
    }

    public function testHtmlSoUsaMarcacoesQueOCampoAceita(): void
    {
        $blocos = [ConteudoPublicare::inicio()['corpo'], ConteudoPublicare::inicioDaInstalacao()['corpo']];
        foreach (ConteudoPublicare::paginas() as $p) {
            $blocos[] = $p['corpo'];
        }
        foreach (ConteudoPublicare::perguntas() as [, $r]) {
            $blocos[] = $r;
        }
        foreach (ConteudoPublicare::guias() as $n) {
            $blocos[] = $n['corpo'];
        }
        foreach ($blocos as $html) {
            self::assertDoesNotMatchRegularExpression('#\s(?:class|id|style)=|<script|<style|<details|<svg|<button#i', $html);
            // O que a limpeza do HTML faria com ele (só reescrita de aspas e `rel` nos links) não pode tirar texto.
            self::assertSame(
                trim(strip_tags(html_entity_decode($html))),
                trim(strip_tags(html_entity_decode(HtmlSeguro::limpar($html)))),
            );
        }
    }

    public function testPilaresApontamParaPaginasDoExemploEParaUmDocumento(): void
    {
        self::assertCount(4, ConteudoPublicare::pilares());
        foreach (ConteudoPublicare::pilares() as $p) {
            self::assertArrayHasKey($p['destino'], ConteudoPublicare::paginas());
            self::assertArrayHasKey($p['destino'], ConteudoPublicare::DOCUMENTO_DO_PILAR);
            self::assertFileExists(self::raiz() . '/' . ConteudoPublicare::DOCUMENTO_DO_PILAR[$p['destino']]);
        }
    }

    public function testLinksInternosDoTextoApontamParaPaginasQueExistem(): void
    {
        $existentes = array_keys(ConteudoPublicare::paginas());
        $existentes = [...$existentes, 'perguntas-frequentes', 'contato', 'sobre'];
        foreach (self::textoTodo() === '' ? [] : [self::textoTodo()] as $t) {
            preg_match_all('#href="(/[a-z0-9-]*)"#', $t, $m);
            foreach (array_unique($m[1]) as $href) {
                self::assertContains(trim($href, '/'), $existentes, "link interno sem página: {$href}");
            }
        }
    }

    public function testDocumentosCitadosNoGitHubExistemNoRepositorio(): void
    {
        preg_match_all('#' . preg_quote(ConteudoPublicare::REPOSITORIO, '#') . '/blob/main/([A-Za-z0-9_./-]+)#', self::textoTodo(), $m);
        self::assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $arquivo) {
            self::assertFileExists(self::raiz() . '/' . $arquivo);
        }
    }

    public function testIdentidadeDoPublicare(): void
    {
        self::assertSame('Publicare 4', ConteudoPublicare::NOME_DO_SITE);
        self::assertStringEndsWith('@exemplo.invalid>', ConteudoPublicare::REMETENTE);
        self::assertStringStartsWith('Publicare 4 <', ConteudoPublicare::REMETENTE);
    }
}
