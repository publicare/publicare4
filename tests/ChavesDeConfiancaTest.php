<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Atualizacao\Assinatura;
use Pbl\Atualizacao\Atualizador;
use Pbl\Atualizacao\BaixadorLocal;
use Pbl\Atualizacao\Chaves;
use Pbl\Atualizacao\ChavesLocais;
use Pbl\Atualizacao\Empacotador;
use Pbl\Atualizacao\ErroDeAtualizacao;
use Pbl\Caminhos;
use Pbl\Console\AssinarComando;
use Pbl\Console\ChavesComando;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR 0019: duas chaves de produção (uso + recuperação), revogação, troca da chave de uso só pela recuperação e canal de teste
 * com chave própria. Todas as chaves aqui são geradas na hora, só para os testes.
 */
final class ChavesDeConfiancaTest extends TestCase
{
    /** @var list<string> */
    private array $limpar = [];
    /** @var array{publica: string, privada: string} */
    private array $uso;
    /** @var array{publica: string, privada: string} */
    private array $recuperacao;
    /** @var array{publica: string, privada: string} */
    private array $teste;

    protected function setUp(): void
    {
        $this->uso = Assinatura::gerarPar();
        $this->recuperacao = Assinatura::gerarPar();
        $this->teste = Assinatura::gerarPar();
    }

    protected function tearDown(): void
    {
        foreach ($this->limpar as $p) {
            Ajuda::apagar($p);
        }
    }

    /** @param array<string, mixed> $extra */
    private function manifesto(string $assinarCom, string $canal = 'estavel', string $versao = '0.2.0', array $extra = []): string
    {
        $pasta = Ajuda::pastaTemporaria();
        $this->limpar[] = $pasta;
        $json = json_encode([
            'formato' => 1, 'canal' => $canal, 'versao' => $versao, 'data' => '2026-11-02', 'php_minimo' => '8.2',
            'atualizar_a_partir_de' => '0.0.0', 'seguranca' => false, 'migracoes' => true, 'resumo' => '', 'notas' => '',
            'pacote' => ['url' => "pbl4-{$versao}.zip", 'tamanho' => 10, 'sha256' => str_repeat('a', 64)],
        ] + $extra, JSON_THROW_ON_ERROR);
        file_put_contents($pasta . '/latest.json', $json);
        file_put_contents($pasta . '/latest.json.sig', Assinatura::assinar($json, $assinarCom) . "\n");

        return $pasta . '/latest.json';
    }

    private function raiz(): Caminhos
    {
        $raiz = Ajuda::pastaTemporaria();
        $this->limpar[] = $raiz;

        return new Caminhos($raiz);
    }

    /**
     * @param list<string> $revogadas
     */
    private function atualizador(Caminhos $c, array $revogadas = []): Atualizador
    {
        return new Atualizador(
            $c, new BaixadorLocal(), [$this->uso['publica'], $this->recuperacao['publica']], '0.1.0', static function (): void {
            }, '8.3.0', [$this->recuperacao['publica']], $revogadas, [$this->teste['publica']],
        );
    }

    public function testAssinaturaValidaDaChaveDeUsoEDaRecuperacaoSaoAceitas(): void
    {
        $c = $this->raiz();

        self::assertTrue($this->atualizador($c)->verificar($this->manifesto($this->uso['privada']))->disponivel);
        self::assertTrue($this->atualizador($c)->verificar($this->manifesto($this->recuperacao['privada']))->disponivel);
    }

    public function testChaveDesconhecidaEhRecusada(): void
    {
        $outra = Assinatura::gerarPar();

        $this->expectException(ErroDeAtualizacao::class);
        $this->expectExceptionMessage('assinatura');
        $this->atualizador($this->raiz())->verificar($this->manifesto($outra['privada']));
    }

    public function testChaveRevogadaDeNascencaEhRecusada(): void
    {
        $this->expectException(ErroDeAtualizacao::class);
        $this->expectExceptionMessage('assinatura');
        $this->atualizador($this->raiz(), [$this->uso['publica']])->verificar($this->manifesto($this->uso['privada']));
    }

    public function testChaveDeUsoNaoTrocaDeChaveNemRevoga(): void
    {
        $nova = Assinatura::gerarPar();
        $c = $this->raiz();

        try {
            $this->atualizador($c)->verificar($this->manifesto($this->uso['privada'], 'estavel', '0.2.0', ['chaves' => ['uso' => $nova['publica']]]));
            self::fail('troca assinada pela chave de uso deveria ser recusada');
        } catch (ErroDeAtualizacao $e) {
            self::assertStringContainsString('recuperação', $e->getMessage());
        }
        try {
            $this->atualizador($c)->verificar($this->manifesto($this->uso['privada'], 'estavel', '0.2.0', ['chaves' => ['revogadas' => [$this->recuperacao['publica']]]]));
            self::fail('revogação assinada pela chave de uso deveria ser recusada');
        } catch (ErroDeAtualizacao) {
        }
        self::assertNull(ChavesLocais::ler($c)->uso, 'nada foi gravado');
    }

    public function testRecuperacaoTrocaAChaveDeUsoEAAntigaPassaASerRecusada(): void
    {
        $nova = Assinatura::gerarPar();
        $c = $this->raiz();
        $troca = $this->manifesto($this->recuperacao['privada'], 'estavel', '0.2.0', ['chaves' => ['uso' => $nova['publica']]]);

        self::assertTrue($this->atualizador($c)->verificar($troca)->disponivel);
        $local = ChavesLocais::ler($c);
        self::assertSame($nova['publica'], $local->uso);
        self::assertContains($this->uso['publica'], $local->revogadas);

        // a nova assina; a antiga (vazada, por exemplo) não assina mais
        self::assertTrue($this->atualizador($c)->verificar($this->manifesto($nova['privada'], 'estavel', '0.3.0'))->disponivel);
        $this->expectException(ErroDeAtualizacao::class);
        $this->expectExceptionMessage('assinatura');
        $this->atualizador($c)->verificar($this->manifesto($this->uso['privada'], 'estavel', '0.3.0'));
    }

    public function testRecuperacaoRevogaChaveEManifestoNaoRevogaARecuperacao(): void
    {
        $c = $this->raiz();
        $revoga = $this->manifesto($this->recuperacao['privada'], 'estavel', '0.2.0', ['chaves' => ['revogadas' => [$this->uso['publica']]]]);
        $this->atualizador($c)->verificar($revoga);

        try {
            $this->atualizador($c)->verificar($this->manifesto($this->uso['privada'], 'estavel', '0.3.0'));
            self::fail('a chave revogada por manifesto não pode mais assinar');
        } catch (ErroDeAtualizacao $e) {
            self::assertStringContainsString('assinatura', $e->getMessage());
        }

        $contraARecuperacao = $this->manifesto($this->recuperacao['privada'], 'estavel', '0.4.0', ['chaves' => ['revogadas' => [$this->recuperacao['publica']]]]);
        $this->expectException(ErroDeAtualizacao::class);
        $this->expectExceptionMessage('recuperação');
        $this->atualizador($c)->verificar($contraARecuperacao);
    }

    public function testCanalDeTesteComChaveDeTesteFuncionaMasProducaoNaoConfiaNela(): void
    {
        $c = $this->raiz();
        $teste = $this->manifesto($this->teste['privada'], 'teste');

        // no canal teste a chave de teste vale
        self::assertTrue($this->atualizador($c)->verificar($teste, 'teste')->disponivel);

        // em produção (canal estável) o mesmo manifesto, e a chave de teste, são rejeitados
        try {
            $this->atualizador($c)->verificar($teste, 'estavel');
            self::fail('produção não pode confiar na chave de teste');
        } catch (ErroDeAtualizacao $e) {
            self::assertStringContainsString('assinatura', $e->getMessage());
        }
        // e as chaves de produção não valem no canal de teste
        $this->expectException(ErroDeAtualizacao::class);
        $this->expectExceptionMessage('assinatura');
        $this->atualizador($c)->verificar($this->manifesto($this->uso['privada'], 'teste'), 'teste');
    }

    public function testCanalDeTesteNaoTrocaChavesDeProducao(): void
    {
        $nova = Assinatura::gerarPar();

        $this->expectException(ErroDeAtualizacao::class);
        $this->expectExceptionMessage('recuperação');
        $this->atualizador($this->raiz())->verificar($this->manifesto($this->teste['privada'], 'teste', '0.2.0', ['chaves' => ['uso' => $nova['publica']]]), 'teste');
    }

    public function testSemNenhumaChaveNadaEhAceito(): void
    {
        $a = new Atualizador($this->raiz(), new BaixadorLocal(), [], '0.1.0', null, '8.3.0', [], [], []);

        $this->expectException(ErroDeAtualizacao::class);
        $this->expectExceptionMessage('não tem chave');
        $a->verificar($this->manifesto($this->uso['privada']));
    }

    public function testEmpacotadorSemChaveNaoAssinaEAssinarComandoAssinaDepois(): void
    {
        $origem = Ajuda::raizDeProjeto();
        $this->limpar[] = $origem;
        mkdir($origem . '/app/src', 0775, true);
        mkdir($origem . '/vendor', 0775, true);
        mkdir($origem . '/bin', 0775, true);
        file_put_contents($origem . '/app/src/Versao.php', "<?php\nfinal class Versao { public const ATUAL = '0.2.0'; }\n");
        file_put_contents($origem . '/vendor/autoload.php', '<?php');
        file_put_contents($origem . '/bin/pbl', '#!/usr/bin/env php');
        file_put_contents($origem . '/LICENSE', 'Apache');
        file_put_contents($origem . '/NOTICE', 'aviso');
        $saida = Ajuda::pastaTemporaria();
        $this->limpar[] = $saida;

        $r = (new Empacotador())->empacotar($origem, $saida, null);

        self::assertNull($r['assinatura']);
        self::assertFileDoesNotExist($r['manifesto'] . '.sig');
        $z = new \ZipArchive();
        $z->open($r['zip']);
        self::assertNotFalse($z->locateName('LICENSE'));
        self::assertNotFalse($z->locateName('NOTICE'), 'o pacote leva o NOTICE');
        $z->close();

        $chave = $saida . '/uso.privada';
        file_put_contents($chave, $this->uso['privada'] . "\n");
        chmod($chave, 0600);
        $t = new CommandTester(new AssinarComando());
        $t->execute(['arquivo' => $r['manifesto'], '--chave-privada' => $chave]);

        self::assertSame(0, $t->getStatusCode());
        self::assertStringContainsString($this->uso['publica'], $t->getDisplay());
        self::assertStringNotContainsString($this->uso['privada'], $t->getDisplay(), 'a chave privada nunca é impressa');
        self::assertTrue(Assinatura::verificar((string) file_get_contents($r['manifesto']), (string) file_get_contents($r['manifesto'] . '.sig'), [$this->uso['publica']]));
    }

    public function testAssinarRecusaChaveInvalidaEArquivoInexistente(): void
    {
        $pasta = Ajuda::pastaTemporaria();
        $this->limpar[] = $pasta;
        file_put_contents($pasta . '/m.json', '{}');
        file_put_contents($pasta . '/ruim', 'não é chave');
        $t = new CommandTester(new AssinarComando());

        $t->execute(['arquivo' => $pasta . '/m.json', '--chave-privada' => $pasta . '/ruim']);
        self::assertSame(1, $t->getStatusCode());
        self::assertFileDoesNotExist($pasta . '/m.json.sig');
        $t->execute(['arquivo' => $pasta . '/nao-existe', '--chave-privada' => $pasta . '/ruim']);
        self::assertSame(1, $t->getStatusCode());
    }

    public function testGerarChavesGravaArquivosComPermissaoRestritaESemMostrarAPrivada(): void
    {
        $pasta = Ajuda::pastaTemporaria();
        $this->limpar[] = $pasta;
        $t = new CommandTester(new ChavesComando());

        $t->execute(['--saida' => $pasta . '/chaves', '--nome' => 'recuperacao']);

        self::assertSame(0, $t->getStatusCode());
        $privada = trim((string) file_get_contents($pasta . '/chaves/recuperacao.privada'));
        $publica = trim((string) file_get_contents($pasta . '/chaves/recuperacao.publica'));
        self::assertSame($publica, Assinatura::publicaDe($privada));
        self::assertStringNotContainsString($privada, $t->getDisplay());
        self::assertSame('0600', substr(sprintf('%o', fileperms($pasta . '/chaves/recuperacao.privada')), -4));

        $t->execute(['--saida' => $pasta . '/chaves', '--nome' => 'recuperacao']);
        self::assertSame(1, $t->getStatusCode(), 'não sobrescreve chave existente');
    }

    public function testSemConfiancaOlhaATesteSoNoCanalDeTeste(): void
    {
        self::assertTrue(Chaves::semConfianca([], 'estavel', ['t']));
        self::assertFalse(Chaves::semConfianca(['p'], 'estavel', []));
        self::assertFalse(Chaves::semConfianca([], 'teste', ['t']), 'build só com TESTE chega ao verificador do canal de teste');
        self::assertTrue(Chaves::semConfianca(['p'], 'teste', []));
    }

    public function testEmpacotarSemAssinarRemoveSigAntigaDaSaidaReaproveitada(): void
    {
        $origem = Ajuda::raizDeProjeto();
        $this->limpar[] = $origem;
        mkdir($origem . '/app/src', 0775, true);
        mkdir($origem . '/vendor', 0775, true);
        mkdir($origem . '/bin', 0775, true);
        file_put_contents($origem . '/app/src/Versao.php', "<?php\nfinal class Versao { public const ATUAL = '0.2.0'; }\n");
        file_put_contents($origem . '/vendor/autoload.php', '<?php');
        file_put_contents($origem . '/bin/pbl', '#!/usr/bin/env php');
        $saida = Ajuda::pastaTemporaria();
        $this->limpar[] = $saida;
        file_put_contents($saida . '/latest.json.sig', "antiga\n");

        $r = (new Empacotador())->empacotar($origem, $saida, null);

        self::assertNull($r['assinatura']);
        self::assertFileDoesNotExist($saida . '/latest.json.sig');
    }
}
