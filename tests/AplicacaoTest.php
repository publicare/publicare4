<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Aplicacao;
use Pbl\Caminhos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AplicacaoTest extends TestCase
{
    public function testRaizResponde200(): void
    {
        $resposta = (new Aplicacao(new Caminhos('/tmp')))->tratar(Request::create('/'));

        self::assertSame(200, $resposta->getStatusCode());
        self::assertStringStartsWith('PBL4 ', (string) $resposta->getContent());
    }

    public function testRotaDesconhecidaDa404(): void
    {
        $resposta = (new Aplicacao(new Caminhos('/tmp')))->tratar(Request::create('/nao-existe'));

        self::assertSame(404, $resposta->getStatusCode());
    }
}
