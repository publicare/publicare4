<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Atualizacao\Assinatura;
use Pbl\Atualizacao\Atualizador;
use Pbl\Atualizacao\BaixadorHttps;
use Pbl\Atualizacao\BaixadorLocal;
use Pbl\Atualizacao\Empacotador;
use Pbl\Atualizacao\ErroDeAtualizacao;
use Pbl\Atualizacao\Manifesto;
use Pbl\Atualizacao\Manutencao;
use Pbl\Atualizacao\Pacote;
use Pbl\Atualizacao\Semver;
use Pbl\Caminhos;
use PHPUnit\Framework\TestCase;

/** Atualizador: assinatura, pacote defensivo, troca atômica, manutenção e volta atrás. */
final class AtualizadorTest extends TestCase
{
    /** @var list<string> */
    private array $limpar = [];
    /** @var array{publica: string, privada: string} */
    private array $par;

    protected function setUp(): void
    {
        $this->par = Assinatura::gerarPar();
    }

    protected function tearDown(): void
    {
        foreach ($this->limpar as $p) {
            Ajuda::apagar($p);
        }
    }

    /** Projeto de mentira com uma versão e um arquivo marcador. */
    private function projeto(string $versao, string $marcador): string
    {
        $raiz = Ajuda::raizDeProjeto();
        $this->limpar[] = $raiz;
        mkdir($raiz . '/app/src', 0775, true);
        mkdir($raiz . '/vendor', 0775, true);
        file_put_contents($raiz . '/app/src/Versao.php', "<?php\nfinal class Versao { public const ATUAL = '{$versao}'; }\n");
        file_put_contents($raiz . '/app/src/marcador.txt', $marcador);
        file_put_contents($raiz . '/vendor/autoload.php', '<?php');
        mkdir($raiz . '/bin', 0775, true);
        file_put_contents($raiz . '/bin/pbl', '#!/usr/bin/env php');
        chmod($raiz . '/bin/pbl', 0755);
        file_put_contents($raiz . '/composer.json', '{"require":{"php":">=8.2"}}');
        file_put_contents($raiz . '/composer.lock', '{}');
        file_put_contents($raiz . '/LICENSE', 'MIT');

        return $raiz;
    }

    /**
     * @param array{atualizar_a_partir_de?: string, seguranca?: bool, resumo?: string, notas?: string, data?: string} $opcoes
     * @return array{0: string, 1: string} pasta de publicação e caminho do manifesto
     */
    private function publicar(string $versao, string $marcador = 'novo', string $canal = 'estavel', ?string $chave = null, array $opcoes = []): array
    {
        $origem = $this->projeto($versao, $marcador);
        $saida = Ajuda::raizDeProjeto();
        $this->limpar[] = $saida;
        $r = (new Empacotador())->empacotar($origem, $saida, $chave ?? $this->par['privada'], $canal, $opcoes);

        return [$saida, $r['manifesto']];
    }

    /** @param list<string>|null $chaves */
    private function atualizador(string $raiz, string $versaoAtual = '0.1.0', ?\Closure $conferir = null, ?array $chaves = null): Atualizador
    {
        return new Atualizador(new Caminhos($raiz), new BaixadorLocal(), $chaves ?? [$this->par['publica']], $versaoAtual, $conferir ?? static function (): void {
        }, '8.3.0');
    }

    public function testAtualizaDeVerdadeTrocaOCodigoMantemDadosEGuardaBanco(): void
    {
        [, $manifesto] = $this->publicar('0.2.0', 'codigo novo', 'estavel', null, ['resumo' => 'Novidades']);
        $raiz = $this->projeto('0.1.0', 'codigo velho');
        mkdir($raiz . '/dados', 0775, true);
        file_put_contents($raiz . '/dados/pbl4.sqlite', 'BANCO');
        file_put_contents($raiz . '/dados/meu-arquivo.txt', 'meu');
        $a = $this->atualizador($raiz);

        $v = $a->verificar($manifesto);
        self::assertTrue($v->disponivel);
        $r = $a->aplicar($v, $manifesto);

        self::assertSame('0.2.0', $r->para);
        self::assertSame('codigo novo', file_get_contents($raiz . '/app/src/marcador.txt'));
        self::assertTrue(is_executable($raiz . '/bin/pbl'), 'o lançador continua executável');
        self::assertSame('meu', file_get_contents($raiz . '/dados/meu-arquivo.txt'), 'dados/ intocado');
        self::assertSame('BANCO', file_get_contents($r->bancoCopiadoPara), 'cópia do banco');
        self::assertSame('codigo velho', file_get_contents($raiz . '/dados/atualizacao/anterior/app/src/marcador.txt'), 'código antigo guardado');
        self::assertFalse(Manutencao::ativa(new Caminhos($raiz)), 'manutenção desligada no fim');
    }

    public function testCopiaDoBancoSqliteEhConsistenteELegivel(): void
    {
        [, $manifesto] = $this->publicar('0.2.0', 'novo');
        $raiz = $this->projeto('0.1.0', 'velho');
        mkdir($raiz . '/dados', 0775, true);
        $c = \Pbl\Banco\Conexao::sqlite($raiz . '/dados/pbl4.sqlite');
        $c->executeStatement('CREATE TABLE t (v TEXT)');
        $c->executeStatement("INSERT INTO t VALUES ('importante')");
        $a = $this->atualizador($raiz);

        $r = $a->aplicar($a->verificar($manifesto), $manifesto);

        self::assertSame('importante', \Pbl\Banco\Conexao::sqlite($r->bancoCopiadoPara)->fetchOne('SELECT v FROM t'));
    }

    public function testFalhaAoCopiarOBancoDesligaAManutencaoENaoTrocaNada(): void
    {
        [, $manifesto] = $this->publicar('0.2.0', 'novo');
        $raiz = $this->projeto('0.1.0', 'velho');
        mkdir($raiz . '/dados', 0775, true);
        file_put_contents($raiz . '/dados/backups', 'um arquivo onde deveria haver uma pasta');
        file_put_contents($raiz . '/dados/pbl4.sqlite', 'BANCO');
        $a = $this->atualizador($raiz);

        try {
            @$a->aplicar($a->verificar($manifesto), $manifesto);
            self::fail('deveria falhar');
        } catch (ErroDeAtualizacao) {
            $this->addToAssertionCount(1);
        }
        self::assertSame('velho', file_get_contents($raiz . '/app/src/marcador.txt'));
        self::assertFalse(Manutencao::ativa(new Caminhos($raiz)));
    }

    public function testFalhaDepoisDaTrocaDevolveOCodigoAntigo(): void
    {
        [, $manifesto] = $this->publicar('0.2.0', 'codigo novo');
        $raiz = $this->projeto('0.1.0', 'codigo velho');
        mkdir($raiz . '/dados', 0775, true);
        $a = $this->atualizador($raiz, '0.1.0', static function (): void {
            throw new ErroDeAtualizacao('migração quebrou');
        });

        try {
            $a->aplicar($a->verificar($manifesto), $manifesto);
            self::fail('deveria falhar');
        } catch (ErroDeAtualizacao $e) {
            self::assertStringContainsString('código anterior foi restaurado', $e->getMessage());
            self::assertStringContainsString('migração quebrou', $e->getMessage());
        }
        self::assertSame('codigo velho', file_get_contents($raiz . '/app/src/marcador.txt'));
        self::assertFalse(Manutencao::ativa(new Caminhos($raiz)));
    }

    public function testAssinaturaDeOutraChaveOuAdulteradaEhRecusada(): void
    {
        $outra = Assinatura::gerarPar();
        [, $manifesto] = $this->publicar('0.2.0', 'novo', 'estavel', $outra['privada']);
        $raiz = $this->projeto('0.1.0', 'velho');

        $this->expectException(ErroDeAtualizacao::class);
        $this->expectExceptionMessage('assinatura');
        $this->atualizador($raiz)->verificar($manifesto);
    }

    public function testManifestoAdulteradoDepoisDeAssinadoEhRecusado(): void
    {
        [, $manifesto] = $this->publicar('0.2.0');
        file_put_contents($manifesto, str_replace('"seguranca": false', '"seguranca": true', (string) file_get_contents($manifesto)));
        $raiz = $this->projeto('0.1.0', 'velho');

        $this->expectException(ErroDeAtualizacao::class);
        $this->atualizador($raiz)->verificar($manifesto);
    }

    public function testSemChaveEmbutidaNadaEhAceito(): void
    {
        [, $manifesto] = $this->publicar('0.2.0');
        $this->expectException(ErroDeAtualizacao::class);
        $this->expectExceptionMessage('chave de assinatura');
        $this->atualizador($this->projeto('0.1.0', 'velho'), '0.1.0', null, [])->verificar($manifesto);
    }

    public function testPacoteTrocadoPorOutroComMesmoNomeNaoConfereComOManifesto(): void
    {
        [$saida, $manifesto] = $this->publicar('0.2.0');
        file_put_contents($saida . '/pbl4-0.2.0.zip', 'qualquer coisa');
        $a = $this->atualizador($this->projeto('0.1.0', 'velho'));

        $this->expectException(ErroDeAtualizacao::class);
        $a->aplicar($a->verificar($manifesto), $manifesto);
    }

    public function testNaoAtualizaParaVersaoIgualOuMenorNemPulaMinimo(): void
    {
        [, $manifesto] = $this->publicar('0.2.0', 'novo', 'estavel', null, ['atualizar_a_partir_de' => '0.1.5']);
        self::assertFalse($this->atualizador($this->projeto('0.2.0', 'x'), '0.2.0')->verificar($manifesto)->disponivel);
        $v = $this->atualizador($this->projeto('0.1.0', 'x'), '0.1.0')->verificar($manifesto);
        self::assertFalse($v->disponivel);
        self::assertStringContainsString('0.1.5', $v->motivo);
    }

    public function testPhpAntigoDemaisImpedeEMostraOMotivo(): void
    {
        [, $manifesto] = $this->publicar('0.2.0');
        $a = new Atualizador(new Caminhos($this->projeto('0.1.0', 'x')), new BaixadorLocal(), [$this->par['publica']], '0.1.0', null, '8.1.0');
        $v = $a->verificar($manifesto);
        self::assertFalse($v->disponivel);
        self::assertStringContainsString('PHP', $v->motivo);
    }

    public function testCanalErradoEhRecusado(): void
    {
        [, $manifesto] = $this->publicar('0.2.0-beta.1', 'novo', 'beta');
        $this->expectException(ErroDeAtualizacao::class);
        $this->atualizador($this->projeto('0.1.0', 'x'))->verificar($manifesto);
    }

    public function testEmpacotadorRecusaVersaoDevEPreLancamentoNoCanalEstavel(): void
    {
        foreach (['0.0.0-dev', '0.2.0-beta.1'] as $versao) {
            $saida = Ajuda::raizDeProjeto();
            $this->limpar[] = $saida;
            try {
                (new Empacotador())->empacotar($this->projeto($versao, 'x'), $saida, $this->par['privada']);
                self::fail("deveria recusar {$versao}");
            } catch (ErroDeAtualizacao) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSemverSegueAPrecedenciaDoSemVer(): void
    {
        $ordem = ['0.1.0-alpha', '0.1.0-alpha.1', '0.1.0-alpha.beta', '0.1.0-beta', '0.1.0-beta.2', '0.1.0-beta.11', '0.1.0-rc.1', '0.1.0', '0.1.1', '0.2.0', '1.0.0'];
        for ($i = 0; $i < count($ordem) - 1; $i++) {
            self::assertSame(-1, Semver::comparar($ordem[$i], $ordem[$i + 1]), "{$ordem[$i]} < {$ordem[$i + 1]}");
            self::assertSame(1, Semver::comparar($ordem[$i + 1], $ordem[$i]));
        }
        self::assertSame(0, Semver::comparar('1.0.0+a', '1.0.0+b'));
        foreach (['1', '1.0', '01.0.0', '1.0.0-', 'v1.0.0', "1.0.0\n"] as $ruim) {
            self::assertFalse(Semver::valida($ruim), $ruim);
        }
    }

    public function testNomesPerigososNoPacoteSaoRecusados(): void
    {
        foreach (['../x', 'app/../../x', '/etc/passwd', 'dados/pbl4.sqlite', 'tests/x.php', 'app//x', "app/x\0y", 'app\\x', '.git/config'] as $nome) {
            try {
                Pacote::validarNome($nome);
                self::fail("deveria recusar {$nome}");
            } catch (ErroDeAtualizacao) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame('app/src/X.php', Pacote::validarNome('app/src/X.php'));
    }

    public function testZipComArquivoForaDaListaOuHashErradoEhRecusado(): void
    {
        $dir = Ajuda::raizDeProjeto();
        $this->limpar[] = $dir;
        $monta = function (string $nome, array $arquivos, array $lista) use ($dir): string {
            $z = new \ZipArchive();
            $z->open("{$dir}/{$nome}.zip", \ZipArchive::CREATE);
            $z->addFromString('PACOTE.json', json_encode(['versao' => '1.0.0', 'arquivos' => $lista], JSON_THROW_ON_ERROR));
            foreach ($arquivos as $n => $c) {
                $z->addFromString($n, $c);
            }
            $z->close();

            return "{$dir}/{$nome}.zip";
        };
        $h = hash('sha256', 'ok');
        $casos = [
            'extra' => $monta('extra', ['app/a.php' => 'ok', 'app/b.php' => 'x'], ['app/a.php' => $h]),
            'hash' => $monta('hash', ['app/a.php' => 'adulterado'], ['app/a.php' => $h]),
            'falta' => $monta('falta', ['app/a.php' => 'ok'], ['app/a.php' => $h, 'app/b.php' => $h]),
            'fora' => $monta('fora', ['dados/x' => 'ok'], ['dados/x' => $h]),
            'versao' => $monta('versao', ['app/a.php' => 'ok'], ['app/a.php' => $h]),
        ];
        foreach ($casos as $nome => $zip) {
            try {
                Pacote::extrair($zip, "{$dir}/saida-{$nome}", $nome === 'versao' ? '9.9.9' : '1.0.0');
                self::fail("deveria recusar {$nome}");
            } catch (ErroDeAtualizacao) {
                $this->addToAssertionCount(1);
            }
        }
        $bom = $monta('bom', ['app/a.php' => 'ok'], ['app/a.php' => $h]);
        self::assertSame(['app/a.php' => $h], Pacote::extrair($bom, "{$dir}/saida-bom", '1.0.0')['arquivos']);
    }

    public function testManifestoRecusaCamposInvalidos(): void
    {
        $base = ['formato' => 1, 'canal' => 'estavel', 'versao' => '0.2.0', 'data' => '2026-11-01', 'php_minimo' => '8.2', 'atualizar_a_partir_de' => '0.0.0',
            'pacote' => ['url' => 'p.zip', 'tamanho' => 10, 'sha256' => str_repeat('a', 64)]];
        self::assertSame('0.2.0', Manifesto::analisar(json_encode($base, JSON_THROW_ON_ERROR))->versao);
        $ruins = [
            ['versao' => 'x'], ['canal' => 'nightly'], ['formato' => 2], ['data' => 'ontem'], ['php_minimo' => 'oito'],
            ['atualizar_a_partir_de' => '9.0.0'], ['pacote' => ['url' => 'p.zip', 'tamanho' => -1, 'sha256' => str_repeat('a', 64)]],
            ['pacote' => ['url' => 'p.zip', 'tamanho' => 10, 'sha256' => 'curto']],
        ];
        foreach ($ruins as $troca) {
            try {
                Manifesto::analisar(json_encode($troca + $base, JSON_THROW_ON_ERROR));
                self::fail('deveria recusar ' . json_encode($troca));
            } catch (ErroDeAtualizacao) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testBaixadorHttpsSoAceitaHttpsNoMesmoServidor(): void
    {
        $b = new BaixadorHttps();
        foreach (['http://exemplo.org/a', 'ftp://exemplo.org/a', 'https://u:p@exemplo.org/a', 'https://exemplo.org:8443/a', 'file:///etc/passwd'] as $ruim) {
            try {
                $b->obter($ruim, 100);
                self::fail("deveria recusar {$ruim}");
            } catch (ErroDeAtualizacao $e) {
                self::assertStringContainsString('HTTPS', $e->getMessage() . ' HTTPS');
            }
        }
        self::assertSame('https://exemplo.org/pbl4/p.zip', $b->resolver('https://exemplo.org/pbl4/latest.json', 'p.zip'));
        self::assertSame('https://exemplo.org/p.zip', $b->resolver('https://exemplo.org/pbl4/latest.json', '/p.zip'));
        foreach (['https://outro.org/p.zip', '../p.zip', 'http://exemplo.org/p.zip', 'a://b'] as $ruim) {
            try {
                $b->resolver('https://exemplo.org/pbl4/latest.json', $ruim);
                self::fail("deveria recusar {$ruim}");
            } catch (ErroDeAtualizacao) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testManutencaoVenceSozinha(): void
    {
        $raiz = $this->projeto('0.1.0', 'x');
        mkdir($raiz . '/dados', 0775, true);
        $c = new Caminhos($raiz);
        Manutencao::ligar($c, 'teste');
        self::assertTrue(Manutencao::ativa($c));
        file_put_contents($raiz . '/dados/manutencao.json', json_encode(['desde' => time() - Manutencao::VALIDADE - 1, 'motivo' => 'velha']));
        self::assertFalse(Manutencao::ativa($c), 'uma atualização que morreu não deixa o site fora do ar');
    }
}
