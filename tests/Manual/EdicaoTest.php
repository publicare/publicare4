<?php

declare(strict_types=1);

namespace Pbl\Testes\Manual;

use Pbl\Manual\Edicao;
use PHPUnit\Framework\TestCase;

final class EdicaoTest extends TestCase
{
    public function testTagEstavelGuardaAVersaoEAtualizaARaiz(): void
    {
        $e = Edicao::para('auto', 'v1.2.3');
        self::assertSame(['1-2-3', 'estavel'], array_map(static fn (Edicao $x): string => $x->chave, $e));
        self::assertSame('/', $e[1]->prefixoDeLink());
        self::assertSame('', $e[1]->prefixoDeUrl());
        self::assertSame('/1-2-3/', $e[0]->prefixoDeLink());
        self::assertSame('1.2.3', $e[0]->rotulo, 'a versão só aparece no rótulo do seletor');
    }

    public function testTagComHifenGuardaAVersaoEAtualizaOBetaSemTocarARaiz(): void
    {
        $e = Edicao::para('auto', '1.2.3-rc.1');
        self::assertSame(['1-2-3-rc-1', 'beta'], array_map(static fn (Edicao $x): string => $x->chave, $e));
        self::assertSame('/beta/', $e[1]->prefixoDeLink());
    }

    public function testEdicaoExplicitaVale(): void
    {
        self::assertSame(['beta'], array_map(static fn (Edicao $x): string => $x->chave, Edicao::para('beta', '9.9.9')));
        self::assertSame(['estavel'], array_map(static fn (Edicao $x): string => $x->chave, Edicao::para('estavel', '9.9.9-rc.1')));
        self::assertSame(['2-0-0'], array_map(static fn (Edicao $x): string => $x->chave, Edicao::para('2.0.0', '1.0.0')));
    }

    public function testVersaoInvalidaOuReservadaEhRecusada(): void
    {
        foreach (['', '///', 'beta', 'estavel'] as $v) {
            try {
                Edicao::daVersao($v);
                self::fail("devia recusar '{$v}'");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame('manual:beta:um/dois', Edicao::beta()->origem('um/dois'));
    }
}
