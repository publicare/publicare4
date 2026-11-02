<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Atualizacao\Atualizador;
use Pbl\Atualizacao\BaixadorLocal;
use Pbl\Atualizacao\Empacotador;
use Pbl\Atualizacao\ErroDeAtualizacao;
use Pbl\Atualizacao\Manutencao;
use Pbl\Atualizacao\RegistroDaAtualizacao;
use Pbl\Atualizacao\Reversor;
use Pbl\Banco\Conexao;
use Pbl\Caminhos;
use PHPUnit\Framework\TestCase;

/** `Reversor`: volta atrás depois de uma atualização concluída, de ponta a ponta sobre pastas de projeto de mentira. */
final class ReversorTest extends TestCase
{
    /** @var list<string> */
    private array $limpar = [];
    /** @var array{publica: string, privada: string} */
    private array $par;

    protected function setUp(): void
    {
        $this->par = \Pbl\Atualizacao\Assinatura::gerarPar();
    }

    protected function tearDown(): void
    {
        foreach ($this->limpar as $p) {
            Ajuda::apagar($p);
        }
    }

    /** Projeto de mentira com uma versão, um arquivo marcador e um banco com `schema_versao`. */
    private function projeto(string $versao, string $marcador, ?int $schema = 1): string
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
        if ($schema !== null) {
            mkdir($raiz . '/dados', 0775, true);
            $c = Conexao::sqlite($raiz . '/dados/pbl4.sqlite');
            $c->executeStatement('CREATE TABLE schema_versao (versao INTEGER PRIMARY KEY)');
            $c->executeStatement('INSERT INTO schema_versao (versao) VALUES (' . $schema . ')');
            $c->executeStatement('CREATE TABLE marca (v TEXT)');
            $c->executeStatement("INSERT INTO marca VALUES ('{$marcador}')");
        }

        return $raiz;
    }

    /** @return array{0: string, 1: string} pasta de publicação e caminho do manifesto */
    private function publicar(string $versao, string $marcador = 'novo'): array
    {
        $origem = $this->projeto($versao, $marcador, null);
        $saida = Ajuda::raizDeProjeto();
        $this->limpar[] = $saida;
        $r = (new Empacotador())->empacotar($origem, $saida, $this->par['privada'], 'estavel');

        return [$saida, $r['manifesto']];
    }

    /**
     * Aplica uma atualização de verdade (como `AtualizadorTest`) e devolve a raiz já atualizada. A atualização em si NUNCA muda o
     * conteúdo do banco (só o código): para que o teste tenha algo que o `Reversor` realmente perderia ao restaurar a cópia de
     * antes, simula que alguém criou conteúdo depois da atualização (`marca` passa a valer `$paraMarcador`).
     */
    private function aplicarAtualizacao(string $deVersao, string $deMarcador, string $paraVersao, string $paraMarcador, ?int $schemaDepois = null): string
    {
        [, $manifesto] = $this->publicar($paraVersao, $paraMarcador);
        $raiz = $this->projeto($deVersao, $deMarcador);
        $conferir = $schemaDepois === null ? static function (): void {
        } : function () use ($raiz, $schemaDepois): void {
            Conexao::sqlite($raiz . '/dados/pbl4.sqlite')->executeStatement('INSERT INTO schema_versao (versao) VALUES (' . $schemaDepois . ')');
        };
        $a = new Atualizador(new Caminhos($raiz), new BaixadorLocal(), [$this->par['publica']], $deVersao, $conferir, '8.3.0');
        $a->aplicar($a->verificar($manifesto), $manifesto);
        // "conteúdo criado depois da atualização": o backup guardou $deMarcador; o banco de hoje passa a ter $paraMarcador.
        Conexao::sqlite($raiz . '/dados/pbl4.sqlite')->executeStatement("UPDATE marca SET v = '{$paraMarcador}'");

        return $raiz;
    }

    private function reversor(string $raiz, string $versaoAtual, ?\Closure $conferir = null, ?\Closure $relogio = null): Reversor
    {
        return new Reversor(new Caminhos($raiz), $versaoAtual, $conferir ?? static function (): void {
        }, $relogio);
    }

    public function testRevertFelizDevolveCodigoEBancoERegistraComoRevertida(): void
    {
        $raiz = $this->aplicarAtualizacao('0.1.0', 'codigo velho', '0.2.0', 'codigo novo');
        self::assertSame('codigo novo', file_get_contents($raiz . '/app/src/marcador.txt'), 'a atualização de fato trocou o código');

        $r = $this->reversor($raiz, '0.2.0');
        $a = $r->avaliar();
        self::assertTrue($a->possivel, $a->motivo);
        self::assertSame('0.1.0', $a->de);
        self::assertSame('0.2.0', $a->para);
        self::assertTrue($a->restauraBanco);

        $resultado = $r->reverter('cli', 42);

        self::assertSame('0.2.0', $resultado->de);
        self::assertSame('0.1.0', $resultado->para);
        self::assertSame('codigo velho', file_get_contents($raiz . '/app/src/marcador.txt'), 'código devolvido');
        self::assertSame('codigo velho', Conexao::sqlite($raiz . '/dados/pbl4.sqlite')->fetchOne('SELECT v FROM marca'), 'banco devolvido ao de antes da atualização');
        self::assertFalse(Manutencao::ativa(new Caminhos($raiz)));
        self::assertFileExists($resultado->copiaDoBancoAtual, 'cópia de segurança do banco de hoje antes de reverter');

        $registro = RegistroDaAtualizacao::ler(new Caminhos($raiz));
        self::assertNotNull($registro);
        self::assertTrue($registro['revertida']);

        // Já revertida: não dá para reverter de novo sem atualizar antes.
        $a2 = $this->reversor($raiz, '0.1.0')->avaliar();
        self::assertFalse($a2->possivel);
        self::assertStringContainsString('já foi desfeita', $a2->motivo);
    }

    public function testSemAtualizacaoConcluidaNaoDaParaReverter(): void
    {
        $raiz = $this->projeto('0.1.0', 'x');
        $a = $this->reversor($raiz, '0.1.0')->avaliar();
        self::assertFalse($a->possivel);
        self::assertStringContainsString('Não há atualização concluída', $a->motivo);

        try {
            $this->reversor($raiz, '0.1.0')->reverter('cli');
            self::fail('deveria recusar');
        } catch (ErroDeAtualizacao $e) {
            self::assertStringContainsString('Não há atualização concluída', $e->getMessage());
        }
    }

    public function testVersaoInstaladaDiferenteDaRegistradaNaoPermiteReverter(): void
    {
        $raiz = $this->aplicarAtualizacao('0.1.0', 'velho', '0.2.0', 'novo');
        // A versão instalada "hoje" não é a que o registro diz (0.2.0): outra coisa aconteceu depois.
        $a = $this->reversor($raiz, '0.3.0')->avaliar();
        self::assertFalse($a->possivel);
        self::assertStringContainsString('não é a da última atualização registrada', $a->motivo);
    }

    public function testFalhaNoMeioDaReversaoDevolveOEstadoDeHoje(): void
    {
        $raiz = $this->aplicarAtualizacao('0.1.0', 'velho', '0.2.0', 'novo');
        $r = $this->reversor($raiz, '0.2.0', static function (): void {
            throw new ErroDeAtualizacao('o código antigo não funciona');
        });

        try {
            $r->reverter('cli');
            self::fail('deveria falhar');
        } catch (ErroDeAtualizacao $e) {
            self::assertStringContainsString('estado anterior (versão 0.2.0) foi restaurado', $e->getMessage());
            self::assertStringContainsString('o código antigo não funciona', $e->getMessage());
        }
        self::assertSame('novo', file_get_contents($raiz . '/app/src/marcador.txt'), 'código de hoje continua no lugar');
        self::assertSame('novo', Conexao::sqlite($raiz . '/dados/pbl4.sqlite')->fetchOne('SELECT v FROM marca'), 'banco de hoje continua no lugar');
        self::assertFalse(Manutencao::ativa(new Caminhos($raiz)));

        $registro = RegistroDaAtualizacao::ler(new Caminhos($raiz));
        self::assertNotNull($registro);
        self::assertFalse($registro['revertida'], 'o registro não é marcado como revertido numa falha');

        // depois da falha, a trava foi solta: dá para tentar de novo com um conferir que funciona
        $resultado = $this->reversor($raiz, '0.2.0')->reverter('cli');
        self::assertSame('0.1.0', $resultado->para);
    }

    public function testDuasReversoesAoMesmoTempoSoUmaPassa(): void
    {
        $raiz = $this->aplicarAtualizacao('0.1.0', 'velho', '0.2.0', 'novo');
        $segunda = null;
        $r = $this->reversor($raiz, '0.2.0', function () use ($raiz, &$segunda): void {
            try {
                $this->reversor($raiz, '0.2.0')->reverter('cli');
            } catch (ErroDeAtualizacao $e) {
                $segunda = $e->getMessage();
            }
        });

        $r->reverter('cli');

        self::assertNotNull($segunda);
        self::assertStringContainsString('Outra atualização em andamento', $segunda);
        self::assertSame('velho', file_get_contents($raiz . '/app/src/marcador.txt'));
    }

    public function testBancoMudouDeFormaIncompativelDepoisDaAtualizacaoRecusaReverter(): void
    {
        $raiz = $this->aplicarAtualizacao('0.1.0', 'velho', '0.2.0', 'novo');
        // Depois da atualização registrada, mais uma migração roda no banco (outra tentativa, um `migrar` manual…): a cópia de
        // antes já não descreve mais este banco.
        Conexao::sqlite($raiz . '/dados/pbl4.sqlite')->executeStatement('INSERT INTO schema_versao (versao) VALUES (99)');

        $a = $this->reversor($raiz, '0.2.0')->avaliar();
        self::assertFalse($a->possivel);
        self::assertStringContainsString('banco mudou de forma incompatível', $a->motivo);
        self::assertSame('novo', file_get_contents($raiz . '/app/src/marcador.txt'), 'nada foi tocado');
    }

    public function testCopiaDoBancoAlteradaDepoisDaAtualizacaoRecusaReverter(): void
    {
        $raiz = $this->aplicarAtualizacao('0.1.0', 'velho', '0.2.0', 'novo');
        $registro = RegistroDaAtualizacao::ler(new Caminhos($raiz)) ?? self::fail();
        $backup = $raiz . '/dados/backups/' . $registro['backup'];
        file_put_contents($backup, 'adulterado');

        $a = $this->reversor($raiz, '0.2.0')->avaliar();
        self::assertFalse($a->possivel);
        self::assertStringContainsString('foi alterada depois da atualização', $a->motivo);
    }

    public function testCodigoAnteriorIncompletoRecusaReverter(): void
    {
        $raiz = $this->aplicarAtualizacao('0.1.0', 'velho', '0.2.0', 'novo');
        Ajuda::apagar($raiz . '/dados/atualizacao/anterior/app');

        $a = $this->reversor($raiz, '0.2.0')->avaliar();
        self::assertFalse($a->possivel);
        self::assertStringContainsString('incompleta', $a->motivo);
    }

    public function testSemBancoNaAtualizacaoRevertSoOCodigo(): void
    {
        [, $manifesto] = $this->publicar('0.2.0', 'novo');
        $raiz = $this->projeto('0.1.0', 'velho', null); // sem dados/pbl4.sqlite
        mkdir($raiz . '/dados', 0775, true);
        $a = new Atualizador(new Caminhos($raiz), new BaixadorLocal(), [$this->par['publica']], '0.1.0', static function (): void {
        }, '8.3.0');
        $a->aplicar($a->verificar($manifesto), $manifesto);

        $av = $this->reversor($raiz, '0.2.0')->avaliar();
        self::assertTrue($av->possivel, $av->motivo);
        self::assertFalse($av->restauraBanco);

        $r = $this->reversor($raiz, '0.2.0')->reverter('cli');
        self::assertSame('', $r->copiaDoBancoAtual);
        self::assertSame('velho', file_get_contents($raiz . '/app/src/marcador.txt'));
    }
}
