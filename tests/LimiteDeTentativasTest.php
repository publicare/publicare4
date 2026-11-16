<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\LimiteDeTentativas;
use Pbl\Banco\Conexao;
use PHPUnit\Framework\TestCase;

/** Travamento progressivo do login (S-05): por par, por login e por origem; IPv6 por /64; espaços separados. */
final class LimiteDeTentativasTest extends TestCase
{
    private string $raiz;
    private int $t = 1_800_000_000;
    private LimiteDeTentativas $limite;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $con = Conexao::sqlite($this->raiz . '/b.sqlite');
        $con->executeStatement('CREATE TABLE tentativa_login (id INTEGER PRIMARY KEY AUTOINCREMENT, chave_hash VARCHAR(64) NOT NULL, quando INTEGER NOT NULL)');
        $this->limite = new LimiteDeTentativas($con, str_repeat('s', 64), fn (): int => $this->t);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function falhar(int $vezes, string $login = 'ana', string $ip = '10.0.0.1', string $espaco = LimiteDeTentativas::ESPACO_LOGIN): void
    {
        for ($i = 0; $i < $vezes; $i++) {
            $this->limite->registrarFalha($login, $ip, $espaco);
        }
    }

    public function testAtrasoDobraACadaFalhaAMaisEtemTeto(): void
    {
        $this->falhar(LimiteDeTentativas::POR_PAR);
        self::assertSame(30, $this->limite->bloqueadoPor('ana', '10.0.0.1'));
        $this->t += 30;
        self::assertNull($this->limite->bloqueadoPor('ana', '10.0.0.1'), 'passado o atraso, pode tentar');

        $this->falhar(1);
        self::assertSame(60, $this->limite->bloqueadoPor('ana', '10.0.0.1'));
        $this->t += 60;
        $this->falhar(1);
        self::assertSame(120, $this->limite->bloqueadoPor('ana', '10.0.0.1'));
        $this->t += 120;
        $this->falhar(10);
        self::assertSame(LimiteDeTentativas::ATRASO_MAXIMO, $this->limite->bloqueadoPor('ana', '10.0.0.1'), 'o atraso tem teto');
    }

    public function testCincoFalhasDeOutrasOrigensNaoTrancamOLoginParaUmaOrigemLimpa(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->falhar(1, 'ana', "203.0.113.{$i}");
        }
        self::assertNull($this->limite->bloqueadoPor('ana', '198.51.100.9'));
    }

    public function testMuitasFalhasDeOrigensDiferentesTrancamOLoginInteiro(): void
    {
        for ($i = 1; $i <= LimiteDeTentativas::POR_LOGIN; $i++) {
            $this->falhar(1, 'ana', "203.0.113.{$i}");
        }
        self::assertNotNull($this->limite->bloqueadoPor('ana', '198.51.100.9'), 'adivinhação distribuída esbarra no teto do login');
    }

    public function testIpv6DoMesmo64DividemOContador(): void
    {
        $this->falhar(LimiteDeTentativas::POR_IP, 'x', '2001:db8:1:2::1');
        self::assertNotNull($this->limite->bloqueadoPor('outro', '2001:db8:1:2:ffff::9'));
        self::assertNull($this->limite->bloqueadoPor('outro', '2001:db8:1:3::1'), 'outro /64 não é afetado');
    }

    public function testModoElevadoTemContadoresPropriosNosDoisSentidos(): void
    {
        $this->falhar(LimiteDeTentativas::POR_PAR, 'ana', '10.0.0.1', LimiteDeTentativas::ESPACO_ELEVADO);
        self::assertNotNull($this->limite->bloqueadoPor('ana', '10.0.0.1', LimiteDeTentativas::ESPACO_ELEVADO));
        self::assertNull($this->limite->bloqueadoPor('ana', '10.0.0.1'), 'falhas no modo elevado não gastam o contador do login');

        $this->falhar(LimiteDeTentativas::POR_PAR, 'bia', '10.0.0.2');
        self::assertNull($this->limite->bloqueadoPor('bia', '10.0.0.2', LimiteDeTentativas::ESPACO_ELEVADO));
    }

    public function testLiberarLoginDestravaDeQualquerOrigemEPerdoarDesfazAFalha(): void
    {
        $this->falhar(LimiteDeTentativas::POR_PAR);
        self::assertNotNull($this->limite->bloqueadoPor('ana', '10.0.0.1'));
        $this->limite->liberarLogin('ANA');
        self::assertNull($this->limite->bloqueadoPor('ana', '10.0.0.1'));
        self::assertNull($this->limite->bloqueadoPor('ana', '10.0.0.1', LimiteDeTentativas::ESPACO_ELEVADO));
        $this->t += 1;
        $this->falhar(1);
        self::assertNull($this->limite->bloqueadoPor('ana', '10.0.0.1'), 'o contador recomeça do zero');

        $this->falhar(LimiteDeTentativas::POR_IP, 'x', '10.9.9.9');
        self::assertNotNull($this->limite->bloqueadoPor('y', '10.9.9.9'));
        $this->limite->liberarIp('10.9.9.9');
        self::assertNull($this->limite->bloqueadoPor('y', '10.9.9.9'));
    }
}
