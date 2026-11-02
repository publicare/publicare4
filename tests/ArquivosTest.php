<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Arquivos\ArmazenamentoDeArquivos;
use Pbl\Arquivos\Arquivo;
use Pbl\Arquivos\ArquivoRepositorio;
use Pbl\Arquivos\ErroDeUpload;
use Pbl\Arquivos\LimpadorDeUploads;
use Pbl\Arquivos\ServicoDeMiniaturas;
use Pbl\Arquivos\ServicoDeUpload;
use Pbl\Arquivos\TiposDeArquivo;
use Pbl\Caminhos;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Upload de arquivos (fase 7a, ADR 0014): whitelist pelo conteúdo real (nunca pela extensão do nome), tamanho,
 * recodificação/validação de imagem, armazenamento com chave aleatória e limpeza de órfãos.
 */
final class ArquivosTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Meu Site', 'www.exemplo.org');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function arquivoTemporario(string $conteudo, string $sufixo = ''): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'pbl4up') . $sufixo;
        file_put_contents($caminho, $conteudo);

        return $caminho;
    }

    /**
     * @param int<1, max> $largura
     * @param int<1, max> $altura
     */
    private function jpegValido(int $largura = 20, int $altura = 10): string
    {
        $img = imagecreatetruecolor($largura, $altura);
        $cor = imagecolorallocate($img, 10, 20, 30);
        imagefill($img, 0, 0, $cor === false ? 0 : $cor);
        ob_start();
        imagejpeg($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    private function pngValido(): string
    {
        $img = imagecreatetruecolor(5, 5);
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

    /** @return array{nome: string, tipo: string, tipos?: list<string>, tamanho_max_mb?: int} */
    private function propriedadeImagem(): array
    {
        return ['nome' => 'foto', 'tipo' => 'imagem'];
    }

    /** @return array{nome: string, tipo: string, tipos?: list<string>, tamanho_max_mb?: int} */
    private function propriedadeArquivo(): array
    {
        return ['nome' => 'anexo', 'tipo' => 'arquivo'];
    }

    // ---------------------------------------------------------------- TiposDeArquivo

    public function testWhitelistSeparaImagemDeDocumentoENaoMisturaAsDuas(): void
    {
        self::assertSame(['jpg', 'png', 'gif', 'webp'], TiposDeArquivo::imagens());
        self::assertNotContains('jpg', TiposDeArquivo::documentos());
        self::assertNotContains('pdf', TiposDeArquivo::imagens());
    }

    public function testAceitasPorRespeitaSubconjuntoConfiguradoESoDentroDaCategoria(): void
    {
        self::assertSame(['jpg', 'png'], TiposDeArquivo::aceitasPor(['tipo' => 'imagem', 'tipos' => ['jpg', 'png']]));
        // "pdf" não é imagem: um subconjunto inválido para a categoria é ignorado (fica de fora, nunca extrapola a categoria).
        self::assertSame([], TiposDeArquivo::aceitasPor(['tipo' => 'imagem', 'tipos' => ['pdf']]));
    }

    public function testLimiteBytesUsaPadraoETeto(): void
    {
        self::assertSame(TiposDeArquivo::PADRAO_MB * 1024 * 1024, TiposDeArquivo::limiteBytes(['tipo' => 'imagem']));
        self::assertSame(5 * 1024 * 1024, TiposDeArquivo::limiteBytes(['tipo' => 'imagem', 'tamanho_max_mb' => 5]));
        self::assertSame(TiposDeArquivo::TETO_MB * 1024 * 1024, TiposDeArquivo::limiteBytes(['tipo' => 'imagem', 'tamanho_max_mb' => 99999]));
    }

    // ---------------------------------------------------------------- ArmazenamentoDeArquivos

    public function testChaveInvalidaNuncaViraCaminhoDeArquivo(): void
    {
        $arm = new ArmazenamentoDeArquivos($this->caminhos);
        foreach (['../../etc/passwd', '../x', 'a/b', '', 'ABCDEF0123456789abcdef0123456789zz', str_repeat('a', 31)] as $chaveRuim) {
            try {
                $arm->caminho($chaveRuim, 'jpg');
                self::fail('deveria recusar: ' . $chaveRuim);
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Chave de arquivo inválida', $e->getMessage());
            }
        }
    }

    public function testGravarELerSaoAtomicosEIdempotentes(): void
    {
        $arm = new ArmazenamentoDeArquivos($this->caminhos);
        $chave = ArmazenamentoDeArquivos::novaChave();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $chave);
        $caminho = $arm->gravar($chave, 'txt', 'conteudo');
        self::assertSame('conteudo', file_get_contents($caminho));
        self::assertTrue($arm->existe($chave, 'txt'));
        self::assertSame(8, $arm->tamanho($chave, 'txt'));
        // Gravar de novo (mesma chave) não falha: dedupe não sobrescreve.
        $arm->gravar($chave, 'txt', 'outra coisa');
        self::assertSame('conteudo', file_get_contents($caminho));
        $arm->remover($chave, 'txt');
        self::assertFalse($arm->existe($chave, 'txt'));
    }

    // ---------------------------------------------------------------- ServicoDeUpload: imagem válida

    public function testEnviaImagemValidaERecodificaComGd(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('sem GD');
        }
        $servico = $this->s->uploads();
        $arquivo = $servico->enviar(new UploadedFile($this->arquivoTemporario($this->jpegValido(30, 15)), 'foto de verdade.jpg', 'image/jpeg', null, true), $this->propriedadeImagem(), 1, 1, null);
        self::assertSame('jpg', $arquivo->extensao);
        self::assertSame('image/jpeg', $arquivo->mime);
        self::assertSame(30, $arquivo->largura);
        self::assertSame(15, $arquivo->altura);
        self::assertTrue($arquivo->sanitizada);
        self::assertTrue($arquivo->ehImagem());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $arquivo->sha256);
    }

    public function testNomeOriginalESaneadoENuncaViraCaminho(): void
    {
        $servico = $this->s->uploads();
        $arquivo = $servico->enviar(new UploadedFile($this->arquivoTemporario($this->pdfValido()), '../../../etc/cron.d/malicioso.pdf', 'application/pdf', null, true), $this->propriedadeArquivo(), 1, 1, null);
        self::assertSame('malicioso.pdf', $arquivo->nome);
        self::assertStringNotContainsString('/', $arquivo->nome);
        self::assertStringNotContainsString('..', $arquivo->nome);
    }

    // ---------------------------------------------------------------- tipo falso pela extensão

    public function testExtensaoMentirosaERecusadaPeloConteudoReal(): void
    {
        $servico = $this->s->uploads();
        // Texto puro enviado como se fosse "foto.jpg": o finfo não acha image/jpeg no conteúdo.
        $caminho = $this->arquivoTemporario("isto não é uma imagem, é só texto\n");
        try {
            $servico->enviar(new UploadedFile($caminho, 'foto.jpg', 'image/jpeg', null, true), $this->propriedadeImagem(), 1, 1, null);
            self::fail('deveria recusar');
        } catch (ErroDeUpload $e) {
            self::assertStringContainsString('Tipo de arquivo não permitido', $e->getMessage());
        }
    }

    public function testExecutavelComExtensaoDeImagemERecusado(): void
    {
        $servico = $this->s->uploads();
        $caminho = $this->arquivoTemporario("<?php system(\$_GET['c']); ?>\n");
        try {
            $servico->enviar(new UploadedFile($caminho, 'webshell.jpg', 'image/jpeg', null, true), $this->propriedadeImagem(), 1, 1, null);
            self::fail('deveria recusar');
        } catch (ErroDeUpload $e) {
            self::assertStringContainsString('não permitido', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- polyglot

    public function testPolyglotComCabecalhoDeGifERecusadoOuNeutralizado(): void
    {
        $servico = $this->s->uploads();
        // Começa com a assinatura de GIF, mas não é um GIF de verdade: nem o finfo (provavelmente) nem o GD aceitam.
        $conteudo = "GIF89a<script>alert(document.domain)</script>" . str_repeat('A', 200);
        $caminho = $this->arquivoTemporario($conteudo);
        try {
            $arquivo = $servico->enviar(new UploadedFile($caminho, 'polyglot.gif', 'image/gif', null, true), $this->propriedadeImagem(), 1, 1, null);
            // Se por algum motivo passou pela whitelist de MIME, o GD precisa ter recusado decodificar como imagem de verdade,
            // e o conteúdo gravado nunca pode ser os bytes originais com o script dentro.
            $gravado = (string) file_get_contents((new ArmazenamentoDeArquivos($this->caminhos))->caminho($arquivo->chave, $arquivo->extensao));
            self::assertStringNotContainsString('<script>', $gravado);
        } catch (ErroDeUpload $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testPolyglotPdfComHtmlAposOCabecalhoContinuaSendoEntreguePdf(): void
    {
        $servico = $this->s->uploads();
        // PDF não é recodificado: o conteúdo entregue é o mesmo enviado, mas o Content-Type é sempre fixo (application/pdf)
        // e a entrega usa nosniff + Content-Disposition — o navegador nunca interpreta isto como HTML (ver AdminUploadTest).
        $conteudo = "%PDF-1.4\n<html><script>alert(1)</script></html>\n%%EOF";
        $arquivo = $servico->enviar(new UploadedFile($this->arquivoTemporario($conteudo), 'relatorio.pdf', 'application/pdf', null, true), $this->propriedadeArquivo(), 1, 1, null);
        self::assertSame('pdf', $arquivo->extensao);
        self::assertSame('application/pdf', $arquivo->mime);
    }

    // ---------------------------------------------------------------- tamanho

    public function testTamanhoAcimaDoLimiteDaPropriedadeERecusadoComMensagemClara(): void
    {
        $servico = $this->s->uploads();
        $propriedade = ['nome' => 'foto', 'tipo' => 'imagem', 'tamanho_max_mb' => 1];
        $grande = str_repeat('a', 2 * 1024 * 1024); // 2 MB, mime não importa: o tamanho é checado antes do finfo
        try {
            $servico->enviar(new UploadedFile($this->arquivoTemporario($grande), 'foto.jpg', 'image/jpeg', null, true), $propriedade, 1, 1, null);
            self::fail('deveria recusar');
        } catch (ErroDeUpload $e) {
            self::assertStringContainsString('MB', $e->getMessage());
        }
    }

    public function testArquivoVazioERecusado(): void
    {
        $servico = $this->s->uploads();
        try {
            $servico->enviar(new UploadedFile($this->arquivoTemporario(''), 'vazio.pdf', 'application/pdf', null, true), $this->propriedadeArquivo(), 1, 1, null);
            self::fail('deveria recusar');
        } catch (ErroDeUpload $e) {
            self::assertStringContainsString('vazio', $e->getMessage());
        }
    }

    public function testErroDoPhpViraMensagemClara(): void
    {
        $servico = $this->s->uploads();
        $arquivo = new UploadedFile($this->arquivoTemporario('x'), 'foto.jpg', 'image/jpeg', \UPLOAD_ERR_INI_SIZE, true);
        try {
            $servico->enviar($arquivo, $this->propriedadeImagem(), 1, 1, null);
            self::fail('deveria recusar');
        } catch (ErroDeUpload $e) {
            self::assertStringContainsString('upload_max_filesize', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- dedup

    public function testMesmoConteudoReaproveitaOArquivoEmDisco(): void
    {
        $servico = $this->s->uploads();
        $conteudo = $this->pngValido();
        $a = $servico->enviar(new UploadedFile($this->arquivoTemporario($conteudo), 'um.png', 'image/png', null, true), $this->propriedadeImagem(), 1, 1, null);
        $b = $servico->enviar(new UploadedFile($this->arquivoTemporario($conteudo), 'dois.png', 'image/png', null, true), $this->propriedadeImagem(), 1, 1, null);
        self::assertSame($a->chave, $b->chave);
        self::assertNotSame($a->id, $b->id);
    }

    // ---------------------------------------------------------------- miniaturas

    public function testMiniaturaGeraArquivoMenorECacheiaSemGerarDeNovo(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('sem GD');
        }
        $servico = $this->s->uploads();
        $arquivo = $servico->enviar(new UploadedFile($this->arquivoTemporario($this->jpegValido(500, 300)), 'grande.jpg', 'image/jpeg', null, true), $this->propriedadeImagem(), 1, 1, null);
        $miniaturas = $this->s->miniaturas();
        $caminho = $miniaturas->caminho($arquivo, 64);
        self::assertNotNull($caminho);
        self::assertTrue(is_file($caminho));
        $info = getimagesize($caminho);
        self::assertNotFalse($info);
        self::assertLessThanOrEqual(64, max($info[0], $info[1]));
        $mtime = filemtime($caminho);
        self::assertSame($caminho, $miniaturas->caminho($arquivo, 64)); // mesma chamada: não regera
        self::assertSame($mtime, filemtime($caminho));
    }

    public function testMiniaturaSoDeTamanhoDaListaFechadaEsoDeImagem(): void
    {
        $servico = $this->s->uploads();
        $imagem = $servico->enviar(new UploadedFile($this->arquivoTemporario($this->pngValido()), 'x.png', 'image/png', null, true), $this->propriedadeImagem(), 1, 1, null);
        $doc = $servico->enviar(new UploadedFile($this->arquivoTemporario($this->pdfValido()), 'x.pdf', 'application/pdf', null, true), $this->propriedadeArquivo(), 1, 1, null);
        $miniaturas = $this->s->miniaturas();
        self::assertNull($miniaturas->caminho($imagem, 999)); // tamanho fora da lista
        self::assertNull($miniaturas->caminho($doc, 64)); // não é imagem
        self::assertFalse(ServicoDeMiniaturas::tamanhoValido(999));
    }

    // ---------------------------------------------------------------- limpeza de órfãos

    public function testLimpadorDeUploadsPreservaReferenciadosEApagaOrfaosAposACarencia(): void
    {
        $classe = $this->s->classes->criar('album', [
            ['nome' => 'capa', 'tipo' => 'imagem', 'indexavel' => false],
        ]);
        $site = $this->s->sites->porHost('www.exemplo.org') ?? self::fail();
        $raiz = $this->s->objetos->porId((int) $site->objetoRaizId) ?? self::fail();
        $servico = $this->s->uploads();
        $usada = $servico->enviar(new UploadedFile($this->arquivoTemporario($this->pngValido()), 'usada.png', 'image/png', null, true), $classe->propriedades[0], $site->id, $raiz->id, null);
        $orfaRecente = $servico->enviar(new UploadedFile($this->arquivoTemporario($this->pngValido() . 'x'), 'orfa-recente.png', 'image/png', null, true), $classe->propriedades[0], $site->id, $raiz->id, null);
        $orfaAntiga = $servico->enviar(new UploadedFile($this->arquivoTemporario($this->pngValido() . 'y'), 'orfa-antiga.png', 'image/png', null, true), $classe->propriedades[0], $site->id, $raiz->id, null);

        $this->s->objetos->criarDentro($site->id, $classe, $raiz->id, 'Álbum', 'album-1', ['capa' => $usada->id]);
        // Só a órfã "antiga" já passou da carência de 1 hora.
        $this->s->conexao()->executeStatement('UPDATE arquivo SET criado_em = ? WHERE id = ?', [gmdate('c', time() - 7200), $orfaAntiga->id]);

        $limpador = new LimpadorDeUploads($this->s->conexao(), $this->s->classes, $this->s->arquivos, new ArmazenamentoDeArquivos($this->caminhos));

        $simulado = $limpador->executar(true);
        self::assertSame(1, $simulado['orfaos']); // só a antiga
        self::assertNotNull($this->s->arquivos->porId($orfaAntiga->id)); // simular não apaga nada
        self::assertNotNull($this->s->arquivos->porId($orfaRecente->id));

        $real = $limpador->executar(false);
        self::assertSame(1, $real['apagados']);
        self::assertNull($this->s->arquivos->porId($orfaAntiga->id));
        self::assertNotNull($this->s->arquivos->porId($usada->id)); // referenciado: nunca apagado
        self::assertNotNull($this->s->arquivos->porId($orfaRecente->id)); // órfão, mas ainda na carência
    }

    public function testLimpadorPreservaArquivoDeVersaoAntigaMesmoDepoisDeTrocado(): void
    {
        $classe = $this->s->classes->criar('album2', [
            ['nome' => 'capa', 'tipo' => 'imagem', 'indexavel' => false],
        ]);
        $site = $this->s->sites->porHost('www.exemplo.org') ?? self::fail();
        $raiz = $this->s->objetos->porId((int) $site->objetoRaizId) ?? self::fail();
        $servico = $this->s->uploads();
        $antiga = $servico->enviar(new UploadedFile($this->arquivoTemporario($this->pngValido() . 'a'), 'antiga.png', 'image/png', null, true), $classe->propriedades[0], $site->id, $raiz->id, null);
        $o = $this->s->objetos->criarDentro($site->id, $classe, $raiz->id, 'Álbum 2', 'album-2', ['capa' => $antiga->id]);
        $this->s->conexao()->executeStatement('UPDATE arquivo SET criado_em = ? WHERE id = ?', [gmdate('c', time() - 7200), $antiga->id]);
        $nova = $servico->enviar(new UploadedFile($this->arquivoTemporario($this->pngValido() . 'b'), 'nova.png', 'image/png', null, true), $classe->propriedades[0], $site->id, $raiz->id, null);
        $this->s->objetos->atualizar($o->id, ['dados' => ['capa' => $nova->id]]);

        $limpador = new LimpadorDeUploads($this->s->conexao(), $this->s->classes, $this->s->arquivos, new ArmazenamentoDeArquivos($this->caminhos));
        $limpador->executar(false);

        // "antiga" ainda está na versão histórica do objeto (restaurar traria ela de volta): não é apagada.
        self::assertNotNull($this->s->arquivos->porId($antiga->id));
        self::assertNotNull($this->s->arquivos->porId($nova->id));
    }
}
