<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Admin\Pagina;
use PHPUnit\Framework\TestCase;

/**
 * Estilos da administração (Bootstrap 5 local, ADR 0012): os arquivos existem, batem com a procedência registrada, a página só os
 * referencia do próprio site (sem CDN, sem inline) e as configurações de servidor de referência os entregam direto.
 */
final class AssetsAdminTest extends TestCase
{
    private function raiz(): string
    {
        return dirname(__DIR__);
    }

    private function publico(string $url): string
    {
        return $this->raiz() . '/public' . $url;
    }

    public function testArquivosExistemEBatemComAProcedencia(): void
    {
        $procedencia = (string) file_get_contents($this->publico(Pagina::ASSETS . '/PROVENIENCIA.md'));
        foreach (['bootstrap-5.3.8/bootstrap.min.css', 'bootstrap-5.3.8/bootstrap.bundle.min.js', 'bootstrap-5.3.8/LICENSE'] as $arquivo) {
            $caminho = $this->publico(Pagina::ASSETS . '/' . $arquivo);
            self::assertFileExists($caminho);
            $sha = hash_file('sha256', $caminho);
            self::assertIsString($sha);
            self::assertStringContainsString('`' . $arquivo . '`', $procedencia);
            self::assertStringContainsString($sha, $procedencia, "SHA-256 de {$arquivo} difere do registrado");
        }
        self::assertStringContainsString('Bootstrap  v5.3.8', (string) file_get_contents($this->publico(Pagina::BOOTSTRAP)));
        self::assertStringContainsString('MIT License', (string) file_get_contents($this->publico(Pagina::ASSETS . '/bootstrap-5.3.8/LICENSE')));
        self::assertStringContainsString('Bootstrap v5.3.8', (string) file_get_contents($this->publico(Pagina::BOOTSTRAP_JS)));
        self::assertFileExists($this->publico(Pagina::CSS));
        self::assertFileExists($this->publico(Pagina::JS));
    }

    public function testUrlVersionadaMudaComOConteudoDoArquivo(): void
    {
        $url = Pagina::urlVersionada(Pagina::CSS);
        self::assertMatchesRegularExpression('#^' . preg_quote(Pagina::CSS, '#') . '\?v=[0-9a-f]{8}$#', $url);
        self::assertSame(hash_file('crc32b', $this->publico(Pagina::CSS)), substr($url, -8));
        self::assertStringContainsString('?v=', Pagina::urlVersionada('/_pbl/assets/inexistente.css'));
    }

    public function testPaginaTemNavegacaoMigalhasEAlertasAcessiveis(): void
    {
        $html = (string) Pagina::resposta('T', Pagina::alerta('danger', 'x') . Pagina::campo('a-b', 'a', 'Nome', 'v'), 200, false, Pagina::barra('Ana', 'tok', true), [['Início', '/_pbl/'], ['Aqui', null]])->getContent();
        self::assertStringContainsString('aria-label="Você está em"', $html);
        self::assertStringContainsString('aria-current="page">Aqui</li>', $html);
        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('<label for="a-b"', $html);
        self::assertStringContainsString('id="a-b"', $html);
    }

    public function testPaginaUsaSoArquivosDoProprioSiteSemInlineNemScript(): void
    {
        $r = Pagina::resposta('Teste', '<p>ok</p>', 200, true, Pagina::barra('Ana', 'token'));
        $html = (string) $r->getContent();
        $csp = (string) $r->headers->get('Content-Security-Policy');

        self::assertStringContainsString('href="' . Pagina::BOOTSTRAP . '?v=', $html);
        self::assertStringContainsString('href="' . Pagina::CSS . '?v=', $html);
        self::assertStringNotContainsString('<style', $html);
        self::assertStringNotContainsString('style=', $html);
        self::assertStringContainsString('<script src="' . Pagina::BOOTSTRAP_JS . '?v=', $html);
        self::assertStringContainsString('<script src="' . Pagina::JS . '?v=', $html);
        self::assertSame(0, preg_match('/<script(?![^>]*\ssrc=)/', $html), 'nenhum script inline');
        self::assertStringNotContainsString('onclick=', $html);
        self::assertStringNotContainsString('//cdn', $html);
        self::assertStringContainsString("style-src 'self'", $csp);
        self::assertStringContainsString("default-src 'none'", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringContainsString("script-src 'self'", $csp, 'só scripts do próprio site');
        self::assertStringContainsString('<main id="conteudo"', $html);
        self::assertStringContainsString('href="#conteudo"', $html, 'atalho para pular a navegação');
    }

    public function testCssDoProjetoNaoBuscaNadaDeFora(): void
    {
        $css = (string) file_get_contents($this->publico(Pagina::CSS));

        self::assertStringNotContainsString('@import', $css);
        self::assertStringNotContainsString('url(', $css);
    }

    public function testApacheEntregaArquivoExistenteDireto(): void
    {
        $publico = (string) file_get_contents($this->raiz() . '/public/.htaccess');
        self::assertStringContainsString('RewriteCond %{REQUEST_FILENAME} !-f', $publico, 'arquivo existente não vai para o index.php');
        $negados = preg_match('/<FilesMatch "([^"]+)">/', $publico, $m) === 1 ? $m[1] : self::fail('FilesMatch não encontrado');
        foreach (['bootstrap.min.css', 'bootstrap.bundle.min.js', 'pbl.css', 'pbl-admin.js'] as $nome) {
            self::assertSame(0, preg_match('/' . str_replace('/', '\/', $negados) . '/', $nome), "{$nome} não pode cair na lista negada");
        }

        // .htaccess da raiz (site enviado inteiro): nada bloqueia /_pbl/assets e o resto vai para public/.
        $raiz = (string) file_get_contents($this->raiz() . '/.htaccess');
        preg_match_all('/^\s*RewriteRule\s+(\S+)\s+-\s+\[F/m', $raiz, $bloqueios);
        self::assertNotEmpty($bloqueios[1]);
        foreach ($bloqueios[1] as $regra) {
            self::assertSame(0, preg_match('/' . str_replace('/', '\/', $regra) . '/', '_pbl/assets/pbl.css'), "regra {$regra} bloquearia os estilos");
        }
        self::assertStringContainsString('RewriteRule ^(.*)$ public/$1', $raiz);
    }

    public function testHtaccessNaoUsaDiretivasQueDaoErro500EmHospedagemComAllowOverrideRestrito(): void
    {
        // "Options" exige AllowOverride Options: em Hestia/painéis restritos vira 500 (conferido em Apache 2.4 real).
        foreach (['/.htaccess', '/public/.htaccess'] as $arquivo) {
            $texto = (string) file_get_contents($this->raiz() . $arquivo);
            $semComentarios = (string) preg_replace('/^\s*#.*$/m', '', $texto);
            self::assertDoesNotMatchRegularExpression('/^\s*Options\b/mi', $semComentarios, $arquivo);
            self::assertStringContainsString('Require all denied', $texto, $arquivo);
            self::assertStringContainsString('<IfModule mod_rewrite.c>', $texto, $arquivo);
        }
        // A raiz só redireciona para public/ quando essa pasta existe sob a raiz do site (se a raiz já é public/, não faz nada).
        self::assertStringContainsString('%{DOCUMENT_ROOT}/public/index.php -f', (string) file_get_contents($this->raiz() . '/.htaccess'));
    }

    public function testNginxDeReferenciaEntregaArquivoExistenteDireto(): void
    {
        $conf = (string) file_get_contents($this->raiz() . '/docker/nginx-teste/pbl4.conf');

        self::assertStringContainsString('try_files $uri /index.php', $conf);
        preg_match_all('/location ~ (\S+) \{ return 404; \}/', $conf, $bloqueios);
        self::assertNotEmpty($bloqueios[1]);
        foreach ($bloqueios[1] as $regra) {
            foreach ([Pagina::BOOTSTRAP, Pagina::BOOTSTRAP_JS, Pagina::CSS, Pagina::JS] as $url) {
                self::assertSame(0, preg_match('#' . $regra . '#', $url), "location {$regra} bloquearia {$url}");
            }
        }
    }

    public function testServidorEmbutidoDeDesenvolvimentoEntregaArquivoExistente(): void
    {
        $index = (string) file_get_contents($this->raiz() . '/public/index.php');

        self::assertStringContainsString("PHP_SAPI === 'cli-server'", $index);
        self::assertStringContainsString('return false;', $index);
    }
}
