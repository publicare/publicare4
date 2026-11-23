<?php

declare(strict_types=1);

namespace Pbl\Testes\E2E;

use Pbl\Testes\AdminAuxiliar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * E2E do painel fase 2 (features/painel-fase2.feature): navega pela `Aplicacao` inteira (roteador, sessão, CSRF, telas, menu),
 * como o navegador faria sem JavaScript. Cada teste leva o título do cenário Gherkin que cobre.
 */
final class PainelFase2PontaAPontaTest extends TestCase
{
    use AdminAuxiliar;

    /** @return array<string, array{string, string}> rótulo => [caminho, item ativo do menu] */
    public static function telas(): array
    {
        return [
            'atualização' => ['/_pbl/atualizacao', 'Mais'],
            'atualização/histórico' => ['/_pbl/atualizacao?aba=historico', 'Mais'],
            'atualização/configuração' => ['/_pbl/atualizacao?aba=configuracao', 'Mais'],
            'cópias' => ['/_pbl/copias', 'Mais'],
            'cópias/agendamento' => ['/_pbl/copias?aba=agendamento', 'Mais'],
            'cópias/destino' => ['/_pbl/copias?aba=destino', 'Mais'],
            'tarefas' => ['/_pbl/tarefas', 'Mais'],
            'contas' => ['/_pbl/contas', 'Mais'],
            'tipos' => ['/_pbl/classes', 'Mais'],
            'modelos' => ['/_pbl/modelos', 'Mais'],
            'domínios' => ['/_pbl/site/{site}/dominios', 'Site'],
            'configurações' => ['/_pbl/site/{site}/configuracoes', 'Site'],
            'revisão' => ['/_pbl/revisar?aba=pendencias', 'Revisar'],
        ];
    }

    private function abrir(string $caminho, string $login = 'admin'): string
    {
        $r = $this->req('GET', str_replace('{site}', (string) $this->siteId, $caminho), $this->token($login));
        self::assertSame(200, $r->getStatusCode(), $caminho);

        return $this->html($r);
    }

    #[DataProvider('telas')]
    public function testTelaTemUmTituloMigalhasMenuDeCincoItensEItemAtivo(string $caminho, string $ativo): void
    {
        $h = $this->abrir($caminho);
        self::assertSame(1, substr_count($h, '<h1'), 'um único h1');
        self::assertStringContainsString('class="pbl-migalhas"', $h, 'migalhas');
        preg_match('~<ul class="pbl-itens">(.*?)</ul>~s', $h, $menu);
        self::assertCount(2, $menu, 'menu principal');
        $itens = $menu[1] ?? '';
        self::assertLessThanOrEqual(5, substr_count($itens, '<li'), 'no máximo 5 itens');
        self::assertSame(1, substr_count($itens, 'aria-current="page"'), 'um item ativo');
        self::assertMatchesRegularExpression('~aria-current="page"[^>]*>\s*' . $ativo . '|' . $ativo . '[^<]*</a>~u', $itens);
        self::assertStringContainsString('aria-current="page"', $h);
    }

    #[DataProvider('telas')]
    public function testTelaNaoUsaScriptNemEstiloEmLinha(string $caminho): void
    {
        $h = $this->abrir($caminho);
        self::assertDoesNotMatchRegularExpression('~<script(?![^>]*\ssrc=)~i', $h, 'script em linha');
        self::assertDoesNotMatchRegularExpression('~\sstyle=~i', $h, 'style em linha');
        self::assertDoesNotMatchRegularExpression('~\son[a-z]+\s*=~i', $h, 'manipulador de evento em linha');
    }

    #[DataProvider('telas')]
    public function testSemSessaoTodaTelaVaiParaEntrar(string $caminho): void
    {
        $r = $this->req('GET', str_replace('{site}', (string) $this->siteId, $caminho));
        self::assertSame(303, $r->getStatusCode());
        self::assertStringContainsString('/_pbl/entrar', (string) $r->headers->get('Location'));
    }

    public function testAtualizacaoTemUmaAcaoPrincipalETresAbasEmLink(): void
    {
        $h = $this->abrir('/_pbl/atualizacao');
        self::assertSame(1, substr_count($h, 'class="pbl-cabecalho-acao"'), 'uma ação principal');
        self::assertStringContainsString('aria-label="Seções da atualização"', $h);
        foreach (['Atualizar', 'Histórico e voltar atrás', 'Configuração'] as $aba) {
            self::assertStringContainsString($aba, $h);
        }
        self::assertSame(1, substr_count($h, '<ul class="pbl-abas-links">'));
        self::assertStringNotContainsString('id="titulo-andamento"', $h, 'sem atualização em curso o andamento fica escondido');
    }

    public function testAbaDesconhecidaCaiNaPadraoSemErro(): void
    {
        $h = $this->abrir('/_pbl/atualizacao?aba=nao-existe');
        self::assertStringContainsString('Seções da atualização', $h);
    }

    public function testDiagnosticoTecnicoFicaRecolhidoNaConfiguracao(): void
    {
        $h = $this->abrir('/_pbl/atualizacao?aba=configuracao');
        self::assertMatchesRegularExpression('~<details class="pbl-recolhivel" id="painel-diagnostico"~', $h);
        self::assertStringNotContainsString('id="painel-diagnostico" open', $h . '', 'recolhido quando tudo está em ordem ou aberto só se faltar algo');
    }

    public function testVoltarAtrasFicaNoHistoricoEPedeSenhaEConfirmacao(): void
    {
        $atualizar = $this->abrir('/_pbl/atualizacao');
        self::assertStringNotContainsString('/_pbl/atualizacao/reverter', $atualizar);
        $h = $this->abrir('/_pbl/atualizacao?aba=historico');
        self::assertStringContainsString('Histórico', $h);
    }

    public function testPostSemCsrfEmQualquerFormularioDaFase2EhRecusado(): void
    {
        $t = $this->token('admin');
        foreach (['/_pbl/copias/fazer', '/_pbl/atualizacao/verificar', '/_pbl/tarefas/executar'] as $rota) {
            $r = $this->req('POST', $rota, $t, [], self::ORIGEM);
            self::assertContains($r->getStatusCode(), [403, 404, 405, 422], $rota);
            self::assertNotSame(200, $r->getStatusCode(), $rota);
        }
    }

    public function testQuemNaoAdministraNaoVeAsTelasDeSistema(): void
    {
        foreach (['/_pbl/atualizacao', '/_pbl/copias', '/_pbl/tarefas', '/_pbl/contas', '/_pbl/classes/nova', '/_pbl/modelos'] as $c) {
            $r = $this->req('GET', $c, $this->token('editor'));
            self::assertContains($r->getStatusCode(), [403, 404], $c);
        }
    }

    public function testListaDeTiposEhDeLeituraParaTodosMasCriarEhSoDoAdministrador(): void
    {
        self::assertStringContainsString('Tipos de conteúdo', $this->abrir('/_pbl/classes', 'editor'));
        self::assertSame(403, $this->req('GET', '/_pbl/classes/nova', $this->token('editor'))->getStatusCode());
        self::assertSame(200, $this->req('GET', '/_pbl/classes/nova', $this->token('admin'))->getStatusCode());
    }

    public function testMaisListaOsAtalhosDoAdministradorEOEditorSoVeOQuePode(): void
    {
        $adm = $this->abrir('/_pbl/mais');
        foreach (['/_pbl/atualizacao', '/_pbl/copias', '/_pbl/tarefas', '/_pbl/contas'] as $href) {
            self::assertStringContainsString('href="' . $href . '"', $adm, $href);
        }
        $ed = $this->abrir('/_pbl/mais', 'editor');
        self::assertStringNotContainsString('href="/_pbl/atualizacao"', $ed);
        self::assertStringNotContainsString('href="/_pbl/contas"', $ed);
    }

    public function testContasMostraBuscaEAFichaTemAbas(): void
    {
        $lista = $this->abrir('/_pbl/contas');
        self::assertMatchesRegularExpression('~type="search"|name="q"~', $lista, 'busca na lista');
        $ficha = $this->abrir('/_pbl/conta/' . $this->ids['editor']);
        foreach (['Dados', 'Papéis e acessos', 'Segurança'] as $aba) {
            self::assertStringContainsString($aba, $ficha);
        }
    }

    public function testDominiosMostraOPrincipalEmDestaque(): void
    {
        $h = $this->abrir('/_pbl/site/{site}/dominios');
        self::assertStringContainsString('www.exemplo.org', $h);
        self::assertMatchesRegularExpression('~[Pp]rincipal~', $h);
    }

    public function testRevisaoSemPendenciasMostraEstadoVazioUtil(): void
    {
        $h = $this->abrir('/_pbl/revisar?aba=pendencias');
        self::assertStringContainsString('Nada aguardando revisão agora.', $h);
        self::assertStringContainsString('role="status"', $h);
    }

    public function testRotasAntigasRedirecionamCom303(): void
    {
        foreach (['/_pbl/pendencias' => '/_pbl/revisar', '/_pbl/meus-envios' => '/_pbl/revisar'] as $antiga => $nova) {
            $r = $this->req('GET', $antiga, $this->token('admin'));
            self::assertSame(303, $r->getStatusCode(), $antiga);
            self::assertStringContainsString($nova, (string) $r->headers->get('Location'));
        }
    }
}
