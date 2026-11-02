<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\SessaoRepositorio;
use Pbl\Aplicacao;
use Pbl\Caminhos;
use Pbl\Conteudo\Classe;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\Site;
use Pbl\Instalacao\Instalador;
use Pbl\Linguagem\Compilador;
use Pbl\Linguagem\Runtime;
use Pbl\Publicacao\FonteRepositorio;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Upload pela tela de editar objeto e entrega pública do arquivo (fase 7a, ADR 0014): ponta a ponta, pelo `Aplicacao`,
 * como os demais testes de administração (`AdminObjetosTest`).
 */
final class AdminUploadTest extends TestCase
{
    private const SENHA = 'uma senha bem longa 2026';
    private const ORIGEM = ['HTTP_ORIGIN' => 'https://www.exemplo.org'];

    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;
    private Site $site;
    private Classe $album;
    private Objeto $sobre;
    private int $adminId;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Meu Site', 'www.exemplo.org');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $this->site = $this->s->sites->porHost('www.exemplo.org') ?? self::fail();
        $this->sobre = $this->s->objetos->porId((int) $this->s->objetos->porUrl($this->site->id, 'sobre')?->id) ?? self::fail();
        $this->album = $this->s->classes->criar('album', [
            ['nome' => 'capa', 'tipo' => 'imagem', 'indexavel' => false],
            ['nome' => 'anexo', 'tipo' => 'arquivo', 'indexavel' => false],
        ]);
        $this->adminId = $this->s->usuarios->criar('admin', 'admin@exemplo.org', 'Admin', self::SENHA, true)->id;
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function token(): string
    {
        return $this->s->sessoes()->criar($this->adminId);
    }

    private function csrf(string $token): string
    {
        return $this->s->csrf()->daSessao(SessaoRepositorio::hash($token));
    }

    private function pngValido(): string
    {
        $img = imagecreatetruecolor(8, 6);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    private function pdfValido(): string
    {
        return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
    }

    private function arquivoTemporario(string $conteudo): string
    {
        $caminho = (string) tempnam(sys_get_temp_dir(), 'pbl4up');
        file_put_contents($caminho, $conteudo);

        return $caminho;
    }

    /**
     * @param array<string, mixed> $campos
     * @param array<string, mixed> $arquivos
     */
    private function postEditar(int $id, array $campos, array $arquivos = []): Response
    {
        $token = $this->token();
        $atual = $this->s->objetos->porId($id);
        $campos += ['_csrf' => $this->csrf($token), 'versao' => (string) ($atual !== null ? $atual->versao : 1)];
        $r = Request::create('https://www.exemplo.org/_pbl/objeto/' . $id . '/editar', 'POST', $campos, ['__Host-pbl4' => $token], $arquivos, self::ORIGEM + ['REMOTE_ADDR' => '198.51.100.7']);

        return (new Aplicacao($this->caminhos))->tratar($r);
    }

    private function criarAlbum(string $titulo = 'Meu álbum'): Objeto
    {
        return $this->s->objetos->criarDentro($this->site->id, $this->album, $this->sobre->id, $titulo, 'album-' . bin2hex(random_bytes(3)), []);
    }

    /**
     * @param array<string, mixed> $campos
     * @param array<string, mixed> $arquivos
     */
    private function postCriar(int $paiId, array $campos, array $arquivos = []): Response
    {
        $token = $this->token();
        $campos += ['_csrf' => $this->csrf($token)];
        $r = Request::create('https://www.exemplo.org/_pbl/objeto/' . $paiId . '/novo', 'POST', $campos, ['__Host-pbl4' => $token], $arquivos, self::ORIGEM + ['REMOTE_ADDR' => '198.51.100.7']);

        return (new Aplicacao($this->caminhos))->tratar($r);
    }

    /** @param array<string, string> $cabecalhos */
    private function getPublico(string $caminho, array $cabecalhos = []): Response
    {
        return (new Aplicacao($this->caminhos))->tratar(Request::create('https://www.exemplo.org' . $caminho, 'GET', [], [], [], $cabecalhos));
    }

    // ---------------------------------------------------------------- envio pela tela de editar

    public function testEnviarImagemPelaTelaDeEditarGravaOIdDoArquivoNaPropriedade(): void
    {
        $o = $this->criarAlbum();
        $arquivo = new UploadedFile($this->arquivoTemporario($this->pngValido()), 'capa.png', 'image/png', null, true);
        $r = $this->postEditar($o->id, ['titulo' => $o->titulo, 'descricao' => '', 'peso' => '0'], ['dados' => ['capa' => $arquivo]]);
        self::assertSame(303, $r->getStatusCode(), (string) $r->getContent());

        $novo = $this->s->objetos->porId($o->id) ?? self::fail();
        self::assertArrayHasKey('capa', $novo->dados);
        $blob = $this->s->arquivos->porId((int) $novo->dados['capa']);
        self::assertNotNull($blob);
        self::assertSame('capa.png', $blob->nome);
        self::assertSame($o->id, $blob->objetoId);
    }

    public function testExtensaoFalsaNaEdicaoDa422ENaoGravaNada(): void
    {
        $o = $this->criarAlbum();
        $arquivo = new UploadedFile($this->arquivoTemporario("não é imagem de verdade"), 'capa.png', 'image/png', null, true);
        $r = $this->postEditar($o->id, ['titulo' => $o->titulo, 'descricao' => '', 'peso' => '0'], ['dados' => ['capa' => $arquivo]]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('não permitido', (string) $r->getContent());
        self::assertArrayNotHasKey('capa', ($this->s->objetos->porId($o->id) ?? self::fail())->dados);
    }

    public function testTamanhoAcimaDoLimiteDa422(): void
    {
        $classeComLimite = $this->s->classes->criar('album_pequeno', [
            ['nome' => 'capa', 'tipo' => 'imagem', 'tamanho_max_mb' => 1],
        ]);
        $o = $this->s->objetos->criarDentro($this->site->id, $classeComLimite, $this->sobre->id, 'Pequeno', 'pequeno-1', []);
        $arquivo = new UploadedFile($this->arquivoTemporario(str_repeat('a', 2 * 1024 * 1024)), 'capa.png', 'image/png', null, true);
        $r = $this->postEditar($o->id, ['titulo' => $o->titulo, 'descricao' => '', 'peso' => '0'], ['dados' => ['capa' => $arquivo]]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('MB', (string) $r->getContent());
    }

    public function testRemoverArquivoAtualLimpaAPropriedade(): void
    {
        $o = $this->criarAlbum();
        $arquivo = new UploadedFile($this->arquivoTemporario($this->pngValido()), 'capa.png', 'image/png', null, true);
        $this->postEditar($o->id, ['titulo' => $o->titulo, 'descricao' => '', 'peso' => '0'], ['dados' => ['capa' => $arquivo]]);
        $comArquivo = $this->s->objetos->porId($o->id) ?? self::fail();
        self::assertArrayHasKey('capa', $comArquivo->dados);

        $r = $this->postEditar($o->id, ['titulo' => $comArquivo->titulo, 'descricao' => '', 'peso' => '0', 'dados_remover' => ['capa' => '1']]);
        self::assertSame(303, $r->getStatusCode());
        $semArquivo = $this->s->objetos->porId($o->id) ?? self::fail();
        self::assertArrayNotHasKey('capa', $semArquivo->dados);
    }

    public function testNaoEnviarNadaMantemOArquivoAtual(): void
    {
        $o = $this->criarAlbum();
        $arquivo = new UploadedFile($this->arquivoTemporario($this->pngValido()), 'capa.png', 'image/png', null, true);
        $this->postEditar($o->id, ['titulo' => $o->titulo, 'descricao' => '', 'peso' => '0'], ['dados' => ['capa' => $arquivo]]);
        $comArquivo = $this->s->objetos->porId($o->id) ?? self::fail();
        $idAntigo = $comArquivo->dados['capa'];

        $r = $this->postEditar($o->id, ['titulo' => 'Outro título', 'descricao' => '', 'peso' => '0']);
        self::assertSame(303, $r->getStatusCode());
        $depois = $this->s->objetos->porId($o->id) ?? self::fail();
        self::assertSame($idAntigo, $depois->dados['capa']);
    }

    // ---------------------------------------------------------------- envio na criação (fase 7b, ADR 0014 §4)

    public function testCriarComArquivoGravaOObjetoEOArquivoNaMesmaSubmissao(): void
    {
        $arquivo = new UploadedFile($this->arquivoTemporario($this->pngValido()), 'capa.png', 'image/png', null, true);
        $r = $this->postCriar($this->sobre->id, [
            'classe' => (string) $this->album->id, 'titulo' => 'Álbum novo', 'descricao' => '', 'peso' => '0', 'url' => 'album-novo',
        ], ['dados' => ['capa' => $arquivo]]);
        self::assertSame(303, $r->getStatusCode(), (string) $r->getContent());

        $novo = $this->s->objetos->porUrl($this->site->id, 'album-novo') ?? self::fail();
        self::assertArrayHasKey('capa', $novo->dados);
        $blob = $this->s->arquivos->porId((int) $novo->dados['capa']);
        self::assertNotNull($blob);
        self::assertSame('capa.png', $blob->nome);
        self::assertSame($novo->id, $blob->objetoId, 'o dono do arquivo é o objeto recém-criado');
    }

    public function testCriarComArquivoInvalidoNaoCriaNadaAtomicamente(): void
    {
        $arquivo = new UploadedFile($this->arquivoTemporario('não é imagem de verdade'), 'capa.png', 'image/png', null, true);
        $r = $this->postCriar($this->sobre->id, [
            'classe' => (string) $this->album->id, 'titulo' => 'Álbum quebrado', 'descricao' => '', 'peso' => '0', 'url' => 'album-quebrado',
        ], ['dados' => ['capa' => $arquivo]]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('não permitido', (string) $r->getContent());
        // Nada ficou pela metade: nem o objeto, nem a linha de arquivo (o upload falhou antes de qualquer insert).
        self::assertNull($this->s->objetos->porUrl($this->site->id, 'album-quebrado'));
    }

    public function testCriarSemArquivoNumCampoObrigatorioDa422ENaoCriaNada(): void
    {
        $classe = $this->s->classes->criar('album_obrigatorio', [
            ['nome' => 'capa', 'tipo' => 'imagem', 'obrigatorio' => true],
        ]);
        $r = $this->postCriar($this->sobre->id, [
            'classe' => (string) $classe->id, 'titulo' => 'Sem capa', 'descricao' => '', 'peso' => '0', 'url' => 'sem-capa',
        ]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('obrigatório', (string) $r->getContent());
        self::assertNull($this->s->objetos->porUrl($this->site->id, 'sem-capa'));
    }

    public function testCriarSemArquivoEmCampoOpcionalFuncionaNormalmente(): void
    {
        $r = $this->postCriar($this->sobre->id, [
            'classe' => (string) $this->album->id, 'titulo' => 'Álbum sem capa', 'descricao' => '', 'peso' => '0', 'url' => 'album-sem-capa',
        ]);
        self::assertSame(303, $r->getStatusCode(), (string) $r->getContent());
        $novo = $this->s->objetos->porUrl($this->site->id, 'album-sem-capa') ?? self::fail();
        self::assertArrayNotHasKey('capa', $novo->dados);
    }

    public function testDocumentoNaPropriedadeImagemERecusadoPeloRepositorio(): void
    {
        // Defesa em profundidade: um id de arquivo da categoria errada (documento numa propriedade `imagem`) não é aceito
        // nem direto pelo repositório, mesmo que a tela normalmente nunca produza essa combinação.
        $o = $this->criarAlbum();
        $doc = $this->s->uploads()->enviar(new UploadedFile($this->arquivoTemporario($this->pdfValido()), 'x.pdf', 'application/pdf', null, true), $this->album->propriedades[1], $this->site->id, $o->id, null);
        $this->expectException(\InvalidArgumentException::class);
        $this->s->objetos->atualizar($o->id, ['dados' => ['capa' => $doc->id]]);
    }

    // ---------------------------------------------------------------- entrega pública

    public function testArquivoDeObjetoPrivadoDa404(): void
    {
        $o = $this->criarAlbum(); // nasce privado
        $arquivo = $this->s->uploads()->enviar(new UploadedFile($this->arquivoTemporario($this->pngValido()), 'capa.png', 'image/png', null, true), $this->album->propriedades[0], $this->site->id, $o->id, null);
        $r = $this->getPublico('/_pbl/arquivo/' . $arquivo->id);
        self::assertSame(404, $r->getStatusCode());
    }

    public function testArquivoDeObjetoPublicadoEntregaComCabecalhosSeguros(): void
    {
        $o = $this->criarAlbum();
        $this->s->objetos->atualizar($o->id, ['status' => 'publicado']);
        $arquivo = $this->s->uploads()->enviar(new UploadedFile($this->arquivoTemporario($this->pngValido()), 'capa bonita.png', 'image/png', null, true), $this->album->propriedades[0], $this->site->id, $o->id, null);

        $r = $this->getPublico('/_pbl/arquivo/' . $arquivo->id);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('image/png', $r->headers->get('Content-Type'));
        self::assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('inline', (string) $r->headers->get('Content-Disposition'));
        self::assertNotNull($r->headers->get('ETag'));
        self::assertStringContainsString('immutable', (string) $r->headers->get('Cache-Control'));
    }

    public function testArquivoDocumentoEntregaComContentDispositionAttachment(): void
    {
        $o = $this->criarAlbum();
        $this->s->objetos->atualizar($o->id, ['status' => 'publicado']);
        $arquivo = $this->s->uploads()->enviar(new UploadedFile($this->arquivoTemporario("conteudo texto\n"), 'notas.txt', 'text/plain', null, true), $this->album->propriedades[1], $this->site->id, $o->id, null);

        $r = $this->getPublico('/_pbl/arquivo/' . $arquivo->id);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('attachment', (string) $r->headers->get('Content-Disposition'));
    }

    public function testRangeDeUmTrechoEhAtendido(): void
    {
        $o = $this->criarAlbum();
        $this->s->objetos->atualizar($o->id, ['status' => 'publicado']);
        $conteudo = $this->pngValido();
        $arquivo = $this->s->uploads()->enviar(new UploadedFile($this->arquivoTemporario($conteudo), 'capa.png', 'image/png', null, true), $this->album->propriedades[0], $this->site->id, $o->id, null);

        $r = $this->getPublico('/_pbl/arquivo/' . $arquivo->id, ['HTTP_RANGE' => 'bytes=0-2']);
        self::assertSame(206, $r->getStatusCode());
        self::assertSame('3', $r->headers->get('Content-Length'));
    }

    public function testArquivoInexistenteEDeOutroSiteDao404(): void
    {
        self::assertSame(404, $this->getPublico('/_pbl/arquivo/999999')->getStatusCode());

        $outroSite = $this->s->sites->criar('Outro', 'outro.exemplo.org', 'basica');
        $raizOutro = $this->s->objetos->criar($outroSite->id, $this->album, null, 'Início', '', [], 'publicado');
        $this->s->sites->definirRaiz($outroSite->id, $raizOutro->id);
        $arquivo = $this->s->uploads()->enviar(new UploadedFile($this->arquivoTemporario($this->pngValido()), 'x.png', 'image/png', null, true), $this->album->propriedades[0], $outroSite->id, $raizOutro->id, null);

        // Pedido no domínio de www.exemplo.org por um arquivo que pertence a outro site: 404 (isolamento entre sites).
        self::assertSame(404, $this->getPublico('/_pbl/arquivo/' . $arquivo->id)->getStatusCode());
    }

    // ---------------------------------------------------------------- miniaturas

    public function testMiniaturaDeImagemPublicadaFunciona(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('sem GD');
        }
        $o = $this->criarAlbum();
        $this->s->objetos->atualizar($o->id, ['status' => 'publicado']);
        $arquivo = $this->s->uploads()->enviar(new UploadedFile($this->arquivoTemporario($this->pngValido()), 'capa.png', 'image/png', null, true), $this->album->propriedades[0], $this->site->id, $o->id, null);

        $r = $this->getPublico('/_pbl/arquivo/' . $arquivo->id . '/miniatura/64');
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('image/jpeg', $r->headers->get('Content-Type'));
    }

    public function testMiniaturaDeTamanhoForaDaListaDa404(): void
    {
        $o = $this->criarAlbum();
        $this->s->objetos->atualizar($o->id, ['status' => 'publicado']);
        $arquivo = $this->s->uploads()->enviar(new UploadedFile($this->arquivoTemporario($this->pngValido()), 'capa.png', 'image/png', null, true), $this->album->propriedades[0], $this->site->id, $o->id, null);

        self::assertSame(404, $this->getPublico('/_pbl/arquivo/' . $arquivo->id . '/miniatura/12345')->getStatusCode());
    }

    public function testMiniaturaDeDocumentoDa404(): void
    {
        $o = $this->criarAlbum();
        $this->s->objetos->atualizar($o->id, ['status' => 'publicado']);
        $doc = $this->s->uploads()->enviar(new UploadedFile($this->arquivoTemporario($this->pdfValido()), 'x.pdf', 'application/pdf', null, true), $this->album->propriedades[1], $this->site->id, $o->id, null);

        self::assertSame(404, $this->getPublico('/_pbl/arquivo/' . $doc->id . '/miniatura/64')->getStatusCode());
    }

    // ---------------------------------------------------------------- comandos de molde (fase 7b, ADR 0014 §4.2)

    /** `FonteRepositorio` de verdade, ligada ao `ArquivoRepositorio` dos Servicos, como o `Renderizador` usa de fato. */
    private function fonteDeDados(): FonteRepositorio
    {
        return new FonteRepositorio($this->s->objetos, $this->s->classes, $this->s->motor, null, null, $this->s->arquivos);
    }

    private function renderMolde(string $molde, Objeto $contexto): string
    {
        $fonte = $this->fonteDeDados();
        $compilador = new Compilador($this->caminhos->cachePbl());

        return ($compilador->carregar($molde))($fonte->contextoDe($contexto), new Runtime($fonte));
    }

    public function testComandoArquivoNaoExpoeUrlDeObjetoPrivado(): void
    {
        $o = $this->criarAlbum(); // nasce privado
        $arquivo = $this->s->uploads()->enviar(new UploadedFile($this->arquivoTemporario($this->pngValido()), 'capa.png', 'image/png', null, true), $this->album->propriedades[0], $this->site->id, $o->id, null);

        self::assertSame('', $this->renderMolde('<@arquivo valor=' . $arquivo->id . ' info="url"@>', $o));
        self::assertSame('', $this->renderMolde('<@arquivo valor=' . $arquivo->id . ' info="nome"@>', $o));
        self::assertSame('', $this->renderMolde('<@miniatura valor=' . $arquivo->id . ' tamanho=150@>', $o), 'sem visibilidade, nem a miniatura aparece');
    }

    public function testComandoArquivoEMiniaturaParaObjetoPublicado(): void
    {
        $o = $this->criarAlbum();
        $this->s->objetos->atualizar($o->id, ['status' => 'publicado']);
        $arquivo = $this->s->uploads()->enviar(new UploadedFile($this->arquivoTemporario($this->pngValido()), 'capa.png', 'image/png', null, true), $this->album->propriedades[0], $this->site->id, $o->id, null);

        self::assertSame('/_pbl/arquivo/' . $arquivo->id, $this->renderMolde('<@arquivo valor=' . $arquivo->id . ' info="url"@>', $o));
        self::assertSame('capa.png', $this->renderMolde('<@arquivo valor=' . $arquivo->id . ' info="nome"@>', $o));
        self::assertSame('png', $this->renderMolde('<@arquivo valor=' . $arquivo->id . ' info="tipo"@>', $o));
        self::assertSame('/_pbl/arquivo/' . $arquivo->id . '/miniatura/150', $this->renderMolde('<@miniatura valor=' . $arquivo->id . ' tamanho=150@>', $o));
    }

    public function testComandoMiniaturaComTamanhoForaDaWhitelistEDocumentoDaoVazio(): void
    {
        $o = $this->criarAlbum();
        $this->s->objetos->atualizar($o->id, ['status' => 'publicado']);
        $imagem = $this->s->uploads()->enviar(new UploadedFile($this->arquivoTemporario($this->pngValido()), 'capa.png', 'image/png', null, true), $this->album->propriedades[0], $this->site->id, $o->id, null);
        $doc = $this->s->uploads()->enviar(new UploadedFile($this->arquivoTemporario($this->pdfValido()), 'x.pdf', 'application/pdf', null, true), $this->album->propriedades[1], $this->site->id, $o->id, null);

        self::assertSame('', $this->renderMolde('<@miniatura valor=' . $imagem->id . ' tamanho=999@>', $o), 'tamanho fora da whitelist');
        self::assertSame('', $this->renderMolde('<@miniatura valor=' . $doc->id . ' tamanho=150@>', $o), 'documento não é imagem, não tem miniatura');
    }

    public function testComandoArquivoDeOutroSiteNaoAparece(): void
    {
        $o = $this->criarAlbum();
        $this->s->objetos->atualizar($o->id, ['status' => 'publicado']);
        $outroSite = $this->s->sites->criar('Outro', 'outro.exemplo.org', 'basica');
        $raizOutro = $this->s->objetos->criar($outroSite->id, $this->album, null, 'Início', '', [], 'publicado');
        $this->s->sites->definirRaiz($outroSite->id, $raizOutro->id);
        $arquivoDeOutroSite = $this->s->uploads()->enviar(new UploadedFile($this->arquivoTemporario($this->pngValido()), 'x.png', 'image/png', null, true), $this->album->propriedades[0], $outroSite->id, $raizOutro->id, null);

        self::assertSame('', $this->renderMolde('<@arquivo valor=' . $arquivoDeOutroSite->id . ' info="url"@>', $o));
    }
}
