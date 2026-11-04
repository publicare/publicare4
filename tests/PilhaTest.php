<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Perfil;
use Pbl\Acesso\SessaoRepositorio;
use Pbl\Aplicacao;
use Pbl\Caminhos;
use Pbl\Conteudo\Classe;
use Pbl\Conteudo\Colagem;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\Pilha;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** A pilha de copiar, recortar e colar (fase 5r, ADR 0025): separar, previsão, mover, copiar (com subárvore e arquivos) e colar link. */
final class PilhaTest extends TestCase
{
    private const SENHA = 'uma senha bem longa 2026';
    private const ORIGEM = ['HTTP_ORIGIN' => 'https://www.exemplo.org'];
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;
    private Objeto $inicio;
    private Classe $pagina;
    private Classe $pasta;
    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Meu Site', 'www.exemplo.org');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $site = $this->s->sites->porHost('www.exemplo.org');
        self::assertNotNull($site);
        $this->inicio = $this->obj((int) $site->objetoRaizId);
        $this->pagina = $this->classe('pagina');
        $this->pasta = $this->classe('pasta');
        foreach (['admin' => true, 'leitor' => false, 'autor' => false, 'editor' => false] as $login => $adm) {
            $this->ids[$login] = $this->s->usuarios->criar($login, $login . '@exemplo.org', ucfirst($login), self::SENHA, $adm)->id;
        }
        $this->s->usuarios->conceder($this->ids['leitor'], $this->inicio->id, Perfil::Leitor);
        $this->s->usuarios->conceder($this->ids['autor'], $this->inicio->id, Perfil::Autor);
        $this->s->usuarios->conceder($this->ids['editor'], $this->inicio->id, Perfil::Editor);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function classe(string $nome): Classe
    {
        $c = $this->s->classes->porNome($nome);
        self::assertNotNull($c);

        return $c;
    }

    private function obj(int $id): Objeto
    {
        $o = $this->s->objetos->porId($id);
        self::assertNotNull($o);

        return $o;
    }

    /** @param array<string, mixed> $dados */
    private function novo(Objeto $pai, string $titulo, string $url, ?Classe $classe = null, string $status = 'publicado', array $dados = [], ?int $autor = null): Objeto
    {
        return $this->s->objetos->criar($pai->siteId, $classe ?? $this->pagina, $pai->id, $titulo, $url, $dados, $status, $autor === null ? [] : ['criado_por' => $autor]);
    }

    private function token(string $login): string
    {
        return $this->s->sessoes()->criar($this->ids[$login]);
    }

    private function csrf(string $token): string
    {
        return $this->s->csrf()->daSessao(SessaoRepositorio::hash($token));
    }

    /**
     * @param array<string, mixed> $campos
     * @param array<string, string> $cab
     */
    private function req(string $metodo, string $caminho, ?string $token = null, array $campos = [], array $cab = self::ORIGEM): Response
    {
        $cookies = $token === null ? [] : ['__Host-pbl4' => $token];
        $r = Request::create('https://www.exemplo.org' . $caminho, $metodo, $campos, $cookies, [], ['REMOTE_ADDR' => '198.51.100.7'] + $cab);

        return (new Aplicacao($this->caminhos))->tratar($r);
    }

    /** @param list<Objeto> $alvos */
    private function separar(string $login, string $modo, array $alvos, ?string $token = null): Response
    {
        $t = $token ?? $this->token($login);
        $versoes = [];
        foreach ($alvos as $o) {
            $versoes[$o->id] = (string) $o->versao;
        }

        return $this->req('POST', '/_pbl/pilha/adicionar', $t, [
            '_csrf' => $this->csrf($t), 'modo' => $modo, 'nivel' => (string) $this->inicio->id,
            'ids' => array_map(static fn (Objeto $o): string => (string) $o->id, $alvos), 'versao' => $versoes,
        ]);
    }

    /** @param array<string, string> $extra */
    private function colar(string $login, Objeto $destino, string $tipo, array $extra = []): Response
    {
        $t = $this->token($login);

        return $this->req('POST', '/_pbl/pilha/colar/' . $destino->id, $t, ['_csrf' => $this->csrf($t), 'tipo' => $tipo] + $extra);
    }

    /** @return list<Objeto> */
    private function filhos(Objeto $pai): array
    {
        return array_values(array_filter($this->s->objetos->todosDaSubarvore($pai->siteId, $pai->caminho), static fn (Objeto $o): bool => $o->paiId === $pai->id));
    }

    public function testMigracaoCriaATabela(): void
    {
        self::assertSame(0, $this->s->pilha->contar($this->ids['admin']));
        self::assertContains(23, array_map(static fn (int $v): int => $v, [(int) $this->s->conexao()->fetchOne('SELECT MAX(versao) FROM schema_versao')]));
    }

    public function testSepararCopiarNaoMudaNada(): void
    {
        $a = $this->novo($this->inicio, 'Alfa', 'alfa');
        $r = $this->separar('leitor', 'copiar', [$a]);
        self::assertSame(303, $r->getStatusCode());
        self::assertStringContainsString('ok=pilha_copiar', (string) $r->headers->get('Location'));
        self::assertSame(1, $this->s->pilha->contar($this->ids['leitor']));
        self::assertSame($this->inicio->id, $this->obj($a->id)->paiId);
    }

    public function testRecortarExigeEditarEIgnoraOQueNaoPode(): void
    {
        $dele = $this->novo($this->inicio, 'Do editor', 'do-editor', null, 'publicado', [], $this->ids['editor']);
        $proprio = $this->novo($this->inicio, 'Do autor', 'do-autor', null, 'publicado', [], $this->ids['autor']);
        $this->separar('leitor', 'recortar', [$dele]);
        self::assertSame(0, $this->s->pilha->contar($this->ids['leitor']), 'leitor não recorta');
        $r = $this->separar('autor', 'recortar', [$dele, $proprio]);
        self::assertStringContainsString('f=1', (string) $r->headers->get('Location'));
        $itens = $this->s->pilha->itens($this->ids['autor']);
        self::assertCount(1, $itens);
        self::assertSame($proprio->id, $itens[0]['objeto_id']);
    }

    public function testPostSemCsrfOuSemLoginERecusado(): void
    {
        $a = $this->novo($this->inicio, 'Alfa', 'alfa');
        $t = $this->token('admin');
        $r = $this->req('POST', '/_pbl/pilha/adicionar', $t, ['_csrf' => 'errado', 'modo' => 'copiar', 'ids' => [(string) $a->id]]);
        self::assertSame(403, $r->getStatusCode());
        self::assertSame(0, $this->s->pilha->contar($this->ids['admin']));
        $semLogin = $this->req('POST', '/_pbl/pilha/adicionar', null, ['modo' => 'copiar']);
        self::assertSame('/_pbl/entrar', $semLogin->headers->get('Location'));
        $c = $this->req('POST', '/_pbl/pilha/colar/' . $this->inicio->id, $t, ['_csrf' => 'errado', 'tipo' => 'copia']);
        self::assertSame(403, $c->getStatusCode());
        $e = $this->req('POST', '/_pbl/pilha/esvaziar', $t, ['_csrf' => 'errado']);
        self::assertSame(403, $e->getStatusCode());
    }

    public function testModoOuSiteDiferenteComecaPilhaNova(): void
    {
        $a = $this->novo($this->inicio, 'Alfa', 'alfa');
        $b = $this->novo($this->inicio, 'Beta', 'beta');
        $this->s->pilha->adicionar($this->ids['admin'], Pilha::COPIAR, [$a]);
        $this->s->pilha->adicionar($this->ids['admin'], Pilha::COPIAR, [$b]);
        self::assertSame(2, $this->s->pilha->contar($this->ids['admin']));
        $this->s->pilha->adicionar($this->ids['admin'], Pilha::RECORTAR, [$b]);
        self::assertSame(1, $this->s->pilha->contar($this->ids['admin']));
        self::assertSame(Pilha::RECORTAR, $this->s->pilha->modo($this->ids['admin']));
        $this->s->pilha->adicionar($this->ids['admin'], Pilha::RECORTAR, [$b]);
        self::assertSame(1, $this->s->pilha->contar($this->ids['admin']), 'repetir não duplica');
    }

    public function testLimiteDaPilha(): void
    {
        $pasta = $this->novo($this->inicio, 'Muitos', 'muitos', $this->pasta);
        $objetos = [];
        for ($i = 0; $i < Pilha::LIMITE + 1; $i++) {
            $objetos[] = $this->novo($pasta, 'N' . $i, 'n-' . $i);
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->s->pilha->adicionar($this->ids['admin'], Pilha::COPIAR, $objetos);
    }

    public function testPrevisaoNaoGravaNada(): void
    {
        $a = $this->novo($this->inicio, 'Alfa', 'alfa');
        $dest = $this->novo($this->inicio, 'Destino', 'destino', $this->pasta);
        $this->s->pilha->adicionar($this->ids['admin'], Pilha::COPIAR, [$a]);
        $antes = $this->s->objetos->contarDoSite($a->siteId);
        $r = $this->req('GET', '/_pbl/pilha/colar/' . $dest->id . '?tipo=copia', $this->token('admin'));
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('Será copiado', (string) $r->getContent());
        self::assertStringContainsString('Confirmar e colar', (string) $r->getContent());
        self::assertSame($antes, $this->s->objetos->contarDoSite($a->siteId));
    }

    public function testColarCopiaGeraRascunhoComUrlNovaEPodeRepetir(): void
    {
        $a = $this->novo($this->inicio, 'Alfa', 'alfa', null, 'publicado', ['resumo' => 'um resumo'], $this->ids['admin']);
        $dest = $this->novo($this->inicio, 'Destino', 'destino', $this->pasta);
        $this->s->pilha->adicionar($this->ids['editor'], Pilha::COPIAR, [$a]);
        for ($i = 0; $i < 2; $i++) {
            $r = $this->colar('editor', $dest, 'copia');
            self::assertSame(200, $r->getStatusCode());
        }
        $copias = $this->filhos($dest);
        self::assertCount(2, $copias);
        self::assertSame('Cópia de Alfa', $copias[0]->titulo);
        self::assertSame('privado', $copias[0]->status);
        self::assertSame('um resumo', $copias[0]->dados['resumo']);
        self::assertSame($this->ids['editor'], $copias[0]->criadoPor);
        self::assertNotSame($copias[0]->urlAmigavel, $copias[1]->urlAmigavel);
        self::assertNotSame('alfa', $copias[0]->urlAmigavel);
        self::assertSame(1, $this->s->pilha->contar($this->ids['editor']), 'copiar continua na pilha');
        self::assertSame('publicado', $this->obj($a->id)->status, 'o original não muda');
    }

    public function testCopiaProfundaLevaOsDescendentesMasNaoAsLixeira(): void
    {
        $pai = $this->novo($this->inicio, 'Seção', 'secao', $this->pasta);
        $f1 = $this->novo($pai, 'Filho 1', 'filho-1');
        $this->novo($f1, 'Neto', 'neto');
        $lix = $this->novo($pai, 'Descartado', 'descartado');
        $this->s->objetos->mandarParaLixeira($lix->id, $lix->versao);
        $dest = $this->novo($this->inicio, 'Destino', 'destino', $this->pasta);
        $this->s->pilha->adicionar($this->ids['admin'], Pilha::COPIAR, [$pai]);

        $rasa = $this->colar('admin', $dest, 'copia');
        self::assertSame(200, $rasa->getStatusCode());
        $this->colar('admin', $dest, 'copia', ['profunda' => '1']);
        $copias = $this->filhos($dest);
        self::assertCount(2, $copias);
        self::assertSame([], $this->filhos($copias[0]), 'sem a opção, só o item');
        $filhos = $this->filhos($copias[1]);
        self::assertSame(['Filho 1'], array_map(static fn (Objeto $o): string => $o->titulo, $filhos));
        self::assertSame(['Neto'], array_map(static fn (Objeto $o): string => $o->titulo, $this->filhos($filhos[0])));
        self::assertSame('privado', $this->filhos($filhos[0])[0]->status);
    }

    public function testCopiaReusaOArquivoSemDuplicarOBlob(): void
    {
        $classe = $this->classe('arquivo');
        $orig = $this->s->objetos->criar($this->inicio->siteId, $classe, $this->inicio->id, 'Edital', 'edital', [], 'publicado', [], false, function (int $id): array {
            return ['arquivo' => $this->s->arquivos->criar($this->inicio->siteId, $id, 'arquivo', 'chave-abc', 'edital.pdf', 'pdf', 'application/pdf', 10, str_repeat('a', 64), null, null, false, null)->id];
        });
        $dest = $this->novo($this->inicio, 'Destino', 'destino', $this->pasta);
        $this->s->pilha->adicionar($this->ids['admin'], Pilha::COPIAR, [$orig]);
        $this->colar('admin', $dest, 'copia');
        $copia = $this->filhos($dest)[0];
        $antigo = $this->s->arquivos->porId((int) $orig->dados['arquivo']);
        $novo = $this->s->arquivos->porId((int) $copia->dados['arquivo']);
        self::assertNotNull($antigo);
        self::assertNotNull($novo);
        self::assertNotSame($antigo->id, $novo->id);
        self::assertSame($copia->id, $novo->objetoId, 'o dono da linha nova é a cópia');
        self::assertSame($antigo->chave, $novo->chave, 'o mesmo arquivo em disco');
        self::assertSame(2, $this->s->arquivos->contarPorChave('chave-abc', 'pdf'));
    }

    public function testColarLinkCriaLinkParaOOriginal(): void
    {
        $a = $this->novo($this->inicio, 'Alfa', 'alfa');
        $dest = $this->novo($this->inicio, 'Destino', 'destino', $this->pasta);
        $this->s->pilha->adicionar($this->ids['admin'], Pilha::COPIAR, [$a]);
        $this->colar('admin', $dest, 'link');
        $l = $this->filhos($dest)[0];
        self::assertSame($this->classe('link')->id, $l->classeId);
        self::assertSame($a->id, (int) $l->dados['destino_interno']);
        self::assertSame('privado', $l->status);
    }

    public function testMoverTrocaOPaiELimpaDaPilha(): void
    {
        $a = $this->novo($this->inicio, 'Alfa', 'alfa');
        $dest = $this->novo($this->inicio, 'Destino', 'destino', $this->pasta);
        $this->separar('editor', 'recortar', [$a]);
        $r = $this->colar('editor', $dest, 'mover');
        self::assertSame(200, $r->getStatusCode());
        self::assertSame($dest->id, $this->obj($a->id)->paiId);
        self::assertSame('alfa', $this->obj($a->id)->urlAmigavel);
        self::assertSame(2, $this->obj($a->id)->versao);
        self::assertSame(0, $this->s->pilha->contar($this->ids['editor']));
    }

    public function testMoverRecusaVersaoVelhaEFicaNaPilha(): void
    {
        $a = $this->novo($this->inicio, 'Alfa', 'alfa');
        $dest = $this->novo($this->inicio, 'Destino', 'destino', $this->pasta);
        $this->separar('editor', 'recortar', [$a]);
        $this->s->objetos->atualizar($a->id, ['titulo' => 'Alfa 2'], 'edição', $a->versao);
        $r = $this->colar('editor', $dest, 'mover');
        self::assertStringContainsString('Foi alterado depois de recortado', (string) $r->getContent());
        self::assertSame($this->inicio->id, $this->obj($a->id)->paiId);
        self::assertSame(1, $this->s->pilha->contar($this->ids['editor']));
    }

    public function testNaoColaDentroDeSiMesmoNemDeDescendente(): void
    {
        $pai = $this->novo($this->inicio, 'Pai', 'pai', $this->pasta);
        $filho = $this->novo($pai, 'Filho', 'filho', $this->pasta);
        foreach (['recortar' => 'mover', 'copiar' => 'copia'] as $modo => $tipo) {
            $this->s->pilha->adicionar($this->ids['admin'], $modo, [$pai]);
            foreach ([$pai, $filho] as $destino) {
                $r = $this->colar('admin', $destino, $tipo);
                self::assertStringContainsString('dentro dele mesmo', (string) $r->getContent());
            }
        }
        self::assertSame($this->inicio->id, $this->obj($pai->id)->paiId);
        self::assertCount(0, $this->filhos($filho));
    }

    public function testTipoNaoAceitoNoDestinoEhRecusadoPorItem(): void
    {
        $noticias = $this->novo($this->inicio, 'Notícia', 'noticia-x', $this->classe('noticia'));
        $a = $this->novo($this->inicio, 'Alfa', 'alfa');
        $this->s->pilha->adicionar($this->ids['admin'], Pilha::RECORTAR, [$a]);
        $r = $this->colar('admin', $noticias, 'mover');
        self::assertStringContainsString('não aceita conteúdo do tipo', (string) $r->getContent());
        self::assertSame($this->inicio->id, $this->obj($a->id)->paiId);
        // Link é aceito por notícia: colar o link funciona onde a cópia não funciona.
        $this->s->pilha->adicionar($this->ids['admin'], Pilha::COPIAR, [$a]);
        $this->colar('admin', $noticias, 'copia');
        self::assertCount(0, $this->filhos($noticias));
        $this->colar('admin', $noticias, 'link');
        self::assertCount(1, $this->filhos($noticias));
    }

    public function testSemPermissaoDeCriarNoDestinoNaoCola(): void
    {
        $a = $this->novo($this->inicio, 'Alfa', 'alfa');
        $dest = $this->novo($this->inicio, 'Destino', 'destino', $this->pasta);
        $this->s->pilha->adicionar($this->ids['leitor'], Pilha::COPIAR, [$a]);
        $r = $this->colar('leitor', $dest, 'copia');
        self::assertSame(403, $r->getStatusCode());
        self::assertCount(0, $this->filhos($dest));
    }

    public function testJeitoDeColarPrecisaCombinarComOModoDaPilha(): void
    {
        $a = $this->novo($this->inicio, 'Alfa', 'alfa');
        $dest = $this->novo($this->inicio, 'Destino', 'destino', $this->pasta);
        $this->s->pilha->adicionar($this->ids['admin'], Pilha::COPIAR, [$a]);
        $colagem = new Colagem($this->s);
        $usuario = $this->s->usuarios->porId($this->ids['admin']);
        self::assertNotNull($usuario);
        $linhas = $colagem->prever($usuario, $dest, Colagem::MOVER, false);
        self::assertFalse($linhas[0]['ok']);
        self::assertSame($this->inicio->id, $this->obj($a->id)->paiId);
    }

    public function testLimiteDeObjetosPorColagem(): void
    {
        $origem = $this->novo($this->inicio, 'Grande', 'grande', $this->pasta);
        for ($i = 0; $i < Colagem::LIMITE_TOTAL; $i++) {
            $this->novo($origem, 'F' . $i, 'f-' . $i);
        }
        $dest = $this->novo($this->inicio, 'Destino', 'destino', $this->pasta);
        $this->s->pilha->adicionar($this->ids['admin'], Pilha::COPIAR, [$origem]);
        $r = $this->colar('admin', $dest, 'copia', ['profunda' => '1']);
        self::assertStringContainsString('limite de ' . Colagem::LIMITE_TOTAL, (string) $r->getContent());
        self::assertCount(0, $this->filhos($dest));
    }

    public function testTelasDeConteudoMostramBotoesEFaixa(): void
    {
        $this->novo($this->inicio, 'Alfa', 'alfa');
        $t = $this->token('leitor');
        $html = (string) $this->req('GET', '/_pbl/conteudo/' . $this->inicio->id, $t)->getContent();
        self::assertStringContainsString('Copiar para a pilha', $html);
        self::assertStringNotContainsString('Recortar para a pilha', $html);
        self::assertStringNotContainsString('Colar aqui', $html);
        $t = $this->token('editor');
        $html = (string) $this->req('GET', '/_pbl/conteudo/' . $this->inicio->id, $t)->getContent();
        self::assertStringContainsString('Recortar para a pilha', $html);
        $a = $this->obj((int) $this->s->objetos->porUrl($this->inicio->siteId, 'alfa')?->id);
        $this->separar('editor', 'copiar', [$a]);
        $html = (string) $this->req('GET', '/_pbl/conteudo/' . $this->inicio->id, $t)->getContent();
        self::assertStringContainsString('Colar aqui', $html);
        self::assertStringContainsString('1 item copiado na pilha', $html);
        $tela = (string) $this->req('GET', '/_pbl/objeto/' . $a->id, $t)->getContent();
        self::assertStringContainsString('Copiar para a pilha', $tela);
        self::assertSame(200, $this->req('GET', '/_pbl/pilha', $t)->getStatusCode());
    }

    public function testEsvaziarERemover(): void
    {
        $a = $this->novo($this->inicio, 'Alfa', 'alfa');
        $b = $this->novo($this->inicio, 'Beta', 'beta');
        $this->s->pilha->adicionar($this->ids['admin'], Pilha::COPIAR, [$a, $b]);
        $t = $this->token('admin');
        $this->req('POST', '/_pbl/pilha/remover', $t, ['_csrf' => $this->csrf($t), 'id' => (string) $a->id]);
        self::assertSame(1, $this->s->pilha->contar($this->ids['admin']));
        $this->req('POST', '/_pbl/pilha/esvaziar', $t, ['_csrf' => $this->csrf($t)]);
        self::assertSame(0, $this->s->pilha->contar($this->ids['admin']));
    }
}
