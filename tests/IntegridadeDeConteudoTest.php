<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Banco\Conexao;
use Pbl\Caminhos;
use Pbl\Conteudo\Classe;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\Site;
use Pbl\Conteudo\ViolacaoDeRegra;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;

/** Regras de integridade na camada do repositório (ADR 0012 §9): valem para CLI, importadores e telas. */
final class IntegridadeDeConteudoTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;
    private Site $site;
    private Objeto $inicio;
    private Classe $pagina;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Meu Site', 'www.exemplo.org');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $this->site = $this->s->sites->porHost('www.exemplo.org') ?? self::fail();
        $this->inicio = $this->s->objetos->porId((int) $this->site->objetoRaizId) ?? self::fail();
        $this->pagina = $this->s->classes->porNome('pagina') ?? self::fail();
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function violacao(callable $acao): ViolacaoDeRegra
    {
        try {
            $acao();
        } catch (ViolacaoDeRegra $e) {
            return $e;
        }
        self::fail('esperava ViolacaoDeRegra');
    }

    public function testCriarRecusaTipoQueOPaiNaoAceita(): void
    {
        $evento = $this->s->classes->criar('evento', []);
        $agenda = $this->s->classes->criar('agenda', [], ['filhas' => ['evento']]);
        $folha = $this->s->classes->criar('folha', [], ['aceita_filhos' => false]);
        $pastaAgenda = $this->s->objetos->criar($this->site->id, $agenda, $this->inicio->id, 'Agenda', 'agenda', []);
        $pastaFolha = $this->s->objetos->criar($this->site->id, $folha, $this->inicio->id, 'Folha', 'folha', []);

        $e = $this->violacao(fn () => $this->s->objetos->criar($this->site->id, $this->pagina, $pastaAgenda->id, 'X', 'x', []));
        self::assertSame(ViolacaoDeRegra::TIPO_NAO_ACEITO, $e->codigo);
        self::assertStringContainsString('agenda não aceita conteúdo do tipo pagina', $e->getMessage());
        $e = $this->violacao(fn () => $this->s->objetos->criar($this->site->id, $evento, $pastaFolha->id, 'Y', 'y', []));
        self::assertSame(ViolacaoDeRegra::TIPO_NAO_ACEITO, $e->codigo);
        self::assertInstanceOf(\InvalidArgumentException::class, $e, 'quem tratava InvalidArgumentException continua tratando');
        $e = $this->violacao(fn () => $this->s->objetos->criarDentro($this->site->id, $this->pagina, $pastaAgenda->id, 'Z', 'z', []));
        self::assertSame(ViolacaoDeRegra::TIPO_NAO_ACEITO, $e->codigo);
        self::assertNull($this->s->objetos->porUrl($this->site->id, 'x'));
        self::assertNull($this->s->objetos->porUrl($this->site->id, 'z'));

        $ok = $this->s->objetos->criar($this->site->id, $evento, $pastaAgenda->id, 'Show', 'show', []);
        self::assertSame($pastaAgenda->id, $ok->paiId);
        // Sem pai (página inicial de um site) não há regra de pai.
        self::assertNull($this->s->objetos->criar($this->site->id, $folha, null, 'Raiz', 'raiz-folha', [])->paiId);
    }

    public function testMoverRecusaTipoQueODestinoNaoAceitaMasPermiteRepetirOPai(): void
    {
        $evento = $this->s->classes->criar('evento', []);
        $agenda = $this->s->classes->criar('agenda', [], ['filhas' => ['evento']]);
        $pastaAgenda = $this->s->objetos->criar($this->site->id, $agenda, $this->inicio->id, 'Agenda', 'agenda', []);
        $pagina = $this->s->objetos->criar($this->site->id, $this->pagina, $this->inicio->id, 'P', 'p', []);
        $show = $this->s->objetos->criar($this->site->id, $evento, $this->inicio->id, 'Show', 'show', []);

        $e = $this->violacao(fn () => $this->s->objetos->mover($pagina->id, $pastaAgenda->id));
        self::assertSame(ViolacaoDeRegra::TIPO_NAO_ACEITO, $e->codigo);
        self::assertSame($this->inicio->id, $this->s->objetos->porId($pagina->id)?->paiId, 'nada mudou');
        self::assertSame($pastaAgenda->id, $this->s->objetos->mover($show->id, $pastaAgenda->id)->paiId);
        // Conteúdo que já estava num lugar que hoje não o aceitaria pode ser "movido" para o mesmo pai sem erro.
        $this->s->classes->atualizar($agenda->id, $agenda->versao, [], ['filhas' => ['pagina']]);
        $this->s->objetos->mover($show->id, $pastaAgenda->id);
        self::assertSame($pastaAgenda->id, $this->s->objetos->porId($show->id)?->paiId);
    }

    public function testConteudoQueJaViolaContinuaLegivelEEditavel(): void
    {
        $evento = $this->s->classes->criar('evento', [['nome' => 'local', 'tipo' => 'texto']]);
        $agenda = $this->s->classes->criar('agenda', [], ['filhas' => ['evento']]);
        $pasta = $this->s->objetos->criar($this->site->id, $agenda, $this->inicio->id, 'Agenda', 'agenda', []);
        $show = $this->s->objetos->criar($this->site->id, $evento, $pasta->id, 'Show', 'show', ['local' => 'Praça']);
        // A regra muda depois: a agenda deixa de aceitar eventos, e o campo passa a ser obrigatório.
        $this->s->classes->atualizar($agenda->id, $agenda->versao, [], ['filhas' => ['pagina']]);
        $evento = $this->s->classes->porNome('evento') ?? self::fail();
        $this->s->classes->atualizar($evento->id, $evento->versao, [['nome' => 'local', 'tipo' => 'texto', 'obrigatorio' => true], ['nome' => 'cidade', 'tipo' => 'texto', 'obrigatorio' => true]], []);

        $lido = $this->s->objetos->porId($show->id) ?? self::fail();
        self::assertSame('Praça', $lido->dados['local']);
        self::assertSame('Novo título', $this->s->objetos->atualizar($show->id, ['titulo' => 'Novo título'])->titulo);
        // Editar outro campo não exige o obrigatório que nunca foi preenchido (`cidade`)...
        $editado = $this->s->objetos->atualizar($show->id, ['dados' => ['local' => 'Teatro']]);
        self::assertSame('Teatro', $editado->dados['local']);
        self::assertArrayNotHasKey('cidade', $editado->dados);
        // ...mas esvaziar um obrigatório é recusado.
        $e = $this->violacao(fn () => $this->s->objetos->atualizar($show->id, ['dados' => ['local' => null]]));
        self::assertSame(ViolacaoDeRegra::CAMPO_OBRIGATORIO, $e->codigo);
        self::assertSame('local', $e->campo);
        $e = $this->violacao(fn () => $this->s->objetos->atualizar($show->id, ['dados' => ['local' => '   ']]));
        self::assertSame('local', $e->campo);
        self::assertSame('Teatro', $this->s->objetos->porId($show->id)?->dados['local']);
        // Aparece nas consultas públicas normalmente.
        self::assertContains($show->id, array_map(static fn (Objeto $o): int => $o->id, $this->s->objetos->publicadosDaSubarvore($this->site->id, $pasta->caminho)));
    }

    public function testCriarExigeCamposObrigatoriosNaoArquivados(): void
    {
        $n = $this->s->classes->porNome('noticia') ?? self::fail();
        $extras = [
            ['nome' => 'peso_extra', 'tipo' => 'numero', 'obrigatorio' => true],
            ['nome' => 'aberto', 'tipo' => 'booleano', 'obrigatorio' => true],
            ['nome' => 'velho', 'tipo' => 'texto', 'obrigatorio' => true],
            ['nome' => 'livre', 'tipo' => 'texto'],
        ];
        $base = array_map(
            static fn (array $p): array => $p['nome'] === 'resumo' ? ['obrigatorio' => true, 'rotulo' => 'Resumo da notícia'] + $p : $p,
            $n->propriedades,
        );
        $c = $this->s->classes->atualizar($n->id, $n->versao, [...$base, ...$extras], ['filhas' => $n->filhas]);
        // Arquiva o `velho`: some dos formulários, então deixa de ser exigido.
        $extras[2]['arquivada'] = true;
        $c = $this->s->classes->atualizar($c->id, $c->versao, [...$base, ...$extras], ['filhas' => $n->filhas]);

        $e = $this->violacao(fn () => $this->s->objetos->criar($this->site->id, $c, $this->inicio->id, 'N', 'n', ['peso_extra' => 1, 'aberto' => 0]));
        self::assertSame(ViolacaoDeRegra::CAMPO_OBRIGATORIO, $e->codigo);
        self::assertSame('resumo', $e->campo);
        self::assertStringContainsString('Resumo da notícia', $e->getMessage());
        $e = $this->violacao(fn () => $this->s->objetos->criar($this->site->id, $c, $this->inicio->id, 'N', 'n', ['resumo' => '  ', 'peso_extra' => 1, 'aberto' => 0]));
        self::assertSame('resumo', $e->campo, 'só espaços não conta');
        $e = $this->violacao(fn () => $this->s->objetos->criar($this->site->id, $c, $this->inicio->id, 'N', 'n', ['resumo' => 'x']));
        self::assertSame('peso_extra', $e->campo);
        self::assertNull($this->s->objetos->porUrl($this->site->id, 'n'));

        // Zero e falso são valores, não "vazio".
        $ok = $this->s->objetos->criar($this->site->id, $c, $this->inicio->id, 'N', 'n', ['resumo' => 'x', 'peso_extra' => 0, 'aberto' => false]);
        self::assertSame(0, $ok->dados['peso_extra']);
        self::assertSame(0, $ok->dados['aberto']);
        // criarDentro obedece igual.
        $e = $this->violacao(fn () => $this->s->objetos->criarDentro($this->site->id, $c, $this->inicio->id, 'M', 'm', []));
        self::assertSame(ViolacaoDeRegra::CAMPO_OBRIGATORIO, $e->codigo);
    }

    public function testRestaurarVersaoSemObrigatorioRecusaSemGravar(): void
    {
        $c = $this->s->classes->criar('nota', [['nome' => 'texto', 'tipo' => 'texto']]);
        $o = $this->s->objetos->criar($this->site->id, $c, $this->inicio->id, 'N', 'n', []);
        $c = $this->s->classes->atualizar($c->id, $c->versao, [['nome' => 'texto', 'tipo' => 'texto', 'obrigatorio' => true]], []);
        $o = $this->s->objetos->atualizar($o->id, ['dados' => ['texto' => 'preenchido']]);
        $e = $this->violacao(fn () => $this->s->objetos->restaurar($o->id, 1));
        self::assertSame('texto', $e->campo);
        self::assertSame('preenchido', $this->s->objetos->porId($o->id)?->dados['texto']);
    }

    public function testPropriedadeHtmlELimpaNaGravacaoPorTodosOsCaminhos(): void
    {
        $c = $this->s->classes->criar('artigo', [['nome' => 'corpo', 'tipo' => 'html'], ['nome' => 'obrig', 'tipo' => 'html']]);
        $o = $this->s->objetos->criar($this->site->id, $c, $this->inicio->id, 'A', 'a', ['corpo' => '<p onclick="x()">oi</p><script>alert(1)</script><a href="javascript:alert(1)">l</a>']);
        self::assertSame('<p>oi</p>l', $o->dados['corpo']);
        $o = $this->s->objetos->atualizar($o->id, ['dados' => ['corpo' => '<img src=x onerror=alert(1)><b>ok</b>']]);
        self::assertSame('<img src="x"><b>ok</b>', $o->dados['corpo']);
        // Restaurar uma versão antiga que (por dado legado) tinha HTML perigoso também passa pela limpeza.
        $conexao = Conexao::sqlite($this->caminhos->banco());
        $conexao->executeStatement('UPDATE objeto_versao SET dados = ? WHERE objeto_id = ? AND versao = 1', [json_encode(['corpo' => '<script>alert(9)</script>velho'], JSON_THROW_ON_ERROR), $o->id]);
        $restaurado = $this->s->objetos->restaurar($o->id, 1);
        self::assertSame('velho', $restaurado->dados['corpo']);
        // O banco guarda o texto limpo.
        self::assertStringNotContainsString('script', (string) $conexao->fetchOne('SELECT dados FROM objeto WHERE id = ?', [$o->id]));
        // Só texto é aceito numa propriedade html.
        $this->expectException(\InvalidArgumentException::class);
        $this->s->objetos->atualizar($o->id, ['dados' => ['corpo' => 5]]);
    }

    public function testHtmlVazioAposALimpezaContaComoVazioParaObrigatorio(): void
    {
        $c = $this->s->classes->criar('artigo2', [['nome' => 'corpo', 'tipo' => 'html', 'obrigatorio' => true]]);
        $e = $this->violacao(fn () => $this->s->objetos->criar($this->site->id, $c, $this->inicio->id, 'A', 'a2', ['corpo' => '<script>alert(1)</script>']));
        self::assertSame('corpo', $e->campo);
    }

    public function testDataImpossivelNaoEhCorrigidaEmSilencio(): void
    {
        $o = $this->s->objetos->criar($this->site->id, $this->pagina, $this->inicio->id, 'DI', 'di', []);
        foreach (['2026-02-31', '2026-13-01', '2026-04-31', '2026-02-30 10:00', '2026-00-10'] as $ruim) {
            $e = $this->violacao(fn () => $this->s->objetos->atualizar($o->id, ['data_publicacao' => $ruim]));
            self::assertSame(ViolacaoDeRegra::DATA_INVALIDA, $e->codigo, $ruim);
            $e = $this->violacao(fn () => $this->s->objetos->criar($this->site->id, $this->pagina, $this->inicio->id, 'DJ', 'dj', [], 'publicado', ['data_validade' => $ruim]));
            self::assertSame('data_validade', $e->campo, $ruim);
        }
        self::assertNull($this->s->objetos->porId($o->id)?->dataPublicacao);
        self::assertSame('2028-02-29T00:00:00+00:00', $this->s->objetos->atualizar($o->id, ['data_publicacao' => '2028-02-29'])->dataPublicacao, 'bissexto vale');
        self::assertSame('2026-04-30T12:00:00+00:00', $this->s->objetos->atualizar($o->id, ['data_publicacao' => '2026-04-30 12:00'])->dataPublicacao);
    }

    public function testDatasDeVigenciaSaoNormalizadasParaUtc(): void
    {
        $o = $this->s->objetos->criar($this->site->id, $this->pagina, $this->inicio->id, 'D', 'd', [], 'publicado', ['data_publicacao' => '2030-01-02T10:00:00-03:00', 'data_validade' => '2030-02-01']);
        self::assertSame('2030-01-02T13:00:00+00:00', $o->dataPublicacao);
        self::assertSame('2030-02-01T00:00:00+00:00', $o->dataValidade);
        $o = $this->s->objetos->atualizar($o->id, ['data_publicacao' => '2031-05-05 08:30', 'data_validade' => null]);
        self::assertSame('2031-05-05T08:30:00+00:00', $o->dataPublicacao);
        self::assertNull($o->dataValidade);
        $e = $this->violacao(fn () => $this->s->objetos->atualizar($o->id, ['data_publicacao' => 'amanhã de manhã']));
        self::assertSame(ViolacaoDeRegra::DATA_INVALIDA, $e->codigo);
        $e = $this->violacao(fn () => $this->s->objetos->criar($this->site->id, $this->pagina, $this->inicio->id, 'D2', 'd2', [], 'publicado', ['data_validade' => 'nunca']));
        self::assertSame('data_validade', $e->campo);
    }
}
