<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\SessaoRepositorio;
use Pbl\Aplicacao;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Auditoria do CLI (ADR 0032): autor real, canal `via`, agente identificado, nenhum conteúdo gravado; e equivalência com o painel. */
final class ConteudoAuditoriaTest extends TestCase
{
    use ConteudoAuxiliar;

    private const UNICO = 'textoUnicoQueNaoPodeVazarXyz';

    /** @return list<array{quando: string, ator: string, acao: string, objeto_id: ?int, detalhe: ?string}> */
    private function eventos(int $objetoId): array
    {
        return $this->servicos()->auditoria->doObjeto($objetoId, 100);
    }

    public function testEdicaoPeloCliGravaOAutorRealEOCanal(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        [$codigo, $saida] = $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'claudim', '--versao' => '1', '--campo' => ['titulo=Título ' . self::UNICO, 'resumo=' . self::UNICO], '--json' => null]);
        self::assertSame(0, $codigo, $saida);

        $e = array_values(array_filter($this->eventos($id), static fn (array $e): bool => $e['acao'] === 'objeto.atualizar'));
        self::assertNotEmpty($e);
        self::assertSame('usuario:' . $this->ids['claudim'], $e[0]['ator']);
        self::assertStringContainsString('"via":"cli"', (string) $e[0]['detalhe']);
        self::assertSame($this->ids['claudim'], (int) $this->s->conexao()->fetchOne('SELECT usuario_id FROM objeto_versao WHERE objeto_id = ? AND versao = 2', [$id]));
    }

    public function testNadaDoConteudoVaiParaAuditoriaNemParaAMensagemDeErro(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'claudim', '--versao' => '1', '--campo' => ['titulo=' . self::UNICO, 'resumo=' . self::UNICO, 'corpo=<p>' . self::UNICO . '</p>'], '--json' => null]);
        [, $saidaErro] = $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'claudim', '--versao' => '2', '--campo' => ['peso=' . self::UNICO, 'url=' . self::UNICO], '--json' => null]);
        $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'leitor', '--versao' => '2', '--campo' => ['titulo=' . self::UNICO . 'x'], '--json' => null]);

        $tudo = json_encode($this->s->conexao()->fetchAllAssociative('SELECT * FROM auditoria'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString(self::UNICO, $tudo);
        self::assertStringNotContainsString(self::UNICO, $saidaErro, 'a mensagem de erro nunca repete o valor digitado');
    }

    public function testRevisaoPeloCliTambemLevaOCanal(): void
    {
        $id = $this->pagina('Rascunho', 'rascunho', 'privado', null, $this->ids['autor']);
        $this->cli(['acao' => 'enviar', 'alvo' => (string) $id, '--como' => 'autor', '--versao' => '1', '--json' => null]);
        $e = array_values(array_filter($this->eventos($id), static fn (array $e): bool => $e['acao'] === 'revisao.enviou'));

        self::assertNotEmpty($e);
        self::assertSame('usuario:' . $this->ids['autor'], $e[0]['ator']);
        self::assertStringContainsString('"via":"cli"', (string) $e[0]['detalhe']);
    }

    public function testComandosQueNaoSaoDeConteudoNaoGanhamCanal(): void
    {
        $this->s->usuarios->conceder($this->ids['editor'], $this->inicioId, \Pbl\Acesso\Perfil::Editor, 'cli');
        $e = array_values(array_filter($this->servicos()->auditoria->recentes(20), static fn (array $e): bool => $e['acao'] === 'acesso.conceder'));
        self::assertStringNotContainsString('via', (string) ($e[0]['detalhe'] ?? ''));
    }

    private function sessao(string $login): string
    {
        return $this->s->sessoes()->criar($this->ids[$login]);
    }

    /** @param array<string, mixed> $campos */
    private function painel(string $metodo, string $caminho, string $token, array $campos = []): Response
    {
        $csrf = $this->s->csrf()->daSessao(SessaoRepositorio::hash($token));
        $r = Request::create('https://www.exemplo.org' . $caminho, $metodo, $metodo === 'POST' ? ['_csrf' => $csrf] + $campos : [], ['__Host-pbl4' => $token], [], ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_ORIGIN' => 'https://www.exemplo.org']);

        return (new Aplicacao($this->caminhos))->tratar($r);
    }

    public function testPainelECliProduzemOMesmoResultado(): void
    {
        $viaPainel = $this->pagina('Igual', 'igual-painel');
        $viaCli = $this->pagina('Igual', 'igual-cli');
        $sujo = '<p onclick="x()">Corpo</p><script>1</script>';

        $token = $this->sessao('editor');
        $r = $this->painel('POST', '/_pbl/objeto/' . $viaPainel . '/editar', $token, [
            'versao' => '1', 'titulo' => 'Título novo', 'descricao' => 'Desc', 'url' => 'igual-painel', 'peso' => '7', 'rotulo_menu' => '', 'tags' => 'um, dois',
            'dados' => ['resumo' => 'Resumo', 'corpo' => $sujo],
        ]);
        self::assertSame(303, $r->getStatusCode(), (string) $r->getContent());
        [$codigo, $saida] = $this->cli(['acao' => 'editar', 'alvo' => (string) $viaCli, '--como' => 'editor', '--versao' => '1', '--json' => '-'], json_encode([
            'titulo' => 'Título novo', 'descricao' => 'Desc', 'peso' => 7, 'tags' => 'um, dois', 'dados' => ['resumo' => 'Resumo', 'corpo' => $sujo],
        ], JSON_THROW_ON_ERROR));
        self::assertSame(0, $codigo, $saida);

        $a = $this->objeto($viaPainel);
        $b = $this->objeto($viaCli);
        foreach (['titulo', 'descricao', 'peso', 'versao', 'status', 'dados', 'noMenu', 'rotuloMenu'] as $campo) {
            self::assertSame($a->$campo, $b->$campo, $campo);
        }
        self::assertSame($this->servicos()->tags->nomesDoObjeto($viaPainel), $this->servicos()->tags->nomesDoObjeto($viaCli));
        $acoes = static fn (array $es): array => array_values(array_unique(array_column($es, 'acao')));
        self::assertSame($acoes($this->eventos($viaPainel)), $acoes($this->eventos($viaCli)), 'mesmas ações de auditoria');
        $porPainel = array_values(array_filter($this->eventos($viaPainel), static fn (array $e): bool => $e['acao'] === 'objeto.atualizar'))[0];
        self::assertStringNotContainsString('via', (string) $porPainel['detalhe'], 'o painel não ganha o canal');
        self::assertSame($this->ids['editor'], (int) $this->s->conexao()->fetchOne('SELECT usuario_id FROM objeto_versao WHERE objeto_id = ? AND versao = 2', [$viaCli]));
    }

    public function testPainelRecusaOQueOCliRecusaNoEnviadoParaRevisao(): void
    {
        $id = $this->pagina('Enviado', 'enviado', 'privado', null, $this->ids['autor']);
        $this->cli(['acao' => 'enviar', 'alvo' => (string) $id, '--como' => 'autor', '--versao' => '1', '--json' => null]);
        $v = $this->objeto($id)->versao;

        [$codigo] = $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'autor', '--versao' => (string) $v, '--campo' => ['titulo=Não'], '--json' => null]);
        $r = $this->painel('POST', '/_pbl/objeto/' . $id . '/editar', $this->sessao('autor'), ['versao' => (string) $v, 'titulo' => 'Não', 'descricao' => '', 'url' => 'enviado', 'peso' => '0']);

        self::assertSame(4, $codigo);
        self::assertSame(403, $r->getStatusCode());
        self::assertSame('Enviado', $this->objeto($id)->titulo);
    }

    public function testLogDoObjetoIdentificaAgenteEViaLinhaDeComando(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'claudim', '--versao' => '1', '--campo' => ['titulo=Pelo agente'], '--json' => null]);
        $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'editor', '--versao' => '2', '--campo' => ['titulo=Pela pessoa via CLI'], '--json' => null]);
        $this->painel('POST', '/_pbl/objeto/' . $id . '/editar', $this->sessao('admin'), ['versao' => '3', 'titulo' => 'Pelo painel', 'descricao' => '', 'url' => 'quem-somos', 'peso' => '0']);

        $html = (string) $this->painel('GET', '/_pbl/objeto/' . $id . '/log', $this->sessao('admin'))->getContent();
        self::assertStringContainsString('Claudim (agente, via linha de comando)', $html);
        self::assertStringContainsString('Editor (via linha de comando)', $html);
        self::assertMatchesRegularExpression('/Admin\s*<\/td>/', $html, 'o painel continua sem o complemento');
    }
}
