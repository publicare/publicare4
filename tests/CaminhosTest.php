<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Caminhos;
use PHPUnit\Framework\TestCase;

final class CaminhosTest extends TestCase
{
    public function testSeparaCodigoSubstituivelDeDados(): void
    {
        $c = new Caminhos('/srv/site/');

        self::assertSame('/srv/site/app', $c->app());
        self::assertSame('/srv/site/dados', $c->dados());
        foreach ([$c->config(), $c->cache(), $c->uploads(), $c->peles(), $c->banco()] as $caminho) {
            self::assertStringStartsWith($c->dados() . '/', $caminho);
        }
    }

    public function testGarantirDadosCriaPastasSoEmDados(): void
    {
        $raiz = sys_get_temp_dir() . '/pbl4-' . bin2hex(random_bytes(4));
        mkdir($raiz);
        $c = new Caminhos($raiz);

        $c->garantirDados();

        self::assertDirectoryExists($c->cache());
        self::assertDirectoryExists($c->uploads());
        self::assertDirectoryDoesNotExist($c->app());

        foreach ([$c->config(), $c->cache(), $c->uploads(), $c->peles(), $c->dados(), $raiz] as $p) {
            @rmdir($p);
        }
    }
}
