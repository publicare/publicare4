<?php

declare(strict_types=1);

namespace Pbl\Testes\Banco;

use Pbl\Atualizacao\Assinatura;
use Pbl\Atualizacao\Atualizador;
use Pbl\Atualizacao\BaixadorLocal;
use Pbl\Atualizacao\Empacotador;
use Pbl\Atualizacao\Reversor;
use Pbl\Banco\CarregadorDeConfigBanco;
use Pbl\Banco\Conexao;
use Pbl\Caminhos;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use Pbl\Testes\Ajuda;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Atualizar e voltar atrás com o banco da instalação em qualquer dialeto (ADR 0028, f): a cópia de antes da atualização é um
 * instantâneo SQLite, e a volta atrás devolve o conteúdo do banco de servidor a partir dele.
 */
#[Group('contrato-dialeto')]
final class AtualizacaoEmQualquerBancoTest extends TestCase
{
    /** @var list<string> */
    private array $limpar = [];

    protected function tearDown(): void
    {
        foreach ($this->limpar as $p) {
            Ajuda::apagar($p);
        }
    }

    private function codigoDeMentira(string $raiz, string $versao): void
    {
        foreach (['app/src', 'vendor', 'bin'] as $p) {
            @mkdir("{$raiz}/{$p}", 0775, true);
        }
        file_put_contents("{$raiz}/app/src/Versao.php", "<?php\nfinal class Versao { public const ATUAL = '{$versao}'; }\n");
        file_put_contents("{$raiz}/vendor/autoload.php", '<?php');
        file_put_contents("{$raiz}/bin/pbl", '#!/usr/bin/env php');
        chmod("{$raiz}/bin/pbl", 0755);
        file_put_contents("{$raiz}/composer.json", '{"require":{"php":">=8.2"}}');
        file_put_contents("{$raiz}/composer.lock", '{}');
        file_put_contents("{$raiz}/LICENSE", 'MIT');
    }

    public function testAtualizaEVoltaAtrasDevolvendoOConteudoDoBanco(): void
    {
        $par = Assinatura::gerarPar();
        $origem = Ajuda::pastaTemporaria();
        $saida = Ajuda::pastaTemporaria();
        $raiz = Ajuda::raizDeProjeto();
        array_push($this->limpar, $origem, $saida, $raiz);
        $this->codigoDeMentira($origem, '0.2.0');
        $manifesto = (new Empacotador())->empacotar($origem, $saida, $par['privada'], 'estavel')['manifesto'];

        $caminhos = new Caminhos($raiz);
        $caminhos->garantirDados();
        $config = (new CarregadorDeConfigBanco($caminhos))->carregar();
        if (!extension_loaded('pdo_' . $config->driver)) {
            self::markTestSkipped("A extensão pdo_{$config->driver} não está carregada.");
        }
        $banco = Conexao::abrir($config);
        Ajuda::apagarTabelas($banco);
        $this->codigoDeMentira($raiz, '0.1.0');
        (new Instalador($caminhos))->instalar('Atualizar', 'atualizar.local');
        $antes = (int) $banco->fetchOne('SELECT COUNT(*) FROM objeto');

        try {
            $a = new Atualizador($caminhos, new BaixadorLocal(), [$par['publica']], '0.1.0', static function (): void {
            }, '8.3.0');
            $a->aplicar($a->verificar($manifesto), $manifesto);
            self::assertStringContainsString('0.2.0', (string) file_get_contents($raiz . '/app/src/Versao.php'));

            // conteúdo criado depois da atualização: a volta atrás o perde (como no SQLite)
            $s = Servicos::doBancoInstalado($caminhos);
            $site = $s->sites->porHost('atualizar.local') ?? self::fail();
            $s->objetos->criar($site->id, $s->classes->porNome('pagina') ?? self::fail(), $site->objetoRaizId, 'Depois da atualização', 'depois', ['resumo' => 'r', 'corpo' => '<p>c</p>']);
            self::assertSame($antes + 1, (int) $banco->fetchOne('SELECT COUNT(*) FROM objeto'));

            $r = new Reversor($caminhos, '0.2.0', static function (): void {
            });
            $av = $r->avaliar();
            self::assertTrue($av->possivel, $av->motivo);
            self::assertTrue($av->restauraBanco);
            $r->reverter('cli');

            self::assertStringContainsString('0.1.0', (string) file_get_contents($raiz . '/app/src/Versao.php'));
            self::assertSame($antes, (int) Conexao::abrir($config)->fetchOne('SELECT COUNT(*) FROM objeto'));
        } finally {
            try {
                Ajuda::apagarTabelas(Conexao::abrir($config));
            } catch (\Throwable) {
            }
        }
    }
}
