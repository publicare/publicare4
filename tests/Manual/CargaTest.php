<?php

declare(strict_types=1);

namespace Pbl\Testes\Manual;

use Pbl\Conteudo\Servico\ConteudoNaoEncontrado;
use Pbl\Manual\Carga;
use Pbl\Manual\Compilador;
use Pbl\Manual\Edicao;
use Pbl\Testes\ConteudoAuxiliar;
use PHPUnit\Framework\TestCase;

/** Carga do manual num site de verdade (SQLite temporário): idempotência, simulação, edição preservada, despublicação, edições e render com a pele. */
final class CargaTest extends TestCase
{
    use ConteudoAuxiliar;

    /** @return array<string, mixed> */
    private function pacote(): array
    {
        $docs = dirname(__DIR__, 2) . '/docs/usuario';

        return (new Compilador($docs, dirname(__DIR__, 2) . '/manual/links-externos.json', null, true))->compilar();
    }

    private function carga(): Carga
    {
        $site = $this->s->sites->porId($this->siteId) ?? self::fail();

        return new Carga($this->servicos(), $this->s->usuarios->porLogin('admin') ?? self::fail(), $site);
    }

    private function total(): int
    {
        return $this->s->objetos->contarDoSite($this->siteId);
    }

    public function testSimularNaoGravaENadaDeSegundaRodada(): void
    {
        $pacote = $this->pacote();
        $antes = $this->total();
        $simulado = $this->carga()->executar($pacote, Edicao::estavel(), true, false);
        self::assertTrue($simulado['alterado']);
        self::assertSame($antes, $this->total(), 'simular não grava');
        self::assertNull($this->s->classes->porNome(Carga::CLASSE_PAGINA), 'nem cria as classes');

        $primeira = $this->carga()->executar($pacote, Edicao::estavel(), false, false);
        self::assertTrue($primeira['alterado']);
        $paginas = array_sum(array_map(static fn (array $c): int => count($c['paginas']), $pacote['capitulos']));
        self::assertSame($paginas + count($pacote['capitulos']), $primeira['resumo']['criar']);
        self::assertSame($antes + $paginas + count($pacote['capitulos']), $this->total());

        $segunda = $this->carga()->executar($pacote, Edicao::estavel(), false, false);
        self::assertFalse($segunda['alterado'], 'idempotente: nada a alterar');
        self::assertSame([], $segunda['conflitos']);
        self::assertSame(['igual'], array_keys($segunda['resumo']));
        self::assertSame($antes + $paginas + count($pacote['capitulos']), $this->total());
    }

    public function testEdicaoFeitaNoPainelEhPreservadaEForcarSobrescreve(): void
    {
        $pacote = $this->pacote();
        $this->carga()->executar($pacote, Edicao::estavel(), false, false);
        $primeira = $pacote['capitulos'][0]['paginas'][0];
        $admin = $this->s->usuarios->porLogin('admin') ?? self::fail();
        $obj = $this->s->conteudo->obterPorEndereco($admin, $this->s->sites->porId($this->siteId) ?? self::fail(), $pacote['capitulos'][0]['slug'] . '/' . $primeira['slug']);
        $this->s->conteudo->editar($admin, $obj->id, $obj->versao, ['dados' => ['corpo' => '<p>Correção feita à mão.</p>']]);

        $r = $this->carga()->executar($pacote, Edicao::estavel(), false, false);
        self::assertSame([$edicaoOrigem = 'manual:estavel:' . $pacote['capitulos'][0]['slug'] . '/' . $primeira['slug']], $r['conflitos']);
        self::assertSame(1, $r['resumo']['preservar']);
        $lido = $this->s->conteudo->obter($admin, $obj->id);
        self::assertStringContainsString('Correção feita à mão', (string) $lido->dados['corpo']);

        $f = $this->carga()->executar($pacote, Edicao::estavel(), false, true);
        self::assertSame([], $f['conflitos']);
        $novo = $this->s->conteudo->obter($admin, $obj->id);
        self::assertStringNotContainsString('Correção feita à mão', (string) $novo->dados['corpo']);
        self::assertGreaterThan($lido->versao, $novo->versao, 'sobrescrever grava versão nova');
        self::assertNotEmpty($edicaoOrigem);
    }

    public function testPaginaQueSumiuFicaPrivadaENuncaNaLixeira(): void
    {
        $pacote = $this->pacote();
        $this->carga()->executar($pacote, Edicao::estavel(), false, false);
        $ultimo = array_key_last($pacote['capitulos']);
        $removida = array_pop($pacote['capitulos'][$ultimo]['paginas']);
        $r = $this->carga()->executar($pacote, Edicao::estavel(), false, false);
        self::assertSame(1, $r['resumo']['despublicar']);
        $admin = $this->s->usuarios->porLogin('admin') ?? self::fail();
        $o = $this->s->conteudo->obterPorEndereco($admin, $this->s->sites->porId($this->siteId) ?? self::fail(), $pacote['capitulos'][$ultimo]['slug'] . '/' . $removida['slug']);
        self::assertSame('privado', $o->status);
        self::assertFalse($this->carga()->executar($pacote, Edicao::estavel(), false, false)['alterado']);
    }

    public function testBetaEVersaoGuardadaNaoTocamAEstavel(): void
    {
        $pacote = $this->pacote();
        $this->carga()->executar($pacote, Edicao::estavel(), false, false);
        $estavel = $this->total();
        foreach ([Edicao::beta(), Edicao::daVersao('v1.2.3-rc.1')] as $ed) {
            $r = $this->carga()->executar($pacote, $ed, false, false);
            self::assertTrue($r['alterado']);
            self::assertFalse($this->carga()->executar($pacote, $ed, false, false)['alterado']);
        }
        self::assertGreaterThan($estavel, $this->total());
        $admin = $this->s->usuarios->porLogin('admin') ?? self::fail();
        $site = $this->s->sites->porId($this->siteId) ?? self::fail();
        $cap = $pacote['capitulos'][0]['slug'];
        $pagina = $this->s->conteudo->obterPorEndereco($admin, $site, '1-2-3-rc-1/' . $cap . '/' . $pacote['capitulos'][0]['paginas'][0]['slug']);
        self::assertStringContainsString('href="/1-2-3-rc-1/', (string) $pagina->dados['navegacao']);
        self::assertStringNotContainsString('~ed~', (string) $pagina->dados['corpo']);
        $this->expectException(ConteudoNaoEncontrado::class);
        $this->s->conteudo->obterPorEndereco($admin, $site, 'beta/inexistente');
    }

    public function testRenderComAPeleDoManual(): void
    {
        $pacote = $this->pacote();
        $this->carga()->executar($pacote, Edicao::estavel(), false, false);
        $this->carga()->executar($pacote, Edicao::beta(), false, false);
        $this->s->sites->definirPele($this->siteId, 'manual');
        $s = $this->servicos();
        $site = $s->sites->porId($this->siteId) ?? self::fail();
        $admin = $s->usuarios->porLogin('admin') ?? self::fail();
        $p = $pacote['capitulos'][0]['paginas'][1];
        $o = $s->conteudo->obterPorEndereco($admin, $site, $pacote['capitulos'][0]['slug'] . '/' . $p['slug']);
        $html = $s->renderizador->renderizar($site, $o);

        self::assertSame(1, substr_count($html, '<h1'), 'um só h1');
        foreach (['<header class="topo">', '<nav class="lateral" id="lateral" aria-label="Capítulos">', '<main id="conteudo"', 'class="pular"', 'role="search"', 'data-manual-corpo', 'aria-label="Anterior e próximo"'] as $parte) {
            self::assertStringContainsString($parte, $html);
        }
        self::assertStringContainsString('manual.css', $html);
        self::assertStringContainsString('href="/beta/"', $html, 'o seletor lista a edição beta');
        self::assertDoesNotMatchRegularExpression('/\sstyle=|\son[a-z]+=|<style/i', $html);
        self::assertDoesNotMatchRegularExpression('~<script(?![^>]*\ssrc=)~i', $html);
        self::assertDoesNotMatchRegularExpression('~<(?:script|img)\b[^>]*\ssrc="(?:https?:)?//|<link\b[^>]*stylesheet[^>]*\shref="(?:https?:)?//~i', $html, 'sem CDN: recursos só do próprio site');
    }
}
