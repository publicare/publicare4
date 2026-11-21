<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Atualizacao\LancadorDeProcesso;
use PHPUnit\Framework\TestCase;

/** Hospedagem com open_basedir: a descoberta do PHP de linha de comando nunca toca (nem avisa sobre) um caminho proibido. */
final class LancadorDePhpOpenBasedirTest extends TestCase
{
    /** @var list<string> */
    private array $avisos = [];

    protected function setUp(): void
    {
        $this->avisos = [];
        set_error_handler(function (int $n, string $msg): bool {
            $this->avisos[] = $msg;

            return true;
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    public function testBinarioDoFpmNaoViraCandidatoENadaEhTocadoForaDoOpenBasedir(): void
    {
        $permitida = Ajuda::pastaTemporaria();

        $r = LancadorDeProcesso::descobrirPhp('', $permitida, '/usr/sbin/php-fpm8.3', '/usr/bin');

        self::assertNull($r);
        self::assertSame([], $this->avisos);
        Ajuda::apagar($permitida);
    }

    public function testCaminhoConfiguradoForaDoOpenBasedirEIgnoradoSemAviso(): void
    {
        $permitida = Ajuda::pastaTemporaria();

        self::assertNull(LancadorDeProcesso::descobrirPhp('/opt/outro/php', $permitida, '/usr/sbin/php-fpm8.3', '/nao/existe'));
        self::assertSame([], $this->avisos);
        Ajuda::apagar($permitida);
    }

    public function testPhpDentroDoOpenBasedirEAceito(): void
    {
        $pasta = Ajuda::pastaTemporaria();
        $php = $pasta . '/php';
        copy('/bin/sh', $php);
        chmod($php, 0755);

        self::assertSame($php, LancadorDeProcesso::descobrirPhp($php, $pasta));
        self::assertSame($php, LancadorDeProcesso::descobrirPhp('', $pasta, '/usr/sbin/php-fpm8.3', $pasta));
        self::assertSame([], $this->avisos);
        Ajuda::apagar($pasta);
    }

    public function testSemRestricaoOBinarioDoFpmContinuaDescartadoPeloNome(): void
    {
        self::assertNull(LancadorDeProcesso::descobrirPhp('', '', '/usr/sbin/php-fpm8.3', '/nao/existe'));
        self::assertSame([], $this->avisos);
    }
}
