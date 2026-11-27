<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Atualizacao\Atualizador;
use Pbl\Atualizacao\Baixador;
use Pbl\Atualizacao\Empacotador;
use Pbl\Atualizacao\ErroDeAtualizacao;
use Pbl\Atualizacao\Manutencao;
use Pbl\Atualizacao\RegistroDaAtualizacao;
use Pbl\Atualizacao\Reversor;
use Pbl\Atualizacao\Semver;
use Pbl\Banco\Conexao;
use Pbl\Caminhos;
use PHPUnit\Framework\TestCase;

/**
 * Teste de atualização da 0.1.0-rc.1 para a 0.1.0-rc.2, no canal beta, de ponta a ponta e sem Internet: dois pacotes assinados
 * com um par de chaves de TESTE (gerado em memória; nunca as chaves de produção), baixados de um servidor HTTP local falso
 * (`php -S` em 127.0.0.1), aplicados com migração e trava de manutenção e depois revertidos com a conferência do SHA-256.
 */
final class AtualizacaoRc1ParaRc2Test extends TestCase
{
    private const RC1 = '0.1.0-rc.1';
    private const RC2 = '0.1.0-rc.2';

    /** @var list<string> */
    private array $limpar = [];
    /** @var array{publica: string, privada: string} */
    private array $par;
    /** @var resource|null */
    private $servidor;
    private string $urlBase = '';

    protected function setUp(): void
    {
        $this->par = \Pbl\Atualizacao\Assinatura::gerarPar();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->servidor)) {
            proc_terminate($this->servidor);
            proc_close($this->servidor);
        }
        foreach ($this->limpar as $p) {
            Ajuda::apagar($p);
        }
    }

    private function projeto(string $versao, string $marcador, bool $comBanco, bool $arquivoNovo = false): string
    {
        $raiz = Ajuda::raizDeProjeto();
        $this->limpar[] = $raiz;
        mkdir($raiz . '/app/src', 0775, true);
        mkdir($raiz . '/vendor', 0775, true);
        mkdir($raiz . '/bin', 0775, true);
        file_put_contents($raiz . '/app/src/Versao.php', "<?php\nfinal class Versao { public const ATUAL = '{$versao}'; }\n");
        file_put_contents($raiz . '/app/src/marcador.txt', $marcador);
        if ($arquivoNovo) {
            file_put_contents($raiz . '/app/src/novo-na-rc2.txt', 'só existe na rc.2');
        }
        file_put_contents($raiz . '/vendor/autoload.php', '<?php');
        file_put_contents($raiz . '/bin/pbl', '#!/usr/bin/env php');
        chmod($raiz . '/bin/pbl', 0755);
        file_put_contents($raiz . '/composer.json', '{"require":{"php":">=8.2"}}');
        file_put_contents($raiz . '/composer.lock', '{}');
        file_put_contents($raiz . '/LICENSE', 'MIT');
        if ($comBanco) {
            mkdir($raiz . '/dados', 0775, true);
            $c = Conexao::sqlite($raiz . '/dados/pbl4.sqlite');
            $c->executeStatement('CREATE TABLE schema_versao (versao INTEGER PRIMARY KEY)');
            $c->executeStatement('INSERT INTO schema_versao (versao) VALUES (1)');
            $c->executeStatement('CREATE TABLE marca (v TEXT)');
            $c->executeStatement("INSERT INTO marca VALUES ('{$marcador}')");
        }

        return $raiz;
    }

    /** Empacota e assina a rc.2 (canal beta) e a rc.1, e devolve a pasta que o servidor falso publica. */
    private function publicarRc2(): string
    {
        $origem = $this->projeto(self::RC2, 'codigo rc2', false, true);
        $saida = Ajuda::raizDeProjeto();
        $this->limpar[] = $saida;
        $r = (new Empacotador())->empacotar($origem, $saida, $this->par['privada'], 'beta', ['seguranca' => true, 'url_base' => '/pacotes/']);
        self::assertSame(self::RC2, $r['versao']);
        mkdir($saida . '/pacotes', 0775, true);
        rename($r['zip'], $saida . '/pacotes/' . basename($r['zip']));

        return $saida;
    }

    private function iniciarServidor(string $docroot): void
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            self::markTestSkipped('Sem como abrir uma porta local: ' . $errstr);
        }
        $porta = (int) explode(':', (string) stream_socket_get_name($sock, false))[1];
        fclose($sock);
        $p = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$porta}", '-t', $docroot], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($p)) {
            self::markTestSkipped('Não foi possível iniciar o servidor local.');
        }
        $this->servidor = $p;
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $porta, $e, $s, 0.2);
            if ($c !== false) {
                fclose($c);
                $this->urlBase = "http://127.0.0.1:{$porta}";

                return;
            }
            usleep(100_000);
        }
        self::markTestSkipped('O servidor local não respondeu.');
    }

    /** Baixador de teste: HTTP puro, só para 127.0.0.1 (o `BaixadorHttps` de produção continua exigindo https). */
    private function baixadorLocalHttp(): Baixador
    {
        return new class () implements Baixador {
            public function obter(string $origem, int $maximoBytes): string
            {
                $t = @file_get_contents($origem, false, null, 0, max(0, $maximoBytes) + 1);
                if ($t === false || strlen($t) > $maximoBytes) {
                    throw new ErroDeAtualizacao("Não foi possível baixar {$origem}");
                }

                return $t;
            }

            public function copiar(string $origem, string $arquivoDestino, int $maximoBytes): void
            {
                file_put_contents($arquivoDestino, $this->obter($origem, $maximoBytes));
            }

            public function resolver(string $base, string $relativo): string
            {
                if (str_contains($relativo, '..') || !str_starts_with($relativo, '/')) {
                    throw new ErroDeAtualizacao('Endereço do pacote inválido');
                }
                $u = parse_url($base);

                return ($u['scheme'] ?? 'http') . '://' . ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : '') . $relativo;
            }
        };
    }

    /** @return array{0: string, 1: Atualizador, 2: string, 3: \ArrayObject<int, bool>} raiz instalada (rc.1), atualizador, URL do manifesto e o que a trava mostrou durante a troca */
    private function prepararAtualizacao(): array
    {
        $this->iniciarServidor($this->publicarRc2());
        $raiz = $this->projeto(self::RC1, 'codigo rc1', true);
        /** @var \ArrayObject<int, bool> $durante */
        $durante = new \ArrayObject();
        $conferir = function () use ($raiz, $durante): void {
            $durante[] = Manutencao::ativa(new Caminhos($raiz));
            // a migração da rc.2 roda dentro da troca, com o código novo
            Conexao::sqlite($raiz . '/dados/pbl4.sqlite')->executeStatement('INSERT INTO schema_versao (versao) VALUES (2)');
        };
        $a = new Atualizador(new Caminhos($raiz), $this->baixadorLocalHttp(), [$this->par['publica']], self::RC1, $conferir, '8.3.0');

        return [$raiz, $a, $this->urlBase . '/latest-beta.json', $durante];
    }

    public function testManifestoBetaDaRc2EstaAssinadoERecusadoPeloCanalEstavel(): void
    {
        [, $a, $url] = $this->prepararAtualizacao();

        self::assertLessThan(0, Semver::comparar(self::RC1, self::RC2));
        $v = $a->verificar($url, 'beta');
        self::assertTrue($v->disponivel, $v->motivo);
        self::assertNotNull($v->manifesto);
        self::assertSame(self::RC2, $v->manifesto->versao);
        self::assertSame('beta', $v->manifesto->canal);
        self::assertTrue($v->manifesto->seguranca);
        self::assertStringStartsWith('/pacotes/', $v->manifesto->urlDoPacote);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $v->manifesto->sha256);

        $this->expectException(ErroDeAtualizacao::class);
        $this->expectExceptionMessage('canal beta');
        $a->verificar($url, 'estavel');
    }

    public function testManifestoAdulteradoNoServidorEhRecusado(): void
    {
        [, $a, $url] = $this->prepararAtualizacao();
        $docroot = null;
        foreach ($this->limpar as $p) {
            if (is_file($p . '/latest-beta.json')) {
                $docroot = $p;
            }
        }
        self::assertNotNull($docroot);
        file_put_contents($docroot . '/latest-beta.json', str_replace(self::RC2, '0.1.0-rc.9', (string) file_get_contents($docroot . '/latest-beta.json')));

        $this->expectException(ErroDeAtualizacao::class);
        $this->expectExceptionMessage('assinatura');
        $a->verificar($url, 'beta');
    }

    /** @return array{0: string, 1: string, 2: Atualizador} raiz, URL e atualizador da atualização já aplicada */
    private function atualizada(): array
    {
        [$raiz, $a, $url, $durante] = $this->prepararAtualizacao();
        $v = $a->verificar($url, 'beta');
        $aplicada = $a->aplicar($v, $url);

        self::assertSame(self::RC1, $aplicada->de);
        self::assertSame(self::RC2, $aplicada->para);
        self::assertSame([true], $durante->getArrayCopy(), 'a manutenção ficou ligada durante a troca');
        self::assertFalse(Manutencao::ativa(new Caminhos($raiz)), 'e foi desligada depois');
        self::assertStringContainsString(self::RC2, (string) file_get_contents($raiz . '/app/src/Versao.php'));
        self::assertSame('codigo rc2', file_get_contents($raiz . '/app/src/marcador.txt'));
        self::assertFileExists($raiz . '/app/src/novo-na-rc2.txt');
        self::assertSame(2, (int) Conexao::sqlite($raiz . '/dados/pbl4.sqlite')->fetchOne('SELECT MAX(versao) FROM schema_versao'), 'migração aplicada');

        // conteúdo criado depois da atualização: a reversão restauraria a cópia de antes e perderia isto
        Conexao::sqlite($raiz . '/dados/pbl4.sqlite')->executeStatement("UPDATE marca SET v = 'conteudo novo'");

        return [$raiz, $url, $a];
    }

    public function testAtualizaPeloServidorLocalERegistraOsDoisHashes(): void
    {
        \Pbl\Testes\Ajuda::pularSeBancoDeServidor();
        [$raiz] = $this->atualizada();

        $r = RegistroDaAtualizacao::ler(new Caminhos($raiz));
        self::assertNotNull($r);
        self::assertSame(self::RC1, $r['de']);
        self::assertSame(self::RC2, $r['para']);
        self::assertFalse($r['revertida']);
        self::assertSame(1, $r['schema_antes']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $r['backup_sha256']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $r['codigo_sha256']);
        self::assertSame('codigo rc1', file_get_contents($raiz . '/dados/atualizacao/anterior/app/src/marcador.txt'), 'o código da rc.1 ficou guardado');
    }

    public function testReverterDaRc2ParaRc1ConfereCodigoEBancoERestaura(): void
    {
        \Pbl\Testes\Ajuda::pularSeBancoDeServidor();
        [$raiz] = $this->atualizada();
        $rev = new Reversor(new Caminhos($raiz), self::RC2, static function (): void {
        });

        $av = $rev->avaliar();
        self::assertTrue($av->possivel, $av->motivo);
        self::assertSame(self::RC1, $av->de);
        self::assertSame(self::RC2, $av->para);
        self::assertTrue($av->restauraBanco);

        $res = $rev->reverter('cli');

        self::assertSame(self::RC2, $res->de);
        self::assertSame(self::RC1, $res->para);
        self::assertStringContainsString(self::RC1, (string) file_get_contents($raiz . '/app/src/Versao.php'));
        self::assertSame('codigo rc1', file_get_contents($raiz . '/app/src/marcador.txt'));
        self::assertFileDoesNotExist($raiz . '/app/src/novo-na-rc2.txt', 'o arquivo que só existia na rc.2 sai');
        $c = Conexao::sqlite($raiz . '/dados/pbl4.sqlite');
        self::assertSame('codigo rc1', $c->fetchOne('SELECT v FROM marca'));
        self::assertSame(1, (int) $c->fetchOne('SELECT MAX(versao) FROM schema_versao'));
        self::assertFalse(Manutencao::ativa(new Caminhos($raiz)));
        $registro = RegistroDaAtualizacao::ler(new Caminhos($raiz));
        self::assertNotNull($registro);
        self::assertTrue($registro['revertida']);
    }

    public function testReversaoRecusaOCodigoGuardadoAdulteradoSemTocarEmNada(): void
    {
        [$raiz] = $this->atualizada();
        file_put_contents($raiz . '/dados/atualizacao/anterior/app/src/marcador.txt', 'codigo rc1 ADULTERADO');

        $rev = new Reversor(new Caminhos($raiz), self::RC2, static function (): void {
        });
        $av = $rev->avaliar();
        self::assertFalse($av->possivel);
        self::assertStringContainsString('SHA-256 não confere', $av->motivo);
        try {
            $rev->reverter('cli');
            self::fail('deveria recusar');
        } catch (ErroDeAtualizacao $e) {
            self::assertStringContainsString('SHA-256 não confere', $e->getMessage());
        }
        $this->assertNadaMudou($raiz);
    }

    public function testRegistroSemHashFeitoAntesDaConferenciaEhRecusadoComMensagemClara(): void
    {
        [$raiz] = $this->atualizada();
        $arquivo = RegistroDaAtualizacao::arquivo(new Caminhos($raiz));
        $json = json_decode((string) file_get_contents($arquivo), true);
        self::assertIsArray($json);
        unset($json['codigo_sha256']);
        file_put_contents($arquivo, json_encode($json));

        $rev = new Reversor(new Caminhos($raiz), self::RC2, static function (): void {
        });
        $av = $rev->avaliar();
        self::assertFalse($av->possivel);
        self::assertStringContainsString('não traz o SHA-256 do código guardado', $av->motivo);
        try {
            $rev->reverter('cli');
            self::fail('deveria recusar');
        } catch (ErroDeAtualizacao $e) {
            self::assertStringContainsString('não traz o SHA-256 do código guardado', $e->getMessage());
            self::assertStringContainsString('nada foi tocado', $e->getMessage());
        }
        $this->assertNadaMudou($raiz);
    }

    private function assertNadaMudou(string $raiz): void
    {
        self::assertStringContainsString(self::RC2, (string) file_get_contents($raiz . '/app/src/Versao.php'));
        self::assertSame('codigo rc2', file_get_contents($raiz . '/app/src/marcador.txt'));
        self::assertSame('conteudo novo', Conexao::sqlite($raiz . '/dados/pbl4.sqlite')->fetchOne('SELECT v FROM marca'));
        self::assertFalse(Manutencao::ativa(new Caminhos($raiz)));
    }
}
