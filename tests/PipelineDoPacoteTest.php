<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Atualizacao\Assinatura;
use Pbl\Atualizacao\Atualizador;
use Pbl\Atualizacao\BaixadorHttps;
use Pbl\Atualizacao\BaixadorLocal;
use Pbl\Atualizacao\Chaves;
use Pbl\Atualizacao\ErroDeAtualizacao;
use Pbl\Caminhos;
use Pbl\Console\AssinarComando;
use Pbl\Console\EmpacotarComando;
use Pbl\Console\VerificarAssinaturaComando;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Ensaio local do pipeline do pacote (docs/releases.md): empacotar, assinar e verificar com um par de chaves **descartável** (nunca
 * as reais). Cobertura que já existe e não é repetida aqui: `ChavesDeConfiancaTest` (canal teste, revogação, troca de chave, `empacotar`
 * sem assinar + `assinar`) e `AtualizadorTest` (assinatura de outra chave, manifesto adulterado, aplicação do pacote).
 */
final class PipelineDoPacoteTest extends TestCase
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

    private function pasta(): string
    {
        $p = Ajuda::pastaTemporaria();
        $this->limpar[] = $p;

        return $p;
    }

    private function projetoPreparado(): string
    {
        $o = Ajuda::raizDeProjeto();
        $this->limpar[] = $o;
        mkdir($o . '/app/src', 0775, true);
        mkdir($o . '/vendor', 0775, true);
        mkdir($o . '/bin', 0775, true);
        file_put_contents($o . '/app/src/Versao.php', "<?php\nfinal class Versao { public const ATUAL = '0.2.0'; }\n");
        file_put_contents($o . '/vendor/autoload.php', '<?php');
        file_put_contents($o . '/bin/pbl', '#!/usr/bin/env php');
        file_put_contents($o . '/LICENSE', 'Apache');

        return $o;
    }

    private function chave(string $privada): string
    {
        $arq = $this->pasta() . '/chave.privada';
        file_put_contents($arq, $privada . "\n");
        chmod($arq, 0600);

        return $arq;
    }

    /** @param array<string, string|bool> $opcoes */
    private function empacotar(array $opcoes, string $nome = 'latest.json'): string
    {
        $saida = $this->pasta();
        $t = new CommandTester(new EmpacotarComando());
        $t->execute(['origem' => $this->projetoPreparado(), 'saida' => $saida] + $opcoes);
        self::assertSame(0, $t->getStatusCode(), $t->getDisplay());

        return $saida . '/' . $nome;
    }

    private function atualizador(): Atualizador
    {
        return new Atualizador(new Caminhos($this->pasta()), new BaixadorLocal(), [], '0.1.0', static function (): void {
        }, '8.3.0', [], [], [$this->par['publica']]);
    }

    public function testManifestoEmpacotadoEAssinadoPelaChaveDeTesteEhAceitoEOAdulteradoRecusado(): void
    {
        $manifesto = $this->empacotar(['--canal' => 'teste', '--chave-privada' => $this->chave($this->par['privada'])], 'latest-teste.json');

        self::assertTrue($this->atualizador()->verificar($manifesto, 'teste')->disponivel);
        self::assertStringContainsString('"sha256": "', (string) file_get_contents($manifesto), 'o ensaio de adulteração precisa mexer no hash');

        // adulterado depois de assinado: o hash do pacote muda, a assinatura deixa de valer
        file_put_contents($manifesto, str_replace('"sha256": "', '"sha256": "0', (string) file_get_contents($manifesto)));
        $this->expectException(ErroDeAtualizacao::class);
        $this->expectExceptionMessage('assinatura');
        $this->atualizador()->verificar($manifesto, 'teste');
    }

    public function testAsChavesEmbutidasDeProducaoNaoAceitamUmManifestoDeTeste(): void
    {
        $manifesto = $this->empacotar(['--canal' => 'teste', '--chave-privada' => $this->chave($this->par['privada'])], 'latest-teste.json');

        self::assertNull(Assinatura::quemAssinou(
            (string) file_get_contents($manifesto),
            (string) file_get_contents($manifesto . '.sig'),
            Chaves::PUBLICAS,
        ));
    }

    public function testCicloDoCiEDoDonoSemAssinarDepoisAssinarEVerificarContraAsChavesConfiaveis(): void
    {
        $manifesto = $this->empacotar(['--sem-assinar' => true, '--canal' => 'estavel']);
        self::assertFileDoesNotExist($manifesto . '.sig', 'o CI não assina');

        $t = new CommandTester(new AssinarComando());
        $t->execute(['arquivo' => $manifesto, '--chave-privada' => $this->chave($this->par['privada'])]);
        self::assertSame(0, $t->getStatusCode());

        $confere = new CommandTester(new VerificarAssinaturaComando([$this->par['publica']], [], [], []));
        $confere->execute(['arquivo' => $manifesto]);
        self::assertSame(0, $confere->getStatusCode(), $confere->getDisplay());

        file_put_contents($manifesto, (string) file_get_contents($manifesto) . "\n ");
        $confere->execute(['arquivo' => $manifesto]);
        self::assertSame(1, $confere->getStatusCode(), 'manifesto alterado depois de assinado');

        $real = new CommandTester(new VerificarAssinaturaComando());
        $real->execute(['arquivo' => $manifesto]);
        self::assertSame(1, $real->getStatusCode(), 'a chave descartável não é uma das embutidas');
    }

    public function testUrlBaseDoPacoteCaminhoAbsolutoResolveNoMesmoHostDoManifesto(): void
    {
        $manifesto = $this->empacotar(['--sem-assinar' => true, '--url-base-pacote' => '/pacotes/']);
        $m = json_decode((string) file_get_contents($manifesto), true);

        self::assertSame('/pacotes/pbl4-0.2.0.zip', $m['pacote']['url']);
        self::assertSame(
            'https://atualizacoes.exemplo.org/pacotes/pbl4-0.2.0.zip',
            (new BaixadorHttps())->resolver('https://atualizacoes.exemplo.org/latest.json', $m['pacote']['url']),
        );
    }

    public function testSemUrlBaseOManifestoMantemONomeRelativo(): void
    {
        $m = json_decode((string) file_get_contents($this->empacotar(['--sem-assinar' => true])), true);

        self::assertSame('pbl4-0.2.0.zip', $m['pacote']['url']);
    }

    /** @return iterable<string, array{string}> */
    public static function basesInvalidas(): iterable
    {
        yield 'http' => ['http://x.org/pacotes/'];
        yield 'sem barra final' => ['/pacotes'];
        yield 'subindo pastas' => ['/pacotes/../'];
        yield 'relativa' => ['pacotes/'];
        yield 'mesmo host com usuario' => ['https://u@x.org/p/'];
        yield 'protocolo relativo' => ['//x.org/'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('basesInvalidas')]
    public function testUrlBaseInvalidaEhRecusada(string $base): void
    {
        $t = new CommandTester(new EmpacotarComando());
        $t->execute(['origem' => $this->projetoPreparado(), 'saida' => $this->pasta(), '--sem-assinar' => true, '--url-base-pacote' => $base]);

        self::assertSame(1, $t->getStatusCode());
        self::assertStringContainsString('URL base do pacote inválida', $t->getDisplay());
    }
}
