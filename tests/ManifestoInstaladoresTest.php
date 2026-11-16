<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Atualizacao\Empacotador;
use Pbl\Atualizacao\ErroDeAtualizacao;
use Pbl\Atualizacao\Manifesto;
use PHPUnit\Framework\TestCase;

/** O manifesto passa a listar o SHA-256 de instalar.php e instalar.sh (campo opcional: manifestos antigos continuam válidos). */
final class ManifestoInstaladoresTest extends TestCase
{
    private string $origem;
    private string $saida;

    protected function setUp(): void
    {
        $this->origem = Ajuda::raizDeProjeto();
        $this->saida = Ajuda::raizDeProjeto();
        mkdir($this->origem . '/app/src', 0775, true);
        mkdir($this->origem . '/vendor', 0775, true);
        file_put_contents($this->origem . '/app/src/Versao.php', "<?php\nfinal class Versao { public const ATUAL = '0.1.0'; }\n");
        file_put_contents($this->origem . '/vendor/autoload.php', '<?php');
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->origem);
        Ajuda::apagar($this->saida);
    }

    public function testEmpacotadorGravaOsHashesDosInstaladoresNoManifesto(): void
    {
        $php = hash('sha256', 'a');
        $sh = hash('sha256', 'b');

        $r = (new Empacotador())->empacotar($this->origem, $this->saida, null, 'estavel', ['instaladores' => ['instalar.php' => $php, 'instalar.sh' => $sh]]);
        $m = Manifesto::analisar((string) file_get_contents($r['manifesto']));

        self::assertSame(['instalar.php' => $php, 'instalar.sh' => $sh], $m->instaladores);
    }

    public function testSemInstaladoresContinuaValido(): void
    {
        $r = (new Empacotador())->empacotar($this->origem, $this->saida, null);

        self::assertSame([], Manifesto::analisar((string) file_get_contents($r['manifesto']))->instaladores);
    }

    public function testInstaladorInvalidoNoManifestoEhRecusado(): void
    {
        $base = ['formato' => 1, 'canal' => 'estavel', 'versao' => '0.1.0', 'data' => '2026-11-16', 'php_minimo' => '8.2', 'atualizar_a_partir_de' => '0.0.0',
            'pacote' => ['url' => 'p.zip', 'tamanho' => 10, 'sha256' => str_repeat('a', 64)]];
        foreach ([['malware.php' => ['sha256' => str_repeat('a', 64)]], ['instalar.php' => ['sha256' => 'curto']], ['instalar.php' => 'x']] as $ruim) {
            try {
                Manifesto::analisar((string) json_encode($base + ['instaladores' => $ruim]));
                self::fail('deveria recusar');
            } catch (ErroDeAtualizacao $e) {
                self::assertStringContainsString('instalador', $e->getMessage());
            }
        }
    }
}
